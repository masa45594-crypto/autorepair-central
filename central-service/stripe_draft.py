"""Optional Stripe TEST MODE draft adapter. Never finalizes, sends or pays invoices."""
import argparse, hashlib, json, os, re, time, urllib.request, urllib.parse
from pathlib import Path
try:
    import fcntl
    def _lock_exclusive(f): fcntl.flock(f, fcntl.LOCK_EX)
except ImportError:
    # Windows has no fcntl; used for local verification only (production targets Linux).
    import msvcrt
    def _lock_exclusive(f):
        f.seek(0)
        try: msvcrt.locking(f.fileno(), msvcrt.LK_LOCK, 1)
        except OSError: pass

def plan(usage,customer):
    if usage.get('mode')!='pilot' or usage.get('billable') is not False:raise ValueError('pilot export required')
    if not re.fullmatch('cus_[A-Za-z0-9]+',customer):raise ValueError('test customer required')
    for k in ('peak','base','unit','amount_yen'):
        if type(usage.get(k)) is not int or usage[k]<0:raise ValueError('nonnegative integer required')
    if usage['amount_yen']!=usage['base']+usage['unit']*usage['peak']:raise ValueError('amount mismatch')
    if usage['amount_yen']>99999999:raise ValueError('pilot amount limit; large invoices require separate design')
    return {'customer':customer,'currency':'jpy','amount':usage['amount_yen'],
            'description':'TEST ONLY AutoRepair '+usage['month']+' peak='+str(usage['peak']),
            'auto_advance':'false','pending_invoice_items_behavior':'exclude'}

def post(path,data,key,identity):
    req=urllib.request.Request('https://api.stripe.com/v1/'+path,data=urllib.parse.urlencode(data).encode(),headers={'Authorization':'Bearer '+key,'Idempotency-Key':identity,'Content-Type':'application/x-www-form-urlencoded'},method='POST')
    with urllib.request.urlopen(req,timeout=20) as r:return json.load(r)

def send(usage,customer,state_dir,key,transport=post):
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    payload=plan(usage,customer);fingerprint=hashlib.sha256(json.dumps(payload,sort_keys=True).encode()).hexdigest()
    directory=Path(state_dir);directory.mkdir(mode=0o700,parents=True,exist_ok=True)
    # Stable business key: changed amount for the same account/period is rejected.
    identity=hashlib.sha256((usage['account']+'|'+usage['month']+'|'+customer).encode()).hexdigest()
    with (directory/(identity+'.lock')).open('a+') as lock:
        _lock_exclusive(lock)
        target=directory/(identity+'.json')
        state=json.loads(target.read_text()) if target.exists() else {'started':time.time(),'fingerprint':fingerprint}
        if state['fingerprint']!=fingerprint:raise ValueError('period already submitted with a different amount; review required')
        if state.get('complete'):return state
        if time.time()-state['started']>72000:raise ValueError('uncertain request older than 20h; reconcile in Stripe before retrying')
        def persist():
            temp=target.with_suffix('.tmp');temp.write_text(json.dumps(state));os.replace(temp,target)
        persist()
        if not state.get('invoice'):
            r=transport('invoices',{k:payload[k] for k in ('customer','currency','description','auto_advance','pending_invoice_items_behavior')},key,identity+'-invoice')
            if r.get('livemode') is not False or r.get('status')!='draft' or not re.fullmatch('in_[A-Za-z0-9]+',r.get('id','')):raise ValueError('unexpected invoice response')
            state['invoice']=r['id'];persist()
        r=transport('invoiceitems',{'customer':customer,'invoice':state['invoice'],'currency':'jpy','amount':payload['amount'],'description':payload['description']},key,identity+'-item')
        if r.get('livemode') is not False or not r.get('id'):raise ValueError('unexpected item response')
        state['item']=r['id'];state['complete']=True;persist();return state

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('export');p.add_argument('--customer',required=True);p.add_argument('--send-test-draft',action='store_true');p.add_argument('--state-dir',default='stripe-state');a=p.parse_args();os.umask(0o077)
    usage=json.loads(Path(a.export).read_text())
    if a.send_test_draft:print(json.dumps(send(usage,a.customer,a.state_dir,os.environ.get('STRIPE_TEST_SECRET_KEY',''))))
    else:print(json.dumps(plan(usage,a.customer),ensure_ascii=False,indent=2))
