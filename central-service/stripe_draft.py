"""Optional Stripe TEST MODE billing adapter. Creates a draft invoice (send()), then can
finalize() and deliver() it so the test customer pays via Stripe's own hosted invoice page.
Collection is always 'send_invoice': this never auto-charges a saved payment method, and
only sk_test_ keys are accepted anywhere in this file."""
import argparse, hashlib, hmac, json, os, re, time, urllib.request, urllib.parse
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

def plan(usage,customer,days_until_due=14):
    if usage.get('mode')!='pilot' or usage.get('billable') is not False:raise ValueError('pilot export required')
    if not re.fullmatch('cus_[A-Za-z0-9]+',customer):raise ValueError('test customer required')
    for k in ('peak','base','unit','amount_yen'):
        if type(usage.get(k)) is not int or usage[k]<0:raise ValueError('nonnegative integer required')
    if usage['amount_yen']!=usage['base']+usage['unit']*usage['peak']:raise ValueError('amount mismatch')
    if usage['amount_yen']>99999999:raise ValueError('pilot amount limit; large invoices require separate design')
    if type(days_until_due) is not int or not 1<=days_until_due<=90:raise ValueError('days_until_due must be between 1 and 90')
    return {'customer':customer,'currency':'jpy','amount':usage['amount_yen'],
            'description':'TEST ONLY AutoRepair '+usage['month']+' peak='+str(usage['peak']),
            'auto_advance':'false','pending_invoice_items_behavior':'exclude',
            # send_invoice (not charge_automatically): the customer pays via Stripe's hosted
            # page after finalize()+deliver(); a saved payment method is never auto-charged.
            'collection_method':'send_invoice','days_until_due':days_until_due,
            # So invoice.paid/invoice.payment_failed webhooks can be matched back to our
            # own account/period without guessing from the Stripe customer ID alone.
            'metadata[account]':usage['account'],'metadata[month]':usage['month']}

def post(path,data,key,identity):
    req=urllib.request.Request('https://api.stripe.com/v1/'+path,data=urllib.parse.urlencode(data).encode(),headers={'Authorization':'Bearer '+key,'Idempotency-Key':identity,'Content-Type':'application/x-www-form-urlencoded'},method='POST')
    with urllib.request.urlopen(req,timeout=20) as r:return json.load(r)

def plan_checkout(account,hub,price_id,success_url,cancel_url,customer_email=None,base=10000,unit=100,overage_price_id=None):
    """Build a subscription Checkout Session.

    ``price_id`` is the fixed base subscription.  When ``overage_price_id`` is supplied,
    it is a Billing Meter price and is added as a second subscription item.  Its quantity is
    intentionally omitted: Stripe calculates it from meter events sent by service.py.
    """
    if not all(re.fullmatch(r'[a-z0-9_-]{1,64}',x) for x in (account,hub)):raise ValueError('invalid account or hub id')
    if not re.fullmatch(r'price_[A-Za-z0-9]+',price_id):raise ValueError('a Stripe Price ID (price_...) is required')
    if overage_price_id is not None and not re.fullmatch(r'price_[A-Za-z0-9]+',overage_price_id):raise ValueError('an overage Stripe Price ID (price_...) is required')
    for url in (success_url,cancel_url):
        if not re.fullmatch(r'https://\S+',url):raise ValueError('success_url/cancel_url must be https')
    if type(base) is not int or type(unit) is not int or min(base,unit)<0:raise ValueError('invalid pricing')
    data={'mode':'subscription','line_items[0][price]':price_id,'line_items[0][quantity]':'1',
          'success_url':success_url,'cancel_url':cancel_url,
          'subscription_data[metadata][account]':account,'subscription_data[metadata][hub]':hub,
          'subscription_data[metadata][base]':str(base),'subscription_data[metadata][unit]':str(unit),
          'metadata[account]':account,'metadata[hub]':hub,
          'metadata[base]':str(base),'metadata[unit]':str(unit)}
    if customer_email is not None:data['customer_email']=customer_email
    if overage_price_id is not None:data['line_items[1][price]']=overage_price_id
    return data

