"""Stripe API sandbox acceptance only; not a checkout/licensing end-to-end test."""
import json
import os
from pathlib import Path
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request


def request(path,fields,key):
    data=urllib.parse.urlencode(fields).encode()
    req=urllib.request.Request('https://api.stripe.com/v1/'+path,data=data,headers={'Authorization':'Bearer '+key,'Idempotency-Key':'aaihb-test-'+secrets.token_hex(16)})
    try:
        with urllib.request.urlopen(req,timeout=30) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as e:
        return e.code,json.loads(e.read())


def run(key,api=request):
    result={'scope':'stripe_sandbox_api_only','status':'not_configured','checkout_to_license':'not_tested','checks':{}}
    if not key or not key.startswith('sk_test_'):return result
    result['status']='failed'
    try:
        fields={'amount':100,'currency':'jpy','payment_method':'pm_card_visa','payment_method_types[]':'card','confirm':'true'}
        code,paid=api('payment_intents',fields,key)
        success=code==200 and paid.get('livemode') is False and paid.get('status')=='succeeded'
        result['checks']['success']=success
        if not success:return result
        code,refund=api('refunds',{'payment_intent':paid['id']},key)
        result['checks']['refund']=code==200 and refund.get('status')=='succeeded'
        code,declined=api('payment_intents',{**fields,'payment_method':'pm_card_chargeDeclined'},key)
        error=declined.get('error',{})
        result['checks']['decline']=code==402 and error.get('code')=='card_declined' and error.get('payment_intent',{}).get('livemode') is False
        result['status']='passed' if all(result['checks'].values()) else 'failed'
    except Exception:result['status']='failed'
    return result

if __name__=='__main__':
    result=run(os.environ.get('STRIPE_TEST_SECRET_KEY',''))
    path=Path(__file__).resolve().parents[1]/'reports';path.mkdir(exist_ok=True)
    (path/'stripe-sandbox.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'status':result['status'],'scope':result['scope']}))
    sys.exit(0 if result['status']=='passed' else 1)
