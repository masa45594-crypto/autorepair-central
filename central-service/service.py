"""AutoRepair central metering pilot. Python 3.11+, standard library only.
Not a production billing or payment entitlement system. Bind loopback behind TLS.
"""
import argparse, contextlib, hashlib, hmac, json, os, re, secrets, sqlite3, sys, threading, time
import urllib.error, urllib.request
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs
from stripe_draft import verify_webhook, create_checkout_session, create_portal_session, record_meter_event, update_subscription_item_quantity
import mailer

class Invalid(Exception): pass
class Conflict(Exception): pass
class Unauthorized(Exception): pass

SIGNUP_PAGE_HTML="""<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>AutoRepair AI Hosting お申し込み(テスト)</title></head>
<body style="font-family:sans-serif;max-width:480px;margin:80px auto;text-align:center">
<h1>AutoRepair AI Hosting</h1>
<p>これはテストモードのお申し込み画面です。実際の課金は発生しません。</p>
<p><input id="email" type="email" placeholder="メールアドレス(任意)" style="width:100%;padding:8px;box-sizing:border-box;margin-bottom:12px"></p>
<button id="go" style="padding:10px 24px;font-size:16px">お申し込みへ進む</button>
<p id="err" style="color:#c00"></p>
<script>
document.getElementById('go').addEventListener('click',async function(){
  var btn=this;btn.disabled=true;document.getElementById('err').textContent='';
  try{
    var email=document.getElementById('email').value;
    var res=await fetch('/v1/signup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(email?{email:email}:{})});
    var data=await res.json();
    if(!res.ok||!data.url)throw new Error('failed');
    location.href=data.url;
  }catch(e){
    document.getElementById('err').textContent='お申し込みを開始できませんでした。しばらくしてから再度お試しください。';
    btn.disabled=false;
  }
});
</script>
</body></html>
"""

def utc(): return datetime.now(timezone.utc).isoformat()
def digest(x): return hashlib.sha256(x.encode()).hexdigest()
def period_start(now,anchor_day):
    # Billing period anchored to the account's contract day (1-28, to stay valid in every month).
    dt=datetime.fromisoformat(now)
    y,m=(dt.year,dt.month) if dt.day>=anchor_day else ((dt.year,dt.month-1) if dt.month>1 else (dt.year-1,12))
    return f'{y:04d}-{m:02d}-{anchor_day:02d}'