def create_checkout_session(account,hub,price_id,success_url,cancel_url,key,customer_email=None,base=10000,unit=100,overage_price_id=None,transport=post):
    """Create the Checkout Session a new customer would actually visit. TEST MODE ONLY.
    Does not deliver the resulting URL anywhere; the caller (not implemented yet) is
    responsible for presenting it to the customer."""
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    data=plan_checkout(account,hub,price_id,success_url,cancel_url,customer_email,base,unit,overage_price_id)
    # Stable per (account, hub, price, success/cancel URL): a retried click with the same
    # destination reuses the still-valid session instead of spawning a new one. success_url
    # and cancel_url are part of the key (not just account/hub/price) because Stripe rejects
    # any reuse of an idempotency key with different request parameters (idempotency_error);
    # a caller that legitimately needs a different redirect destination must not collide with
    # an earlier attempt's cached session.
    identity=hashlib.sha256((account+'|'+hub+'|'+price_id+'|'+(overage_price_id or '')+'|'+success_url+'|'+cancel_url).encode()).hexdigest()
    r=transport('checkout/sessions',data,key,identity)
    if r.get('livemode') is not False or not re.fullmatch('cs_[A-Za-z0-9_]+',r.get('id','')) or not r.get('url'):raise ValueError('unexpected checkout session response')
    return {'id':r['id'],'url':r['url']}

def record_meter_event(event_name,customer_id,value,key,identifier,transport=post):
    """Record the latest overage count for a Stripe Billing Meter in test mode.

    The configured meter uses ``last`` aggregation.  Therefore ``value`` must be the
    *current* number of excess sites, not a running total or this period's peak.
    """
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    if not re.fullmatch(r'[A-Za-z0-9_]{1,100}',event_name):raise ValueError('invalid meter event name')
    if not re.fullmatch(r'cus_[A-Za-z0-9]+',customer_id):raise ValueError('a Stripe customer ID (cus_...) is required')
    if type(value) is not int or value<0:raise ValueError('meter value must be a nonnegative integer')
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,100}',identifier):raise ValueError('invalid meter event identifier')
    data={'event_name':event_name,'payload[stripe_customer_id]':customer_id,'payload[value]':str(value),'identifier':identifier}
    r=transport('billing/meter_events',data,key,identifier)
    if r.get('livemode') is not False or r.get('event_name')!=event_name:raise ValueError('unexpected meter event response')
    return {'identifier':identifier,'value':value}

