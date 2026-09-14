"""AutoRepair central metering pilot. Python 3.11+, standard library only.
Not a production billing or payment entitlement system. Bind loopback behind TLS.
"""
import argparse, contextlib, hashlib, hmac, json, os, re, secrets, sqlite3, urllib.error, urllib.parse, urllib.request
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

class Invalid(Exception): pass
class Conflict(Exception): pass
class Unauthorized(Exception): pass
class StripeError(Exception): pass

def utc(): return datetime.now(timezone.utc).isoformat()
def digest(x): return hashlib.sha256(x.encode()).hexdigest()

def stripe_config():
    key=os.environ.get('STRIPE_SECRET_KEY','')
    price=os.environ.get('STRIPE_PRICE_ID','')
    webhook=os.environ.get('STRIPE_WEBHOOK_SECRET','')
    return {'configured':bool(key.startswith('sk_test_') and price.startswith('price_') and webhook.startswith('whsec_')),
            'key':key,'price':price,'webhook':webhook}

def stripe_request(method,path,data=None):
    cfg=stripe_config()
    if not cfg['key'].startswith('sk_test_'): raise StripeError('Stripe test secret key is not configured')
    body=None
    headers={'Authorization':'Bearer '+cfg['key'],'User-Agent':'AutoRepair-Central/stripe-test'}
    if data is not None:
        body=urllib.parse.urlencode(data).encode()
        headers['Content-Type']='application/x-www-form-urlencoded'
    request=urllib.request.Request('https://api.stripe.com/v1'+path,data=body,headers=headers,method=method)
    try:
        with urllib.request.urlopen(request,timeout=20) as response:
            decoded=json.loads(response.read())
            if not isinstance(decoded,dict): raise StripeError('invalid Stripe response')
            return decoded
    except (urllib.error.URLError, urllib.error.HTTPError, ValueError):
        raise StripeError('Stripe request failed')

def verify_stripe_signature(raw,header,secret):
    if not isinstance(raw,bytes) or not isinstance(header,str) or not secret.startswith('whsec_'): return False
    parts={}
    for part in header.split(','):
        key,sep,value=part.partition('=')
        if sep: parts.setdefault(key,[]).append(value)
    try: stamp=int(parts['t'][0])
    except (KeyError,ValueError): return False
    if abs(datetime.now(timezone.utc).timestamp()-stamp)>300: return False
    signed=str(stamp).encode()+b'.'+raw
    expected=hmac.new(secret.encode(),signed,hashlib.sha256).hexdigest()
    return any(hmac.compare_digest(expected,value) for value in parts.get('v1',[]))