class Store:
    def __init__(self, path):
        self.path=path
        with self.db() as c:
            c.executescript('''
            CREATE TABLE IF NOT EXISTS accounts(id TEXT PRIMARY KEY, base INTEGER NOT NULL, unit INTEGER NOT NULL, anchor_day INTEGER NOT NULL DEFAULT 1, suspended TEXT NOT NULL DEFAULT '', suspend_reason TEXT NOT NULL DEFAULT '', spending_cap INTEGER NOT NULL DEFAULT 0, stripe_subscription_id TEXT NOT NULL DEFAULT '', stripe_subscription_item_id TEXT NOT NULL DEFAULT '', stripe_customer_id TEXT NOT NULL DEFAULT '', created TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS hubs(id TEXT PRIMARY KEY, account TEXT NOT NULL REFERENCES accounts(id), token_hash TEXT UNIQUE NOT NULL, sequence INTEGER NOT NULL DEFAULT 0, payload_hash TEXT NOT NULL DEFAULT '', updated TEXT NOT NULL DEFAULT '', revoked TEXT NOT NULL DEFAULT '');
            CREATE TABLE IF NOT EXISTS sites(hub TEXT NOT NULL REFERENCES hubs(id), site TEXT NOT NULL, PRIMARY KEY(hub,site));
            CREATE TABLE IF NOT EXISTS months(account TEXT NOT NULL, month TEXT NOT NULL, peak INTEGER NOT NULL, base INTEGER NOT NULL, unit INTEGER NOT NULL, PRIMARY KEY(account,month));
            CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, hub TEXT NOT NULL, sequence INTEGER NOT NULL, digest TEXT NOT NULL, received TEXT NOT NULL, count INTEGER NOT NULL, UNIQUE(hub,sequence));
            CREATE TABLE IF NOT EXISTS stripe_events(id TEXT PRIMARY KEY, type TEXT NOT NULL, received TEXT NOT NULL, payload TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS payments(account TEXT NOT NULL, month TEXT NOT NULL, status TEXT NOT NULL, amount INTEGER, recorded_at TEXT NOT NULL, PRIMARY KEY(account,month));
            CREATE TABLE IF NOT EXISTS management_tokens(account TEXT PRIMARY KEY REFERENCES accounts(id), token_hash TEXT UNIQUE NOT NULL, stripe_customer TEXT NOT NULL, created TEXT NOT NULL);
            ''')
            # Migrations for databases created before these columns existed.
            try: c.execute('ALTER TABLE accounts ADD COLUMN anchor_day INTEGER NOT NULL DEFAULT 1')
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE hubs ADD COLUMN revoked TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN suspended TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN suspend_reason TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute('ALTER TABLE accounts ADD COLUMN spending_cap INTEGER NOT NULL DEFAULT 0')
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN stripe_subscription_id TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN stripe_subscription_item_id TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN stripe_customer_id TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN last_webhook_type TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
            try: c.execute("ALTER TABLE accounts ADD COLUMN last_webhook_at TEXT NOT NULL DEFAULT ''")
            except sqlite3.OperationalError: pass
    @contextlib.contextmanager
    def db(self):
        c=sqlite3.connect(self.path,timeout=15,isolation_level=None);c.row_factory=sqlite3.Row
        # WAL survives a process crash mid-write without corrupting the file (unlike the
        # default rollback journal on some filesystems); NORMAL is the safe pairing for WAL.
        c.execute('PRAGMA journal_mode=WAL');c.execute('PRAGMA synchronous=NORMAL')
        c.execute('PRAGMA foreign_keys=ON')
        try:
            c.execute('BEGIN IMMEDIATE');yield c;c.commit()
        except BaseException: c.rollback();raise
        finally:c.close()
    def backup(self,target):
        # Uses SQLite's own online backup API so this is safe to run against a live server
        # (unlike copying the file directly, which can capture a half-written page).
        src=sqlite3.connect(self.path)
        try:
            dest=sqlite3.connect(target)
            try: src.backup(dest)
            finally: dest.close()
        finally: src.close()
    def check(self):
        with self.db() as c:
            return c.execute('PRAGMA integrity_check').fetchone()[0]
    def record_stripe_event(self,event,now=None):
        """Durably record an already-verified Stripe webhook event, once. Returns False for a
        duplicate delivery (Stripe retries at-least-once) so callers can skip reprocessing.
        Recording is all this does for now; no event type triggers any account action yet."""
        eid=event.get('id');etype=event.get('type')
        if not isinstance(eid,str) or not eid or not isinstance(etype,str) or not etype:raise Invalid('event missing id/type')
        with self.db() as c:
            cur=c.execute('INSERT OR IGNORE INTO stripe_events(id,type,received,payload) VALUES(?,?,?,?)',(eid,etype,now or utc(),json.dumps(event)))
            return cur.rowcount==1
    def record_payment(self,account,month,status,amount,now=None):
        # Recording only: no automatic reaction (e.g. auto-suspend on failure) is wired up.
        # Refund rules and any failure-triggered action are deliberately still manual.
        if status not in ('paid','failed'):raise Invalid('invalid payment status')
        with self.db() as c:
            c.execute('''INSERT INTO payments(account,month,status,amount,recorded_at) VALUES(?,?,?,?,?)
                         ON CONFLICT(account,month) DO UPDATE SET status=excluded.status,amount=excluded.amount,recorded_at=excluded.recorded_at''',
                      (account,month,status,amount,now or utc()))
    def payment_status(self,account,month):
        with self.db() as c:
            row=c.execute('SELECT * FROM payments WHERE account=? AND month=?',(account,month)).fetchone()
            return dict(row) if row else None
    def issue_management_token(self,account,stripe_customer,now=None):
        # One live token per account (re-issuing replaces the previous one, so an old link
        # a customer forwarded or lost stops working once a new one is issued).
        token=secrets.token_hex(32)
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone():raise Invalid('unknown account')
            c.execute('''INSERT INTO management_tokens(account,token_hash,stripe_customer,created) VALUES(?,?,?,?)
                         ON CONFLICT(account) DO UPDATE SET token_hash=excluded.token_hash,stripe_customer=excluded.stripe_customer,created=excluded.created''',
                      (account,digest(token),stripe_customer,now or utc()))
        return token
    def auth_management(self,c,token):
        if not isinstance(token,str) or not re.fullmatch('[a-f0-9]{64}',token):raise Unauthorized()
        row=c.execute('SELECT * FROM management_tokens WHERE token_hash=?',(digest(token),)).fetchone()
        if not row:raise Unauthorized()
        return row
    def set_stripe_subscription(self,account,subscription_id,item_id,customer_id=None,now=None):
        # Recorded once at subscription creation so an existing hub's own status/portal shim
        # (see handler()) knows which customer and subscription to ask Stripe about later.
        if not re.fullmatch(r'sub_[A-Za-z0-9]+',subscription_id):raise Invalid('invalid stripe subscription id')
        if not re.fullmatch(r'si_[A-Za-z0-9]+',item_id):raise Invalid('invalid stripe subscription item id')
        if customer_id is not None and not re.fullmatch(r'cus_[A-Za-z0-9]+',customer_id):raise Invalid('invalid stripe customer id')
        with self.db() as c:
            row=c.execute('SELECT stripe_customer_id FROM accounts WHERE id=?',(account,)).fetchone()
            if not row:raise Invalid('unknown account')
            c.execute('UPDATE accounts SET stripe_subscription_id=?,stripe_subscription_item_id=?,stripe_customer_id=? WHERE id=?',
                      (subscription_id,item_id,customer_id or row['stripe_customer_id'],account))
    def set_stripe_customer(self,account,customer_id):
        """Persist the Checkout customer as soon as it exists.

        Checkout and subscription webhooks can arrive in either order.  Meter events only
        need the customer ID, so do not wait for a subscription-item webhook.
        """
        if not re.fullmatch(r'cus_[A-Za-z0-9]+',customer_id):raise Invalid('invalid stripe customer id')
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone():raise Invalid('unknown account')
            c.execute('UPDATE accounts SET stripe_customer_id=? WHERE id=?',(customer_id,account))
    def stripe_subscription_item(self,account):
        with self.db() as c:
            row=c.execute('SELECT stripe_subscription_item_id FROM accounts WHERE id=?',(account,)).fetchone()
            return row['stripe_subscription_item_id'] or None if row else None
    def record_webhook(self,account,etype,now=None):
        """Best-effort marker of 'this account has actually received a verified webhook',
        for the WordPress-side contract-check screen. A no-op (0 rows) if the account
        doesn't exist yet -- callers only invoke this once the account is guaranteed to
        exist (see handle_stripe_event)."""
        with self.db() as c:
            c.execute('UPDATE accounts SET last_webhook_type=?,last_webhook_at=? WHERE id=?',(etype,now or utc(),account))
    def webhook_state(self,account):
        with self.db() as c:
            row=c.execute('SELECT last_webhook_type,last_webhook_at FROM accounts WHERE id=?',(account,)).fetchone()
            if not row:raise Invalid('unknown account')
            return {'last_webhook_type':row['last_webhook_type'] or None,'last_webhook_at':row['last_webhook_at'] or None}
    def stripe_account_state(self,account):
        # subscription_id/customer_id on file for this account, if any -- used by the
        # existing-hub self-upgrade shim (see handler()) to re-check live Stripe status and
        # open a portal session without needing a management token or new signup.
        with self.db() as c:
            row=c.execute('SELECT stripe_subscription_id,stripe_customer_id FROM accounts WHERE id=?',(account,)).fetchone()
            if not row:raise Invalid('unknown account')
            return {'subscription_id':row['stripe_subscription_id'] or None,'customer_id':row['stripe_customer_id'] or None}
    def set_spending_cap(self,account,cap):
        # Warning-only: this never blocks usage or new site registrations (see result()).
        if type(cap) is not int or cap<0:raise Invalid('spending_cap must be a nonnegative integer (0 disables it)')
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone():raise Invalid('unknown account')
            c.execute('UPDATE accounts SET spending_cap=? WHERE id=?',(cap,account))
    def issue_additional_hub(self,account):
        # Self-service hub add for an existing, already-verified (via management token)
        # account: pricing is always inherited from the account, never chosen by the caller.
        with self.db() as c:
            acc=c.execute('SELECT base,unit,anchor_day,suspended FROM accounts WHERE id=?',(account,)).fetchone()
            if not acc:raise Invalid('unknown account')
            if acc['suspended']:raise Invalid('account is suspended; contact support to add hubs')
            # Cheap abuse cap, not a real capacity limit: nothing about this project's
            # architecture caps hubs-per-account otherwise.
            if c.execute('SELECT COUNT(*) FROM hubs WHERE account=?',(account,)).fetchone()[0]>=50:
                raise Invalid('hub limit reached for this account; contact support')
        hub='hub-'+secrets.token_hex(8)
        token=self.provision(account,hub,acc['base'],acc['unit'],acc['anchor_day'])
        return hub,token
    def provision(self,account,hub,base=10000,unit=100,anchor_day=None,now=None):
        if not all(re.fullmatch(r'[a-z0-9_-]{1,64}',x) for x in (account,hub)):raise Invalid('invalid account or hub ID')
        if type(base) is not int or type(unit) is not int or min(base,unit)<0:raise Invalid('invalid pricing')
        now=now or utc()
        # Default anchor is this account's first contract date; capped at 28 so every month has that day.
        if anchor_day is None:anchor_day=min(datetime.fromisoformat(now).day,28)
        if type(anchor_day) is not int or not 1<=anchor_day<=28:raise Invalid('anchor_day must be between 1 and 28')
        token=secrets.token_hex(32)
        with self.db() as c:
            c.execute('INSERT OR IGNORE INTO accounts(id,base,unit,anchor_day,created) VALUES(?,?,?,?,?)',(account,base,unit,anchor_day,now))
            price=c.execute('SELECT base,unit,anchor_day FROM accounts WHERE id=?',(account,)).fetchone()
            if tuple(price)!=(base,unit,anchor_day):raise Conflict('existing account pricing differs')
            c.execute('INSERT INTO hubs(id,account,token_hash) VALUES(?,?,?)',(hub,account,digest(token)))
        return token
    def auth(self,c,token):
        if not isinstance(token,str) or not re.fullmatch('[a-f0-9]{64}',token):raise Unauthorized()
        h=c.execute('SELECT * FROM hubs WHERE token_hash=?',(digest(token),)).fetchone()
        if not h or h['revoked']:raise Unauthorized()
        a=c.execute('SELECT suspended FROM accounts WHERE id=?',(h['account'],)).fetchone()
        if a['suspended']:raise Unauthorized()
        return h
    def revoke(self,hub,now=None):
        with self.db() as c:
            if not c.execute('SELECT 1 FROM hubs WHERE id=?',(hub,)).fetchone():raise Invalid('unknown hub')
            c.execute('UPDATE hubs SET revoked=? WHERE id=?',(now or utc(),hub))
    def suspend(self,account,now=None,reason='manual'):
        # Freezes an account: existing site counts are kept as-is (billing is unaffected),
        # but every hub under it is denied sync/status until unsuspend() is called.
        # reason is recorded so an automatic unsuspend (see handle_stripe_event's
        # invoice.paid handling) only ever reverses its own automatic suspend, never a
        # manual one an operator put in place for an unrelated reason (e.g. abuse).
        if not isinstance(reason,str) or not reason:raise Invalid('a suspend reason is required')
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone():raise Invalid('unknown account')
            c.execute('UPDATE accounts SET suspended=?,suspend_reason=? WHERE id=?',(now or utc(),reason,account))
    def unsuspend(self,account):
        with self.db() as c:
            if not c.execute('SELECT 1 FROM accounts WHERE id=?',(account,)).fetchone():raise Invalid('unknown account')
            c.execute("UPDATE accounts SET suspended='',suspend_reason='' WHERE id=?",(account,))
    def count(self,c,account):
        # Revoked hubs stop counting immediately; their past observed peaks are not rewritten.
        return c.execute("SELECT COUNT(DISTINCT s.site) FROM sites s JOIN hubs h ON h.id=s.hub WHERE h.account=? AND h.revoked=''",(account,)).fetchone()[0]
    def observe(self,c,account,now):
        # Only observed periods are materialized; no invented historical usage.
        a=c.execute('SELECT * FROM accounts WHERE id=?',(account,)).fetchone()
        month=period_start(now,a['anchor_day']);n=self.count(c,account)
        c.execute('INSERT OR IGNORE INTO months VALUES(?,?,?,?,?)',(account,month,n,a['base'],a['unit']))
        c.execute('UPDATE months SET peak=MAX(peak,?) WHERE account=? AND month=?',(n,account,month))
        return month
    def result(self,c,h,now):
        period=self.observe(c,h['account'],now)
        m=c.execute('SELECT * FROM months WHERE account=? AND month=?',(h['account'],period)).fetchone()
        stale=c.execute("SELECT COUNT(*) FROM hubs WHERE account=? AND revoked='' AND (updated='' OR updated<?)",(h['account'],datetime.fromtimestamp(datetime.fromisoformat(now).timestamp()-7200,timezone.utc).isoformat())).fetchone()[0]
        cap=c.execute('SELECT spending_cap FROM accounts WHERE id=?',(h['account'],)).fetchone()['spending_cap']
        estimate=m['base']+m['peak']*m['unit']
        current=self.count(c,h['account'])
        # Warning only: a cap never blocks new site registrations or usage (see README).
        return dict(mode='pilot',month=period,current=current,peak=m['peak'],base_yen=m['base'],unit_yen=m['unit'],estimate_yen=estimate,currency='jpy',stale_hubs=stale,sequence=h['sequence'],observed_at=now,billable=False,spending_cap_yen=cap,over_spending_cap=bool(cap) and estimate>cap,overage_sites=max(0,current-included_sites()))
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
            # Count existing registrations before removals at a billing-period boundary.
            self.observe(c,h['account'],now)
            c.execute('DELETE FROM sites WHERE hub=?',(h['id'],))
            c.executemany('INSERT INTO sites VALUES(?,?)',((h['id'],s) for s in sites))
            c.execute('UPDATE hubs SET sequence=?,payload_hash=?,updated=? WHERE id=?',(seq,body,now,h['id']))
            c.execute('INSERT INTO events(hub,sequence,digest,received,count) VALUES(?,?,?,?,?)',(h['id'],seq,body,now,len(sites)))
            h=c.execute('SELECT * FROM hubs WHERE id=?',(h['id'],)).fetchone()
            return self.result(c,h,now)
    def export(self,account,month,now=None,force=False):
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}',month):raise Invalid('invalid period; use the period start date (YYYY-MM-DD) shown in observation results')
        now=now or utc()
        y,m,d=(int(x) for x in month.split('-'));y,m=(y,m+1) if m<12 else (y+1,1)
        end=f'{y:04d}-{m:02d}-{d:02d}T00:00:00+00:00'
        with self.db() as c:
            row=c.execute('SELECT * FROM months WHERE account=? AND month=?',(account,month)).fetchone()
            if not row:raise Invalid('no observations for period')
            # A hub that hasn't reported since the period ended may still be holding
            # data for it; only trust the peak once it has, or once the grace period lapses.
            unsynced=c.execute("SELECT COUNT(*) FROM hubs WHERE account=? AND revoked='' AND (updated='' OR updated<?)",(account,end)).fetchone()[0]
            grace_hours=(datetime.fromisoformat(now)-datetime.fromisoformat(end)).total_seconds()/3600
            if unsynced and grace_hours<72 and not force:raise Invalid(f'{unsynced} hub(s) have not reported since this period ended at {end}; wait until 72h after that, or pass force=True to accept the risk')
            r=dict(row);r.update(mode='pilot',currency='jpy',amount_yen=r['base']+r['peak']*r['unit'],billable=False,unsynced_hubs=unsynced)
            return r