def create_portal_session(customer,return_url,key,transport=post):
    """Create a Stripe Billing Portal session so an existing customer can manage or cancel
    their own subscription on Stripe's hosted page. TEST MODE ONLY. There is no public
    endpoint or login system calling this yet; an operator confirms the customer's identity
    some other way (e.g. their reply to the account's registered email) and runs this from
    the CLI (--portal-customer) to hand them the resulting link."""
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    if not re.fullmatch('cus_[A-Za-z0-9]+',customer):raise ValueError('test customer required')
    if not re.fullmatch(r'https://\S+',return_url):raise ValueError('return_url must be https')
    # Idempotent within the same minute only (unlike Checkout, a customer may legitimately
    # ask for a fresh portal link many times over weeks; each such ask should get one).
    identity=hashlib.sha256((customer+'|'+return_url+'|'+str(int(time.time()//60))).encode()).hexdigest()
    r=transport('billing_portal/sessions',{'customer':customer,'return_url':return_url},key,identity)
    if r.get('livemode') is not False or not re.fullmatch('bps_[A-Za-z0-9_]+',r.get('id','')) or not r.get('url'):raise ValueError('unexpected portal session response')
    return {'id':r['id'],'url':r['url']}

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
            r=transport('invoices',{k:payload[k] for k in ('customer','currency','description','auto_advance','pending_invoice_items_behavior','collection_method','days_until_due','metadata[account]','metadata[month]')},key,identity+'-invoice')
            if r.get('livemode') is not False or r.get('status')!='draft' or not re.fullmatch('in_[A-Za-z0-9]+',r.get('id','')):raise ValueError('unexpected invoice response')
            state['invoice']=r['id'];persist()
        r=transport('invoiceitems',{'customer':customer,'invoice':state['invoice'],'currency':'jpy','amount':payload['amount'],'description':payload['description']},key,identity+'-item')
        if r.get('livemode') is not False or not r.get('id'):raise ValueError('unexpected item response')
        state['item']=r['id'];state['complete']=True;persist();return state

def finalize(usage,customer,state_dir,key,transport=post):
    """Finalize a completed draft invoice (see send()): Stripe locks it and marks it 'open'
    (awaiting payment). TEST MODE ONLY. Does not email the customer; call deliver() for that."""
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    directory=Path(state_dir)
    identity=hashlib.sha256((usage['account']+'|'+usage['month']+'|'+customer).encode()).hexdigest()
    if not directory.exists():raise ValueError('no draft found for this account/period/customer; run send() first')
    with (directory/(identity+'.lock')).open('a+') as lock:
        _lock_exclusive(lock)
        target=directory/(identity+'.json')
        if not target.exists():raise ValueError('no draft found for this account/period/customer; run send() first')
        state=json.loads(target.read_text())
        if not state.get('complete'):raise ValueError('draft is not complete yet; finish send() first')
        if state.get('finalized'):return state
        def persist():
            temp=target.with_suffix('.tmp');temp.write_text(json.dumps(state));os.replace(temp,target)
        r=transport('invoices/'+state['invoice']+'/finalize',{},key,identity+'-finalize')
        if r.get('livemode') is not False or r.get('status') not in ('open','paid'):raise ValueError('unexpected finalize response')
        state['finalized']=True;state['finalized_status']=r['status'];persist();return state

def deliver(usage,customer,state_dir,key,transport=post):
    """Email the finalized invoice to the test customer via Stripe's hosted invoice page.
    TEST MODE ONLY. Requires finalize() to have completed first."""
    if not key.startswith('sk_test_'):raise ValueError('Only sk_test_ keys allowed. Live billing is not implemented.')
    directory=Path(state_dir)
    identity=hashlib.sha256((usage['account']+'|'+usage['month']+'|'+customer).encode()).hexdigest()
    if not directory.exists():raise ValueError('no draft found for this account/period/customer; run send() first')
    with (directory/(identity+'.lock')).open('a+') as lock:
        _lock_exclusive(lock)
        target=directory/(identity+'.json')
        if not target.exists():raise ValueError('no draft found for this account/period/customer; run send() first')
        state=json.loads(target.read_text())
        if not state.get('finalized'):raise ValueError('invoice is not finalized yet; run finalize() first')
        if state.get('delivered'):return state
        def persist():
            temp=target.with_suffix('.tmp');temp.write_text(json.dumps(state));os.replace(temp,target)
        r=transport('invoices/'+state['invoice']+'/send',{},key,identity+'-deliver')
        if r.get('livemode') is not False:raise ValueError('unexpected send response')
        state['delivered']=True;persist();return state

def verify_webhook(payload,sig_header,secret,now=None,tolerance=300):
    """Verify a Stripe webhook request per https://docs.stripe.com/webhooks/signatures.
    payload must be the raw request body (bytes); never parse it as JSON before this succeeds.
    Returns the parsed event on success. Does not decide what to do with any event type;
    service.py's /v1/stripe/webhook endpoint just records verified events for now."""
    if not secret.startswith('whsec_'):raise ValueError('a webhook signing secret (whsec_...) is required')
    if not isinstance(sig_header,str) or not sig_header:raise ValueError('missing Stripe-Signature header')
    fields={}
    for item in sig_header.split(','):
        k,_,v=item.partition('=')
        if k in ('t','v1'):fields.setdefault(k,[]).append(v)
    if len(fields.get('t',[]))!=1 or not fields.get('v1') or not re.fullmatch(r'\d+',fields['t'][0]):raise ValueError('malformed Stripe-Signature header')
    timestamp=fields['t'][0];now=time.time() if now is None else now
    if abs(now-int(timestamp))>tolerance:raise ValueError('timestamp outside tolerance; possible replay')
    body=payload if isinstance(payload,bytes) else payload.encode()
    expected=hmac.new(secret.encode(),timestamp.encode()+b'.'+body,hashlib.sha256).hexdigest()
    # Stripe sends multiple v1 signatures while a signing secret is being rotated; any match is valid.
    if not any(hmac.compare_digest(expected,v) for v in fields['v1']):raise ValueError('signature mismatch')
    return json.loads(body)

def correct(usage,customer,state_dir,reason):
    """Archive a prior submission for this account/period/customer so send() can start over
    with a corrected amount. Never touches Stripe: the operator must separately delete/void
    the old draft invoice found in the archived record, using its 'invoice'/'item' IDs."""
    if not isinstance(reason,str) or not reason.strip():raise ValueError('a non-empty correction reason is required for the audit trail')
    directory=Path(state_dir)
    identity=hashlib.sha256((usage['account']+'|'+usage['month']+'|'+customer).encode()).hexdigest()
    target=directory/(identity+'.json')
    if not target.exists():raise ValueError('nothing to correct: no prior submission for this account/period/customer')
    with (directory/(identity+'.lock')).open('a+') as lock:
        _lock_exclusive(lock)
        state=json.loads(target.read_text())
        archive=directory/(identity+'.corrected-'+str(int(time.time()))+'.json')
        archive.write_text(json.dumps({**state,'corrected_at':time.time(),'corrected_reason':reason}))
        target.unlink()
    return json.loads(archive.read_text())

def record_refund(usage,customer,state_dir,reason,amount=None):
    """Record a refund decision for a completed submission, for an audit trail only. Never
    touches Stripe: refunding is money leaving the account, so -- like correct() -- the
    operator issues the actual refund in the Stripe dashboard, using the 'invoice'/'item'
    IDs already on record. amount=None means a full refund; otherwise it must be a positive
    integer not exceeding the original invoice amount (no over-refunding).
    Confirming the invoice was actually paid (see service.Store.payment_status) before
    calling this is the operator's responsibility; this function does not check it."""
    if not isinstance(reason,str) or not reason.strip():raise ValueError('a non-empty refund reason is required for the audit trail')
    directory=Path(state_dir)
    identity=hashlib.sha256((usage['account']+'|'+usage['month']+'|'+customer).encode()).hexdigest()
    target=directory/(identity+'.json')
    if not target.exists():raise ValueError('nothing to refund: no prior submission for this account/period/customer')
    with (directory/(identity+'.lock')).open('a+') as lock:
        _lock_exclusive(lock)
        state=json.loads(target.read_text())
        if not state.get('complete'):raise ValueError('invoice was never completed; nothing was charged to refund')
        if state.get('refunded'):raise ValueError('already recorded as refunded')
        full_amount=plan(usage,customer)['amount']
        if amount is None:amount=full_amount
        elif type(amount) is not int or not 0<amount<=full_amount:raise ValueError('refund amount must be a positive integer not exceeding the original invoice amount')
        state['refunded']=True;state['refund_amount']=amount;state['refund_full_amount']=full_amount
        state['refunded_at']=time.time();state['refund_reason']=reason
        temp=target.with_suffix('.tmp');temp.write_text(json.dumps(state));os.replace(temp,target)
    return state

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('export',nargs='?',help='usage.json path (not needed with --portal-customer)');p.add_argument('--customer');p.add_argument('--send-test-draft',action='store_true');p.add_argument('--finalize',action='store_true',help='finalize a completed draft (run --send-test-draft first) so Stripe marks it open/awaiting payment')
    p.add_argument('--deliver',action='store_true',help='email a finalized invoice to the test customer via Stripe\'s hosted page (run --finalize first)')
    p.add_argument('--correct',metavar='REASON',help='archive the prior submission for this account/period/customer (for an audit trail) so it can be resubmitted with a corrected amount; does not touch Stripe')
    p.add_argument('--refund',metavar='REASON',help='record a refund decision for a completed submission (for an audit trail); does not touch Stripe, the operator issues the actual refund in the dashboard')
    p.add_argument('--refund-amount',type=int,metavar='YEN',help='partial refund amount in yen (omit for a full refund); must not exceed the original invoice amount')
    p.add_argument('--state-dir',default='stripe-state')
    p.add_argument('--portal-customer',metavar='CUS_ID',help='create a Billing Portal session for this existing Stripe customer, instead of any invoice/export action')
    p.add_argument('--portal-return-url',metavar='URL');a=p.parse_args();os.umask(0o077)
    if a.portal_customer:
        print(json.dumps(create_portal_session(a.portal_customer,a.portal_return_url,os.environ.get('STRIPE_TEST_SECRET_KEY',''))))
    else:
        if not a.export or not a.customer:p.error('export and --customer are required unless --portal-customer is used')
        usage=json.loads(Path(a.export).read_text())
        if a.correct:print(json.dumps(correct(usage,a.customer,a.state_dir,a.correct),ensure_ascii=False,indent=2))
        elif a.refund:print(json.dumps(record_refund(usage,a.customer,a.state_dir,a.refund,a.refund_amount),ensure_ascii=False,indent=2))
        elif a.deliver:print(json.dumps(deliver(usage,a.customer,a.state_dir,os.environ.get('STRIPE_TEST_SECRET_KEY',''))))
        elif a.finalize:print(json.dumps(finalize(usage,a.customer,a.state_dir,os.environ.get('STRIPE_TEST_SECRET_KEY',''))))
        elif a.send_test_draft:print(json.dumps(send(usage,a.customer,a.state_dir,os.environ.get('STRIPE_TEST_SECRET_KEY',''))))
        else:print(json.dumps(plan(usage,a.customer),ensure_ascii=False,indent=2))