class Store:
    def __init__(self, path):
        self.path=path
        with self.db() as c:
            c.executescript('''
            CREATE TABLE IF NOT EXISTS accounts(id TEXT PRIMARY KEY, base INTEGER NOT NULL, unit INTEGER NOT NULL, created TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS hubs(id TEXT PRIMARY KEY, account TEXT NOT NULL REFERENCES accounts(id), token_hash TEXT UNIQUE NOT NULL, sequence INTEGER NOT NULL DEFAULT 0, payload_hash TEXT NOT NULL DEFAULT '', updated TEXT NOT NULL DEFAULT '');
            CREATE TABLE IF NOT EXISTS sites(hub TEXT NOT NULL REFERENCES hubs(id), site TEXT NOT NULL, PRIMARY KEY(hub,site));
            CREATE TABLE IF NOT EXISTS months(account TEXT NOT NULL, month TEXT NOT NULL, peak INTEGER NOT NULL, base INTEGER NOT NULL, unit INTEGER NOT NULL, PRIMARY KEY(account,month));
            CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, hub TEXT NOT NULL, sequence INTEGER NOT NULL, digest TEXT NOT NULL, received TEXT NOT NULL, count INTEGER NOT NULL, UNIQUE(hub,sequence));
            CREATE TABLE IF NOT EXISTS stripe_accounts(account TEXT PRIMARY KEY REFERENCES accounts(id), customer_id TEXT NOT NULL DEFAULT '', subscription_id TEXT NOT NULL DEFAULT '', item_id TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', updated TEXT NOT NULL DEFAULT '');
            CREATE TABLE IF NOT EXISTS stripe_cancellations(account TEXT PRIMARY KEY REFERENCES accounts(id), cancel_at_period_end INTEGER NOT NULL DEFAULT 0, current_period_end INTEGER NOT NULL DEFAULT 0);
            ''')
    @contextlib.contextmanager
    def db(self):
        c=sqlite3.connect(self.path,timeout=15,isolation_level=None);c.row_factory=sqlite3.Row
        c.execute('PRAGMA foreign_keys=ON')
        try:
            c.execute('BEGIN IMMEDIATE');yield c;c.commit()
        except BaseException: c.rollback();raise
        finally:c.close()
    def provision(self,account,hub,base=10000,unit=100,now=None):
        if not all(re.fullmatch(r'[a-z0-9_-]{1,64}',x) for x in (account,hub)):raise Invalid('invalid account or hub ID')
        if type(base) is not int or type(unit) is not int or min(base,unit)<0:raise Invalid('invalid pricing')
        token=secrets.token_hex(32)
        with self.db() as c:
            c.execute('INSERT OR IGNORE INTO accounts VALUES(?,?,?,?)',(account,base,unit,now or utc()))
            price=c.execute('SELECT base,unit FROM accounts WHERE id=?',(account,)).fetchone()
            if tuple(price)!=(base,unit):raise Conflict('existing account pricing differs')
            c.execute('INSERT INTO hubs(id,account,token_hash) VALUES(?,?,?)',(hub,account,digest(token)))
        return token
    def auth(self,c,token):
        if not isinstance(token,str) or not re.fullmatch('[a-f0-9]{64}',token):raise Unauthorized()
        h=c.execute('SELECT * FROM hubs WHERE token_hash=?',(digest(token),)).fetchone()
        if not h:raise Unauthorized()
        return h
    def count(self,c,account):
        return c.execute('SELECT COUNT(DISTINCT s.site) FROM sites s JOIN hubs h ON h.id=s.hub WHERE h.account=?',(account,)).fetchone()[0]
    def observe(self,c,account,now):
        month=now[:7];n=self.count(c,account)
        # Only observed periods are materialized; no invented historical usage.
        a=c.execute('SELECT * FROM accounts WHERE id=?',(account,)).fetchone()
        c.execute('INSERT OR IGNORE INTO months VALUES(?,?,?,?,?)',(account,month,n,a['base'],a['unit']))
        c.execute('UPDATE months SET peak=MAX(peak,?) WHERE account=? AND month=?',(n,account,month))
    def result(self,c,h,now):
        self.observe(c,h['account'],now)
        m=c.execute('SELECT * FROM months WHERE account=? AND month=?',(h['account'],now[:7])).fetchone()
        stale=c.execute("SELECT COUNT(*) FROM hubs WHERE account=? AND (updated='' OR updated<?)",(h['account'],datetime.fromtimestamp(datetime.fromisoformat(now).timestamp()-7200,timezone.utc).isoformat())).fetchone()[0]
        return dict(mode='pilot',month=now[:7],current=self.count(c,h['account']),peak=m['peak'],base_yen=m['base'],unit_yen=m['unit'],estimate_yen=m['base']+m['peak']*m['unit'],currency='jpy',stale_hubs=stale,sequence=h['sequence'],observed_at=now,billable=False)
    def status(self,token,now=None):
        now=now or utc()
        with self.db() as c:
            h=self.auth(c,token);return self.result(c,h,now)
    def snapshot(self,token,data,now=None):
        now=now or utc()
        if not isinstance(data,dict) or set(data)!={'sequence','sites'}:raise Invalid('expected sequence and sites only')
        seq=data['sequence'];sites=data['sites']
        if type(seq) is not int or not 1<=seq<=9007199254740991:raise Invalid('invalid sequence')
        if not isinstance(sites,list) or len(sites)>100000 or any(not isinstance(s,str) or not re.fullmatch('[a-f0-9]{64}',s) for s in sites):raise Invalid('invalid sites')
        if len(set(sites))!=len(sites):raise Invalid('duplicate site ID')
        body=digest(json.dumps(sorted(sites),separators=(',',':')))
        with self.db() as c:
            h=self.auth(c,token)
            if seq<h['sequence'] or (seq==h['sequence'] and body!=h['payload_hash']):raise Conflict('stale or conflicting sequence')
            if seq==h['sequence']:return self.result(c,h,now)
            # Count existing registrations before removals at a month boundary.
            self.observe(c,h['account'],now)
            c.execute('DELETE FROM sites WHERE hub=?',(h['id'],))
            c.executemany('INSERT INTO sites VALUES(?,?)',((h['id'],s) for s in sites))
            c.execute('UPDATE hubs SET sequence=?,payload_hash=?,updated=? WHERE id=?',(seq,body,now,h['id']))
            c.execute('INSERT INTO events(hub,sequence,digest,received,count) VALUES(?,?,?,?,?)',(h['id'],seq,body,now,len(sites)))
            h=c.execute('SELECT * FROM hubs WHERE id=?',(h['id'],)).fetchone()
            return self.result(c,h,now)
    def stripe_save(self,account,customer='',subscription='',item='',status='',cancel_at_period_end=None,current_period_end=None,now=None):
        if not re.fullmatch(r'[a-z0-9_-]{1,64}',str(account)): raise Invalid('invalid account ID')
        now=now or utc()
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone(): raise Invalid('unknown account')
            old=c.execute('SELECT * FROM stripe_accounts WHERE account=?',(account,)).fetchone()
            customer=customer or (old['customer_id'] if old else '')
            subscription=subscription or (old['subscription_id'] if old else '')
            item=item or (old['item_id'] if old else '')
            status=status or (old['status'] if old else '')
            c.execute('INSERT INTO stripe_accounts(account,customer_id,subscription_id,item_id,status,updated) VALUES(?,?,?,?,?,?) ON CONFLICT(account) DO UPDATE SET customer_id=excluded.customer_id,subscription_id=excluded.subscription_id,item_id=excluded.item_id,status=excluded.status,updated=excluded.updated',(account,customer,subscription,item,status,now))
            if cancel_at_period_end is not None or current_period_end is not None:
                old_cancel=c.execute('SELECT * FROM stripe_cancellations WHERE account=?',(account,)).fetchone()
                cancel_at_period_end=int(bool(cancel_at_period_end)) if cancel_at_period_end is not None else (old_cancel['cancel_at_period_end'] if old_cancel else 0)
                current_period_end=current_period_end if type(current_period_end) is int and current_period_end>=0 else (old_cancel['current_period_end'] if old_cancel else 0)
                c.execute('INSERT INTO stripe_cancellations(account,cancel_at_period_end,current_period_end) VALUES(?,?,?) ON CONFLICT(account) DO UPDATE SET cancel_at_period_end=excluded.cancel_at_period_end,current_period_end=excluded.current_period_end',(account,cancel_at_period_end,current_period_end))
            row=c.execute('SELECT s.account,s.customer_id,s.subscription_id,s.item_id,s.status,s.updated,COALESCE(x.cancel_at_period_end,0) AS cancel_at_period_end,COALESCE(x.current_period_end,0) AS current_period_end FROM stripe_accounts s LEFT JOIN stripe_cancellations x ON x.account=s.account WHERE s.account=?',(account,)).fetchone()
            return dict(row)

    def stripe_state(self,account):
        with self.db() as c:
            row=c.execute('SELECT s.account,s.customer_id,s.subscription_id,s.item_id,s.status,s.updated,COALESCE(x.cancel_at_period_end,0) AS cancel_at_period_end,COALESCE(x.current_period_end,0) AS current_period_end FROM stripe_accounts s LEFT JOIN stripe_cancellations x ON x.account=s.account WHERE s.account=?',(account,)).fetchone()
            return dict(row) if row else {'account':account,'customer_id':'','subscription_id':'','item_id':'','status':'','updated':'','cancel_at_period_end':0,'current_period_end':0}

    def stripe_peak(self,account,now=None):
        now=now or utc()
        with self.db() as c:
            row=c.execute('SELECT peak FROM months WHERE account=? AND month=?',(account,now[:7])).fetchone()
            if not row: raise Invalid('no observed usage for period')
            return int(row['peak'])

    def export(self,account,month):
        if not re.fullmatch(r'\d{4}-(0[1-9]|1[0-2])',month):raise Invalid('invalid month')
        with self.db() as c:
            row=c.execute('SELECT * FROM months WHERE account=? AND month=?',(account,month)).fetchone()
            if not row:raise Invalid('no observations for period')
            r=dict(row);r.update(mode='pilot',currency='jpy',amount_yen=r['base']+r['peak']*r['unit'],billable=False)
            return r