def _subscription_item_id(items):
    """Pick the recurring per-site item from a Stripe subscription."""
    if not items:return None
    price_id=os.environ.get('STRIPE_PRICE_ID','')
    matched=next((it for it in items if price_id and (it.get('price') or {}).get('id')==price_id),None)
    return (matched or items[0]).get('id')

def handle_stripe_event(store,event):
    """Wire specific verified Stripe events to account actions; everything else is left as
    just recorded (see Store.record_stripe_event). Requires 'account' (and for provisioning,
    'hub') in the Stripe object's metadata -- set this on the Checkout Session, using
    subscription_data.metadata so it carries onto the subscription for later events too.
    Checkout Session creation itself is not implemented yet, so nothing sets this metadata
    yet; this function is the receiving half, ready for when it is."""
    etype=event.get('type');obj=(event.get('data') or {}).get('object') or {}
    meta=obj.get('metadata') or {}
    try:
        if etype=='checkout.session.completed':
            account=meta.get('account');hub=meta.get('hub')
            if not account or not hub:return {'action':'skipped','reason':'missing account/hub metadata'}
            base=int(meta['base']) if 'base' in meta else 10000
            unit=int(meta['unit']) if 'unit' in meta else 100
            token=store.provision(account,hub,base,unit)
            # A management token doubles as this account's proof-of-identity for the manage
            # link, without building a login system: whoever holds the (random, unguessable)
            # token can request a fresh Stripe Portal session for this account's subscription.
            manage_url=None
            stripe_customer=obj.get('customer')
            if stripe_customer:
                store.set_stripe_customer(account,stripe_customer)
                manage_token=store.issue_management_token(account,stripe_customer)
                manage_base=os.environ.get('MANAGE_BASE_URL','')
                if manage_base:manage_url=manage_base.rstrip('/')+'/v1/manage/portal?token='+manage_token
            # Stripe does not guarantee webhook delivery order: customer.subscription.created
            # for this same purchase can (and in practice does) arrive before this event. That
            # handler requires the account to already exist, so if it arrives first it finds
            # nothing to link to and silently gives up for good (Stripe does not redeliver a
            # successfully-received event). Link the subscription here too, right after the
            # account is guaranteed to exist, using the subscription id Checkout already
            # carries -- so linkage no longer depends on event arrival order. Best-effort: a
            # failure here must not undo the provisioning that already happened above.
            subscription_id=obj.get('subscription')
            if subscription_id:
                key=stripe_secret_key()
                if key:
                    try:
                        sub=stripe_get('subscriptions/'+subscription_id,key)
                        items=((sub.get('items') or {}).get('data') or [])
                        item_id=_subscription_item_id(items)
                        if item_id:store.set_stripe_subscription(account,subscription_id,item_id,customer_id=stripe_customer)
                    except Exception:pass
            # Webhook responses are not seen by the customer, so email is the only delivery
            # channel; a delivery failure must not undo the provision that already happened.
            email=(obj.get('customer_details') or {}).get('email') or obj.get('customer_email')
            delivered=False
            if email:
                try:
                    mailer.send(email,'ご利用開始のご案内',mailer.hub_token_email_body(account,hub,token,manage_url))
                    delivered=True
                except Exception:delivered=False
            store.record_webhook(account,etype)
            return {'action':'provisioned','account':account,'hub':hub,'hub_token':token,'email_delivered':delivered}
        if etype=='customer.subscription.created':
            account=meta.get('account')
            if not account:return {'action':'skipped','reason':'missing account metadata'}
            subscription_id=obj.get('id');items=((obj.get('items') or {}).get('data') or [])
            item_id=_subscription_item_id(items)
            if not subscription_id or not item_id:return {'action':'skipped','reason':'missing subscription or item id'}
            store.set_stripe_subscription(account,subscription_id,item_id,customer_id=obj.get('customer'))
            store.record_webhook(account,etype)
            return {'action':'subscription_linked','account':account,'subscription':subscription_id}
        if etype=='customer.subscription.deleted':
            account=meta.get('account')
            if not account:return {'action':'skipped','reason':'missing account metadata'}
            store.suspend(account,reason='subscription_canceled')
            store.record_webhook(account,etype)
            return {'action':'suspended','account':account}
        if etype in ('invoice.paid','invoice.payment_failed'):
            account=meta.get('account');month=meta.get('month')
            if not account or not month:return {'action':'skipped','reason':'missing account/month metadata'}
            status='paid' if etype=='invoice.paid' else 'failed'
            amount=obj.get('amount_paid') if status=='paid' else obj.get('amount_due')
            store.record_payment(account,month,status,amount)
            dunning=None
            if status=='failed':
                store.suspend(account,reason='payment_failed');dunning='suspended'
            else:
                # Only reverse our own automatic suspend; a manual one (e.g. abuse) stands.
                with store.db() as c:acc=c.execute('SELECT suspend_reason FROM accounts WHERE id=?',(account,)).fetchone()
                if acc and acc['suspend_reason']=='payment_failed':store.unsuspend(account);dunning='unsuspended'
            store.record_webhook(account,etype)
            return {'action':'payment_recorded','account':account,'month':month,'status':status,'dunning_action':dunning}
    except sqlite3.IntegrityError:
        return {'action':'skipped','reason':'hub already provisioned'}
    except (Invalid,ValueError):
        return {'action':'skipped','reason':'invalid or unknown account/hub in metadata'}
    return {'action':'recorded'}

