"""AutoRepair central metering pilot. Python 3.11+, standard library only.
Not a production billing or payment entitlement system. Bind loopback behind TLS.
"""
import argparse, contextlib, hashlib, json, os, re, secrets, sqlite3
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

class Invalid(Exception): pass
class Conflict(Exception): pass
class Unauthorized(Exception): pass

def utc(): return datetime.now(timezone.utc).isoformat()
def digest(x): return hashlib.sha256(x.encode()).hexdigest()

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
    s=sub.add_parser('provision');s.add_argument('account');s.add_argument('hub');s.add_argument('--base',type=int,default=10000);s.add_argument('--unit',type=int,default=100)
    s=sub.add_parser('export');s.add_argument('account');s.add_argument('month')
    a=p.parse_args();os.umask(0o077);store=Store(a.db)
    if a.command=='provision':print(json.dumps({'hub_token':store.provision(a.account,a.hub,a.base,a.unit),'mode':'pilot'}))
    elif a.command=='export':print(json.dumps(store.export(a.account,a.month)))
    else:HTTPServer((a.host,a.port),handler(store)).serve_forever()