def handler(store):
    class Handler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup();self.connection.settimeout(10)
        def log_message(self,*args):pass # Do not log authorization, payloads or query strings.
        def reply(self,code,data):
            body=json.dumps(data,separators=(',',':')).encode();self.send_response(code)
            self.send_header('Content-Type','application/json');self.send_header('Cache-Control','no-store');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        def admin_allowed(self):
            admin=os.environ.get('ADMIN_TOKEN','')
            given=self.headers.get('X-Admin-Token','')
            if not admin or not hmac.compare_digest(given,admin): raise Unauthorized()

        def body(self,limit=4000):
            size=int(self.headers.get('Content-Length','0'))
            if not 0<size<=limit: raise Invalid('payload size')
            data=json.loads(self.rfile.read(size))
            if not isinstance(data,dict): raise Invalid('expected an object')
            return data

        def stripe_checkout(self,body):
            self.admin_allowed();cfg=stripe_config()
            if not cfg['configured']: raise StripeError('Stripe test configuration is incomplete')
            account=body.get('account','');success=body.get('success_url','');cancel=body.get('cancel_url','')
            if not re.fullmatch(r'[a-z0-9_-]{1,64}',str(account)): raise Invalid('invalid account')
            for url in (success,cancel):
                p=urllib.parse.urlparse(url)
                if p.scheme!='https' or not p.netloc or p.username or p.password or p.fragment: raise Invalid('invalid redirect URL')
            customer=stripe_request('POST','/customers',{'metadata[account]':account})
            session=stripe_request('POST','/checkout/sessions',{'mode':'subscription','customer':customer['id'],'success_url':success,'cancel_url':cancel,'line_items[0][price]':cfg['price'],'line_items[0][quantity]':'1','metadata[account]':account,'subscription_data[metadata][account]':account})
            self.reply(200,{'mode':'test','checkout_url':session.get('url','')})

        def stripe_hub_checkout(self,token,body):
            """Create a test Checkout session for the authenticated hub's account.

            This route intentionally does not accept an account ID.  A hub token can
            only create a session for the account to which that hub was provisioned.
            """
            success=body.get('success_url','');cancel=body.get('cancel_url','')
            for url in (success,cancel):
                p=urllib.parse.urlparse(url)
                if p.scheme!='https' or not p.netloc or p.username or p.password or p.fragment: raise Invalid('invalid redirect URL')
            with store.db() as c:
                hub=store.auth(c,token);account=hub['account']
            cfg=stripe_config()
            if not cfg['configured']: raise StripeError('Stripe test configuration is incomplete')
            state=store.stripe_state(account)
            if state['status'] in ('active','trialing','past_due','unpaid'):
                raise Conflict('account already has a Stripe test subscription')
            customer=stripe_request('POST','/customers',{'metadata[account]':account})
            session=stripe_request('POST','/checkout/sessions',{'mode':'subscription','customer':customer['id'],'success_url':success,'cancel_url':cancel,'line_items[0][price]':cfg['price'],'line_items[0][quantity]':'1','metadata[account]':account,'subscription_data[metadata][account]':account})
            checkout_url=session.get('url','')
            if not isinstance(checkout_url,str) or not checkout_url.startswith('https://checkout.stripe.com/'):
                raise StripeError('Stripe returned an invalid Checkout URL')
            self.reply(200,{'mode':'test','checkout_url':checkout_url})

        def stripe_hub_status(self,token):
            """Return only the authenticated hub account's non-secret test status."""
            with store.db() as c:
                hub=store.auth(c,token);account=hub['account']
            state=store.stripe_state(account)
            # Webhook deliveries can arrive out of order.  Ask Stripe for the
            # authoritative subscription state before reporting it to a hub.
            if state['subscription_id']:
                subscription=stripe_request('GET','/subscriptions/'+urllib.parse.quote(state['subscription_id'],safe=''))
                # Stripe can represent an end-of-period cancellation with either
                # cancel_at_period_end/current_period_end or a concrete cancel_at.
                cancel_at=subscription.get('cancel_at')
                pending=bool(subscription.get('cancel_at_period_end')) or (type(cancel_at) is int and cancel_at>0)
                period=subscription.get('current_period_end')
                if type(period) is not int or period<0:
                    period=cancel_at if type(cancel_at) is int and cancel_at>=0 else 0
                state=store.stripe_save(
                    account,
                    customer=subscription.get('customer',''),
                    subscription=subscription.get('id',''),
                    status=subscription.get('status',''),
                    cancel_at_period_end=pending,
                    current_period_end=period,
                )
            pending=bool(state['cancel_at_period_end'] and state['status'] in ('active','trialing','past_due','unpaid'))
            self.reply(200,{'mode':'test','subscription_status':state['status'],'updated':state['updated'],'cancellation_pending':pending,'cancellation_at':state['current_period_end'] if pending else 0})

        def stripe_hub_portal(self,token,body):
            success=body.get('return_url','')
            p=urllib.parse.urlparse(success)
            if p.scheme!='https' or not p.netloc or p.username or p.password or p.fragment: raise Invalid('invalid return URL')
            with store.db() as c:
                hub=store.auth(c,token);account=hub['account']
            cfg=stripe_config()
            if not cfg['configured']: raise StripeError('Stripe test configuration is incomplete')
            state=store.stripe_state(account)
            if not state['customer_id'].startswith('cus_'): raise Invalid('no Stripe test customer for account')
            session=stripe_request('POST','/billing_portal/sessions',{'customer':state['customer_id'],'return_url':success})
            portal_url=session.get('url','')
            portal=urllib.parse.urlparse(portal_url)
            if not isinstance(portal_url,str) or portal.scheme!='https' or portal.hostname!='billing.stripe.com': raise StripeError('Stripe returned an invalid portal URL')
            self.reply(200,{'mode':'test','portal_url':portal_url})

        def stripe_sync(self,body):
            self.admin_allowed();cfg=stripe_config()
            if not cfg['configured']: raise StripeError('Stripe test configuration is incomplete')
            account=body.get('account','');state=store.stripe_state(account)
            if not state['subscription_id']: raise Invalid('no Stripe subscription for account')
            peak=store.stripe_peak(account);subscription=stripe_request('GET','/subscriptions/'+urllib.parse.quote(state['subscription_id'],safe=''))
            items=((subscription.get('items') or {}).get('data') or []);item=next((x for x in items if ((x.get('price') or {}).get('id')==cfg['price'])),None)
            if not item: raise StripeError('subscription does not contain configured price')
            updated=stripe_request('POST','/subscription_items/'+urllib.parse.quote(item['id'],safe=''),{'quantity':str(peak),'proration_behavior':'none'})
            store.stripe_save(account,customer=subscription.get('customer',''),subscription=subscription.get('id',''),item=updated.get('id',''),status=subscription.get('status',''))
            self.reply(200,{'mode':'test','account':account,'peak':peak,'quantity':int(updated.get('quantity',0)),'subscription_status':subscription.get('status','')})

        def stripe_webhook(self):
            cfg=stripe_config();size=int(self.headers.get('Content-Length','0'))
            if not 0<size<=1000000: raise Invalid('payload size')
            raw=self.rfile.read(size)
            if not verify_stripe_signature(raw,self.headers.get('Stripe-Signature',''),cfg['webhook']): raise Unauthorized()
            event=json.loads(raw);obj=((event.get('data') or {}).get('object') or {});metadata=obj.get('metadata') or {};account=metadata.get('account','');etype=event.get('type','')
            if account and re.fullmatch(r'[a-z0-9_-]{1,64}',str(account)):
                subscription=obj.get('subscription','') if etype=='checkout.session.completed' else obj.get('id','')
                cancel=obj.get('cancel_at_period_end') if etype.startswith('customer.subscription.') else None
                period=obj.get('current_period_end') if etype.startswith('customer.subscription.') else None
                store.stripe_save(account,customer=obj.get('customer',''),subscription=subscription,status=obj.get('status','active' if etype=='checkout.session.completed' else ''),cancel_at_period_end=cancel,current_period_end=period)
            self.reply(200,{'received':True})

        def dispatch(self):
            try:
                auth=self.headers.get('Authorization','');token=auth[7:] if auth.startswith('Bearer ') else ''
                if self.command=='GET' and self.path=='/v1/usage': r=store.status(token)
                elif self.command=='POST' and self.path=='/v1/snapshot':
                    # Authenticate before reading a potentially large request.
                    with store.db() as c:store.auth(c,token)
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=8000000:raise Invalid('payload size')
                    r=store.snapshot(token,json.loads(self.rfile.read(size)))
                elif self.command=='POST' and self.path=='/v1/admin/provision':
                    self.admin_allowed();body=self.body()
                    r={'hub_token':store.provision(body.get('account'),body.get('hub'),int(body.get('base',10000)),int(body.get('unit',100))),'mode':'pilot'}
                elif self.command=='POST' and self.path=='/v1/admin/stripe/checkout':
                    return self.stripe_checkout(self.body())
                elif self.command=='POST' and self.path=='/v1/stripe/test-checkout':
                    return self.stripe_hub_checkout(token,self.body())
                elif self.command=='GET' and self.path=='/v1/stripe/test-status':
                    return self.stripe_hub_status(token)
                elif self.command=='POST' and self.path=='/v1/stripe/test-portal':
                    return self.stripe_hub_portal(token,self.body())
                elif self.command=='POST' and self.path=='/v1/admin/stripe/sync':
                    return self.stripe_sync(self.body())
                elif self.command=='POST' and self.path=='/v1/stripe/webhook':
                    return self.stripe_webhook()
                elif self.command=='GET' and self.path=='/v1/admin/stripe/status':
                    self.admin_allowed();cfg=stripe_config();return self.reply(200,{'mode':'test','configured':cfg['configured'],'price_configured':cfg['price'].startswith('price_')})
                else:return self.reply(404,{'error':'not_found'})
                self.reply(200,r)
            except Unauthorized:self.reply(401,{'error':'unauthorized'})
            except Conflict:self.reply(409,{'error':'sequence_conflict'})
            except StripeError:self.reply(503,{'error':'stripe_unavailable'})
            except (Invalid,ValueError,UnicodeError):self.reply(400,{'error':'invalid_request'})
            except Exception:self.reply(503,{'error':'temporarily_unavailable'})
        def do_GET(self):self.dispatch()
        def do_POST(self):self.dispatch()
    return Handler

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--db',default=os.environ.get('AAIHB_DB','usage.sqlite3'));sub=p.add_subparsers(dest='command',required=True)
    s=sub.add_parser('serve')
    # Loopback stays the default; a PaaS container needs 0.0.0.0 to accept the
    # platform's own TLS-terminating edge proxy, so that must be opted into.
    s.add_argument('--host',default=os.environ.get('AAIHB_HOST','127.0.0.1'))
    s.add_argument('--port',type=int,default=int(os.environ.get('PORT',os.environ.get('AAIHB_PORT','8787'))))
    s=sub.add_parser('provision');s.add_argument('account');s.add_argument('hub');s.add_argument('--base',type=int,default=10000);s.add_argument('--unit',type=int,default=100)
    s=sub.add_parser('export');s.add_argument('account');s.add_argument('month')
    a=p.parse_args();os.umask(0o077);store=Store(a.db)
    if a.command=='provision':print(json.dumps({'hub_token':store.provision(a.account,a.hub,a.base,a.unit),'mode':'pilot'}))
    elif a.command=='export':print(json.dumps(store.export(a.account,a.month)))
    else:HTTPServer((a.host,a.port),handler(store)).serve_forever()