def stripe_secret_key():
    """Use the live key when it is configured, otherwise retain test compatibility."""
    return os.environ.get('STRIPE_SECRET_KEY','') or os.environ.get('STRIPE_TEST_SECRET_KEY','')

def stripe_live_enabled():
    """An explicit second switch prevents accidental live charging after a key is pasted."""
    return os.environ.get('STRIPE_LIVE_ENABLED','')=='1'

def stripe_mode(key):
    if key.startswith('sk_live_') and stripe_live_enabled():return 'live'
    if key.startswith('sk_test_'):return 'test'
    raise ValueError('Stripe billing is not safely configured')

def included_sites():
    raw=os.environ.get('STRIPE_INCLUDED_SITES','10')
    try:value=int(raw)
    except (TypeError,ValueError):raise ValueError('STRIPE_INCLUDED_SITES must be an integer')
    if not 0<=value<=100000:return (_ for _ in ()).throw(ValueError('STRIPE_INCLUDED_SITES is out of range'))
    return value

def sync_stripe_meter(store,account,period,current,sequence):
    """Best-effort report of the *current* extra-site count to the Billing Meter.

    The Dashboard meter is configured with ``last`` aggregation.  Replaying the same
    snapshot uses a stable identifier, so a retry cannot add another site's worth of usage.
    A Stripe outage never makes WordPress synchronization fail; the next snapshot retries.
    """
    key=stripe_secret_key();event_name=os.environ.get('STRIPE_METER_EVENT_NAME','')
    if not (key and event_name):return
    state=store.stripe_account_state(account);customer=state['customer_id']
    if not customer:return
    overage=max(0,current-included_sites())
    identifier='arai_'+digest(account+'|'+period+'|'+str(sequence)+'|'+str(overage))[:48]
    try:
        if stripe_live_enabled():record_meter_event(event_name,customer,overage,key,identifier,allow_live=True)
        else:record_meter_event(event_name,customer,overage,key,identifier)
    except Exception:pass

def sync_stripe_site_quantity(store,account,current):
    """Best-effort mirror of active sites to the Stripe subscription quantity."""
    key=stripe_secret_key()
    if not (key and os.environ.get('STRIPE_PRICE_ID','')):return
    item_id=store.stripe_subscription_item(account)
    if not item_id:return
    try:
        quantity=max(1,current)
        if stripe_live_enabled():update_subscription_item_quantity(item_id,quantity,key,allow_live=True)
        else:update_subscription_item_quantity(item_id,quantity,key)
    except Exception:pass

def stripe_get(path,key):
    # A plain authenticated GET; stripe_draft.post() only ever POSTs, and this shim needs to
    # read back live subscription state rather than create anything.
    req=urllib.request.Request('https://api.stripe.com/v1/'+path,headers={'Authorization':'Bearer '+key})
    try:
        with urllib.request.urlopen(req,timeout=20) as r:return json.load(r)
    except (urllib.error.URLError,urllib.error.HTTPError,ValueError):raise RuntimeError('Stripe request failed')

_overage_price_cache={}
def overage_price_info(key):
    """Unit amount/currency of STRIPE_OVERAGE_PRICE_ID, for the WordPress contract-check
    screen's 'expected overage charge' line. Cached for an hour (module-level, per-process)
    since the Price object practically never changes and this would otherwise mean one
    extra Stripe call on every test-status check."""
    price_id=os.environ.get('STRIPE_OVERAGE_PRICE_ID','')
    if not price_id:return None
    cached=_overage_price_cache.get(price_id)
    if cached and time.time()-cached[0]<3600:return cached[1]
    try:
        price=stripe_get('prices/'+price_id,key)
        info={'unit_amount':price.get('unit_amount'),'currency':price.get('currency')}
        if type(info['unit_amount']) is not int or not isinstance(info['currency'],str):return None
    except Exception:return None
    _overage_price_cache[price_id]=(time.time(),info)
    return info

def handler(store):
    # Shared across requests/threads: a simple in-memory rate limit for the public signup
    # endpoint. Resets on restart and is per-process (fine at pilot scale; a real multi-
    # instance deployment would need a shared store instead).
    signup_hits={};signup_lock=threading.Lock()
    def rate_limited(ip,limit=5,window=600):
        now=time.time()
        with signup_lock:
            hits=[t for t in signup_hits.get(ip,()) if now-t<window]
            hits.append(now);signup_hits[ip]=hits
            return len(hits)>limit
    class Handler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup();self.connection.settimeout(10)
        def log_message(self,*args):pass # Do not log authorization, payloads or query strings.
        def reply(self,code,data):
            body=json.dumps(data,separators=(',',':')).encode();self.send_response(code)
            self.send_header('Content-Type','application/json');self.send_header('Cache-Control','no-store');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        def dispatch(self):
            try:
                auth=self.headers.get('Authorization','');token=auth[7:] if auth.startswith('Bearer ') else ''
                if self.command=='GET' and self.path=='/v1/usage': r=store.status(token)
                elif self.command=='GET' and self.path=='/signup':
                    # A minimal test-purchase screen: it only calls the existing POST
                    # /v1/signup and redirects to the Checkout URL that returns. Same
                    # configuration gate as /v1/signup, so an unfinished deployment doesn't
                    # advertise a half-built signup flow.
                    if not all((os.environ.get('STRIPE_PRICE_ID'),os.environ.get('SIGNUP_SUCCESS_URL'),os.environ.get('SIGNUP_CANCEL_URL'),stripe_secret_key())):return self.reply(404,{'error':'not_found'})
                    body=SIGNUP_PAGE_HTML.encode('utf-8')
                    self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.send_header('Cache-Control','no-store');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body);return
                elif self.command=='GET' and self.path.startswith('/v1/manage/portal'):
                    # A GET (not POST) so this works as a plain link clicked from email.
                    # Bearer-style auth via query param, since there is no login/session
                    # system; the management token itself is the credential (see
                    # handle_stripe_event and Store.issue_management_token).
                    return_url=os.environ.get('PORTAL_RETURN_URL','');key=stripe_secret_key()
                    if not (return_url and key):return self.reply(404,{'error':'not_found'})
                    mtoken=(parse_qs(urlparse(self.path).query).get('token') or [''])[0]
                    with store.db() as c:row=store.auth_management(c,mtoken)
                    session=create_portal_session(row['stripe_customer'],return_url,key,allow_live=stripe_live_enabled())
                    self.send_response(302);self.send_header('Location',session['url']);self.send_header('Content-Length','0');self.end_headers();return
                elif self.command=='POST' and self.path=='/v1/manage/hubs':
                    # Same management-token auth as the portal link, via the normal
                    # Authorization header this time since this is a plain JSON API call,
                    # not a link meant to be clicked directly.
                    with store.db() as c:row=store.auth_management(c,token)
                    hub,hub_token=store.issue_additional_hub(row['account'])
                    r={'hub':hub,'hub_token':hub_token}
                elif self.command=='POST' and self.path=='/v1/manage/spending-cap':
                    # Self-service budget alert: warning-only, never blocks usage (see result()).
                    with store.db() as c:row=store.auth_management(c,token)
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=1000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict) or type(body.get('spending_cap_yen')) is not int:raise Invalid('expected an integer spending_cap_yen')
                    store.set_spending_cap(row['account'],body['spending_cap_yen'])
                    r={'account':row['account'],'spending_cap_yen':body['spending_cap_yen']}
                elif self.command=='POST' and self.path=='/v1/snapshot':
                    # Authenticate before reading a potentially large request.
                    with store.db() as c:h=store.auth(c,token)
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=8000000:raise Invalid('payload size')
                    r=store.snapshot(token,json.loads(self.rfile.read(size)))
                    # A per-site subscription must never be combined with legacy metered overage.
                    if os.environ.get('STRIPE_PRICE_ID'):sync_stripe_site_quantity(store,h['account'],r['current'])
                    else:sync_stripe_meter(store,h['account'],r['month'],r['current'],r['sequence'])
                elif self.command=='POST' and self.path=='/v1/stripe/test-checkout':
                    # Compatibility shim for an already-connected hub upgrading itself to a
                    # paid plan from inside its own WordPress admin -- distinct from the
                    # public, anonymous /v1/signup flow above, which is for a brand new
                    # customer who has not connected a hub yet. Checkout still runs through
                    # create_checkout_session()/handle_stripe_event() like /v1/signup does, so
                    # the resulting subscription gets linked via the normal
                    # customer.subscription.created webhook; the hub itself is already
                    # provisioned, so /v1/signup's own provisioning step on
                    # checkout.session.completed just no-ops for it (hub already exists).
                    with store.db() as c:h=store.auth(c,token)
                    key=stripe_secret_key();price_id=os.environ.get('STRIPE_PRICE_ID','')
                    if not (key and price_id):raise RuntimeError('Stripe test checkout is not configured')
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict):raise Invalid('expected an object')
                    success_url=body.get('success_url','');cancel_url=body.get('cancel_url','')
                    state=store.stripe_account_state(h['account'])
                    if state['subscription_id']:
                        live=stripe_get('subscriptions/'+state['subscription_id'],key)
                        if live.get('status') in ('active','trialing','past_due','unpaid'):raise Conflict()
                    mode=stripe_mode(key)
                    session=create_checkout_session(h['account'],h['id'],price_id,success_url,cancel_url,key,overage_price_id=os.environ.get('STRIPE_OVERAGE_PRICE_ID') or None,allow_live=stripe_live_enabled())
                    r={'mode':mode,'checkout_url':session['url']}
                elif self.command=='GET' and self.path=='/v1/stripe/test-status':
                    # Same shim family as test-checkout above: status for the hub's own
                    # account, re-verified live against Stripe rather than trusting whatever a
                    # possibly-delayed or out-of-order webhook last recorded locally. Also
                    # backs the WordPress "contract check" screen, so this bundles webhook
                    # receipt and overage figures alongside the subscription status rather
                    # than making that screen call three separate endpoints.
                    with store.db() as c:
                        h=store.auth(c,token)
                        usage=store.result(c,h,utc())
                    key=stripe_secret_key()
                    state=store.stripe_account_state(h['account'])
                    if not (key and state['subscription_id']):
                        r={'mode':stripe_mode(key) if key else 'test','subscription_status':'none','updated':utc(),'cancellation_pending':False,'cancellation_at':0}
                    else:
                        live=stripe_get('subscriptions/'+state['subscription_id'],key)
                        cancel_at=live.get('cancel_at')
                        pending=bool(live.get('cancel_at_period_end')) or (type(cancel_at) is int and cancel_at>0)
                        period=live.get('current_period_end')
                        if type(period) is not int or period<0:period=cancel_at if type(cancel_at) is int and cancel_at>=0 else 0
                        r={'mode':stripe_mode(key),'subscription_status':live.get('status',''),'updated':utc(),
                           'cancellation_pending':pending,'cancellation_at':period if pending else 0}
                    r.update(store.webhook_state(h['account']))
                    r['overage_sites']=usage['overage_sites']
                    price=overage_price_info(key) if key else None
                    if price:
                        r['overage_unit_amount']=price['unit_amount'];r['overage_currency']=price['currency']
                        r['overage_amount']=price['unit_amount']*usage['overage_sites']
                    else:
                        r['overage_unit_amount']=None;r['overage_currency']=None;r['overage_amount']=None
                elif self.command=='POST' and self.path=='/v1/stripe/test-portal':
                    # Same shim family: open a Stripe-hosted portal for the hub's own account's
                    # customer, recorded from the customer.subscription.created webhook (see
                    # handle_stripe_event) rather than the management-token flow above, which
                    # only exists for accounts created through the public /v1/signup path.
                    with store.db() as c:h=store.auth(c,token)
                    key=stripe_secret_key()
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict):raise Invalid('expected an object')
                    state=store.stripe_account_state(h['account'])
                    if not (key and state['customer_id']):raise Invalid('no Stripe customer on file for this account yet')
                    session=create_portal_session(state['customer_id'],body.get('return_url',''),key,allow_live=stripe_live_enabled())
                    r={'mode':stripe_mode(key),'portal_url':session['url']}
                elif self.command=='POST' and self.path=='/v1/admin/provision':
                    # Disabled unless ADMIN_TOKEN is set; exists only so hub tokens can be
                    # issued on hosts with no shell access (e.g. a free-tier PaaS).
                    admin=os.environ.get('ADMIN_TOKEN','')
                    given=self.headers.get('X-Admin-Token','')
                    if not admin or not hmac.compare_digest(given,admin):raise Unauthorized()
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict):raise Invalid('expected an object')
                    r={'hub_token':store.provision(body.get('account'),body.get('hub'),int(body.get('base',10000)),int(body.get('unit',100)),(int(body['anchor_day']) if 'anchor_day' in body else None)),'mode':'pilot'}
                elif self.command=='POST' and self.path=='/v1/admin/revoke':
                    # Same admin gate as provision; the mirror-image operation for ending a hub's access.
                    admin=os.environ.get('ADMIN_TOKEN','')
                    given=self.headers.get('X-Admin-Token','')
                    if not admin or not hmac.compare_digest(given,admin):raise Unauthorized()
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict) or not isinstance(body.get('hub'),str):raise Invalid('expected a hub id')
                    store.revoke(body['hub']);r={'revoked':body['hub']}
                elif self.command=='POST' and self.path in ('/v1/admin/suspend','/v1/admin/unsuspend'):
                    # Same admin gate; freezes/unfreezes every hub under an account at once (delinquency handling).
                    admin=os.environ.get('ADMIN_TOKEN','')
                    given=self.headers.get('X-Admin-Token','')
                    if not admin or not hmac.compare_digest(given,admin):raise Unauthorized()
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size))
                    if not isinstance(body,dict) or not isinstance(body.get('account'),str):raise Invalid('expected an account id')
                    if self.path=='/v1/admin/suspend':store.suspend(body['account'])
                    else:store.unsuspend(body['account'])
                    r={'account':body['account'],'suspended':self.path=='/v1/admin/suspend'}
                elif self.command=='POST' and self.path=='/v1/stripe/webhook':
                    # Disabled unless STRIPE_WEBHOOK_SECRET is set.
                    secret=os.environ.get('STRIPE_WEBHOOK_SECRET','')
                    if not secret:raise Unauthorized()
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<size<=65536:raise Invalid('payload size')
                    body=self.rfile.read(size)
                    try:event=verify_webhook(body,self.headers.get('Stripe-Signature'),secret)
                    except ValueError:raise Unauthorized()
                    is_new=store.record_stripe_event(event)
                    r={'type':event.get('type'),'new':is_new}
                    # Only act the first time an event is seen; Stripe redelivers at-least-once.
                    if is_new:r.update(handle_stripe_event(store,event))
                elif self.command=='POST' and self.path=='/v1/signup':
                    # Public, unauthenticated by design (this is how a new customer starts).
                    # 404s instead of 401 when unconfigured, so an unfinished deployment
                    # doesn't advertise a half-built signup flow.
                    price_id=os.environ.get('STRIPE_PRICE_ID','');success_url=os.environ.get('SIGNUP_SUCCESS_URL','')
                    cancel_url=os.environ.get('SIGNUP_CANCEL_URL','');key=stripe_secret_key()
                    if not (price_id and success_url and cancel_url and key):return self.reply(404,{'error':'not_found'})
                    if rate_limited(self.client_address[0]):return self.reply(429,{'error':'rate_limited'})
                    size=int(self.headers.get('Content-Length','0'))
                    if not 0<=size<=4000:raise Invalid('payload size')
                    body=json.loads(self.rfile.read(size)) if size else {}
                    if not isinstance(body,dict):raise Invalid('expected an object')
                    email=body.get('email')
                    if email is not None and (not isinstance(email,str) or not re.fullmatch(r'[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,24}',email)):raise Invalid('invalid email')
                    # account/hub are never taken from the request: random IDs prevent
                    # guessing or colliding with an existing customer.
                    account='acct-'+secrets.token_hex(8);hub='hub-'+secrets.token_hex(8)
                    base=int(os.environ.get('SIGNUP_BASE','10000'));unit=int(os.environ.get('SIGNUP_UNIT','100'))
                    session=create_checkout_session(account,hub,price_id,success_url,cancel_url,key,customer_email=email,base=base,unit=unit,overage_price_id=os.environ.get('STRIPE_OVERAGE_PRICE_ID') or None,allow_live=stripe_live_enabled())
                    r={'url':session['url']}
                else:return self.reply(404,{'error':'not_found'})
                self.reply(200,r)
            except Unauthorized:self.reply(401,{'error':'unauthorized'})
            except Conflict:self.reply(409,{'error':'sequence_conflict'})
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
    s=sub.add_parser('provision');s.add_argument('account');s.add_argument('hub');s.add_argument('--base',type=int,default=10000);s.add_argument('--unit',type=int,default=100);s.add_argument('--anchor-day',type=int,default=None,help='billing day of month (1-28); defaults to the day this account is first provisioned')
    s=sub.add_parser('export');s.add_argument('account');s.add_argument('period',help='billing period start date (YYYY-MM-DD), as shown in observation results');s.add_argument('--force',action='store_true',help='export even if hubs have not reported since the period ended and the 72h grace window has not passed')
    s=sub.add_parser('revoke');s.add_argument('hub',help='hub id to permanently deny further sync/status requests from; its already-recorded peaks are unaffected')
    s=sub.add_parser('suspend');s.add_argument('account',help='freeze every hub under this account (e.g. non-payment); site counts are kept as-is, only new sync/status requests are denied')
    s=sub.add_parser('unsuspend');s.add_argument('account')
    s=sub.add_parser('backup');s.add_argument('target',help='path to write a consistent point-in-time copy to; safe to run against a live server')
    s=sub.add_parser('check',help='run PRAGMA integrity_check and exit non-zero if it finds damage')
    a=p.parse_args();os.umask(0o077);store=Store(a.db)
    if a.command=='provision':print(json.dumps({'hub_token':store.provision(a.account,a.hub,a.base,a.unit,a.anchor_day),'mode':'pilot'}))
    elif a.command=='export':print(json.dumps(store.export(a.account,a.period,force=a.force)))
    elif a.command=='revoke':store.revoke(a.hub);print(json.dumps({'revoked':a.hub}))
    elif a.command=='suspend':store.suspend(a.account);print(json.dumps({'account':a.account,'suspended':True}))
    elif a.command=='unsuspend':store.unsuspend(a.account);print(json.dumps({'account':a.account,'suspended':False}))
    elif a.command=='backup':store.backup(a.target);print(json.dumps({'backed_up_to':a.target}))
    elif a.command=='check':
        result=store.check();print(json.dumps({'integrity_check':result}))
        if result!='ok':sys.exit(1)
    else:
        # Threaded because each request opens its own SQLite connection (see Store.db) and
        # closes it before returning; a single slow client must not stall every other hub.
        ThreadingHTTPServer((a.host,a.port),handler(store)).serve_forever()
