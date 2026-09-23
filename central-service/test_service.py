import hashlib,hmac,json,os,re,tempfile,time,unittest,threading,urllib.request,urllib.error
from pathlib import Path
from http.server import HTTPServer
from service import Store,Invalid,Conflict,Unauthorized,handler,digest,handle_stripe_event
from stripe_draft import plan,send,correct,verify_webhook,finalize,deliver,plan_checkout,create_checkout_session,create_portal_session,record_refund,record_meter_event,update_subscription_item_quantity
import mailer
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.s=Store(str(Path(self.tmp.name)/'test.db'));self.now='2026-09-10T00:00:00+00:00'
  self.a=self.s.provision('a','hub1',anchor_day=1,now=self.now);self.b=self.s.provision('a','hub2',anchor_day=1,now=self.now);self.other=self.s.provision('other','hub3',anchor_day=1,now=self.now);self.ids=[digest(str(i)) for i in range(4)]
 def tearDown(self):self.tmp.cleanup()
 def push(self,t,seq,ids,now=None):return self.s.snapshot(t,{'sequence':seq,'sites':ids},now or self.now)
 def test_aggregate(self):
  self.push(self.a,1,self.ids[:2]);r=self.push(self.b,1,self.ids[1:3]);self.assertEqual(r['current'],3);self.assertEqual(r['estimate_yen'],10300)
 def test_tenant_isolation(self):
  self.push(self.a,1,self.ids);self.assertEqual(self.s.status(self.other,self.now)['current'],0)
 def test_retry_idempotent(self):
  self.push(self.a,1,self.ids);r=self.push(self.a,1,self.ids);self.assertEqual(r['peak'],4)
  with self.s.db() as c:self.assertEqual(c.execute('SELECT COUNT(*) FROM events').fetchone()[0],1)
 def test_conflict(self):
  self.push(self.a,2,self.ids)
  for seq,ids in [(1,self.ids),(2,[])]:
   with self.assertRaises(Conflict):self.push(self.a,seq,ids)
 def test_deletion_peak(self):
  self.push(self.a,1,self.ids);r=self.push(self.a,2,[]);self.assertEqual((r['current'],r['peak']),(0,4))
 def test_month_carry(self):
  self.push(self.a,1,self.ids);r=self.push(self.a,2,[], '2026-10-01T00:00:00+00:00');self.assertEqual(r['peak'],4)
 def test_export_grace_period(self):
  t=self.s.provision('g','hub-g',anchor_day=1,now=self.now);self.s.snapshot(t,{'sequence':1,'sites':self.ids},self.now)
  with self.assertRaises(Invalid):self.s.export('g','2026-09-01',now='2026-10-01T00:00:00+00:00')
  with self.assertRaises(Invalid):self.s.export('g','2026-09-01',now='2026-10-03T23:00:00+00:00')
  r=self.s.export('g','2026-09-01',now='2026-10-04T01:00:00+00:00');self.assertEqual(r['unsynced_hubs'],1)
  r=self.s.export('g','2026-09-01',now='2026-10-01T00:00:00+00:00',force=True);self.assertEqual(r['unsynced_hubs'],1)
  self.s.snapshot(t,{'sequence':2,'sites':self.ids},'2026-10-02T00:00:00+00:00')
  r=self.s.export('g','2026-09-01',now='2026-10-02T01:00:00+00:00');self.assertEqual(r['unsynced_hubs'],0)
 def test_anchor_day_period(self):
  t=self.s.provision('c','hub-c',anchor_day=15);ids=[digest('c'+str(i)) for i in range(3)]
  r=self.push(t,1,ids,'2026-09-20T00:00:00+00:00');self.assertEqual((r['month'],r['peak']),('2026-09-15',3))
  r=self.push(t,2,ids[:1],'2026-10-10T00:00:00+00:00');self.assertEqual((r['month'],r['peak']),('2026-09-15',3))
  r=self.push(t,3,ids,'2026-10-16T00:00:00+00:00');self.assertEqual((r['month'],r['peak']),('2026-10-15',3))
 def test_revoke(self):
  with self.assertRaises(Invalid):self.s.revoke('does-not-exist')
  self.push(self.a,1,self.ids)
  self.s.revoke('hub1',self.now)
  with self.assertRaises(Unauthorized):self.s.status(self.a,self.now)
  r=self.s.status(self.b,self.now);self.assertEqual(r['current'],0);self.assertEqual(r['peak'],4)
 def test_suspend(self):
  with self.assertRaises(Invalid):self.s.suspend('does-not-exist')
  with self.assertRaises(Invalid):self.s.unsuspend('does-not-exist')
  self.push(self.a,1,self.ids)
  self.s.suspend('a',self.now)
  with self.assertRaises(Unauthorized):self.s.status(self.a,self.now)
  with self.assertRaises(Unauthorized):self.s.status(self.b,self.now)
  self.assertEqual(self.s.status(self.other,self.now)['current'],0)
  self.s.unsuspend('a');self.assertEqual(self.s.status(self.a,self.now)['current'],4)
 def test_management_token(self):
  with self.assertRaises(Invalid):self.s.issue_management_token('does-not-exist','cus_x')
  with self.s.db() as c:
   with self.assertRaises(Unauthorized):self.s.auth_management(c,'not-a-token')
  token=self.s.issue_management_token('a','cus_first')
  with self.s.db() as c:row=self.s.auth_management(c,token);self.assertEqual(row['stripe_customer'],'cus_first')
  # Re-issuing replaces the previous token; the old one stops working.
  token2=self.s.issue_management_token('a','cus_second')
  with self.s.db() as c:
   with self.assertRaises(Unauthorized):self.s.auth_management(c,token)
   row=self.s.auth_management(c,token2);self.assertEqual(row['stripe_customer'],'cus_second')
 def test_issue_additional_hub(self):
  with self.assertRaises(Invalid):self.s.issue_additional_hub('does-not-exist')
  hub,token=self.s.issue_additional_hub('a')
  self.assertTrue(hub.startswith('hub-'));self.assertTrue(re.fullmatch('[a-f0-9]{64}',token))
  # Inherits the account's existing pricing rather than any caller-supplied value.
  with self.s.db() as c:
   row=c.execute('SELECT base,unit FROM accounts WHERE id=?',('a',)).fetchone()
   self.assertEqual((row['base'],row['unit']),(10000,100))
  self.assertEqual(self.s.status(token,self.now)['current'],0)
  self.s.suspend('a',self.now)
  with self.assertRaises(Invalid):self.s.issue_additional_hub('a')
  self.s.unsuspend('a')
  with self.s.db() as c:
   for i in range(48):c.execute("INSERT INTO hubs(id,account,token_hash) VALUES(?,?,?)",(f'filler-{i}','a',digest(f'filler-{i}')))
  with self.assertRaises(Invalid):self.s.issue_additional_hub('a')
 def test_wal_mode(self):
  with self.s.db() as c:self.assertEqual(c.execute('PRAGMA journal_mode').fetchone()[0].lower(),'wal')
 def test_backup_and_check(self):
  self.push(self.a,1,self.ids);self.assertEqual(self.s.check(),'ok')
  target=str(Path(self.tmp.name)/'backup.db');self.s.backup(target)
  copy=Store(target);self.assertEqual(copy.status(self.a,self.now)['current'],4)
 def test_auth(self):
  with self.assertRaises(Unauthorized):self.s.status('x')
 def test_invalid(self):
  for data in [{'sequence':True,'sites':[]},{'sequence':1,'sites':['x']},{'sequence':1,'sites':self.ids*2},{'sequence':1,'sites':[],'account':'other'}]:
   with self.assertRaises(Invalid):self.s.snapshot(self.a,data,self.now)
 def test_pricing_snapshot(self):
  self.push(self.a,1,self.ids)
  with self.s.db() as c:c.execute("UPDATE accounts SET unit=500 WHERE id='a'")
  self.assertEqual(self.s.status(self.a,self.now)['unit_yen'],100)
 def test_stale_not_deleted(self):
  self.push(self.a,1,self.ids);r=self.s.status(self.a,'2026-09-12T00:00:00+00:00');self.assertEqual(r['current'],4);self.assertGreater(r['stale_hubs'],0);self.assertFalse(r['billable'])
 def test_spending_cap(self):
  with self.assertRaises(Invalid):self.s.set_spending_cap('does-not-exist',5000)
  with self.assertRaises(Invalid):self.s.set_spending_cap('a',-1)
  r=self.push(self.a,1,self.ids);self.assertEqual(r['spending_cap_yen'],0);self.assertFalse(r['over_spending_cap'])
  self.s.set_spending_cap('a',5000)
  r=self.s.status(self.a,self.now);self.assertEqual(r['estimate_yen'],10400);self.assertTrue(r['over_spending_cap'])
  # Over the cap does not block anything: sync keeps working.
  r=self.push(self.a,2,self.ids);self.assertEqual(r['current'],4)
  self.s.set_spending_cap('a',20000)
  self.assertFalse(self.s.status(self.a,self.now)['over_spending_cap'])
 def test_stripe_preview(self):
  self.push(self.a,1,self.ids);r=plan(self.s.export('a','2026-09-01',force=True),'cus_test');self.assertEqual(r['amount'],10400);self.assertEqual(r['auto_advance'],'false')
 def test_stripe_live_rejected(self):
  self.push(self.a,1,[])
  with self.assertRaises(ValueError):send(self.s.export('a','2026-09-01',force=True),'cus_test',self.tmp.name,'sk_live_fake')
 def test_stripe_replay(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09-01',force=True);calls=[]
  def transport(path,data,key,identity):calls.append((path,data,identity));return {'livemode':False,'status':'draft','id':'in_test' if path=='invoices' else 'ii_test'}
  send(u,'cus_test',self.tmp.name,'sk_test_fake',transport);send(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertEqual(len(calls),2);self.assertEqual(calls[1][1]['invoice'],'in_test')
  u['peak']=3;u['amount_yen']=10300
  with self.assertRaises(ValueError):send(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
 def test_stripe_correction(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09-01',force=True)
  def original(path,data,key,identity):return {'livemode':False,'status':'draft','id':'in_orig' if path=='invoices' else 'ii_orig'}
  send(u,'cus_test',self.tmp.name,'sk_test_fake',original)
  corrected=dict(u,peak=3,amount_yen=10300)
  with self.assertRaises(ValueError):send(corrected,'cus_test',self.tmp.name,'sk_test_fake',original)
  with self.assertRaises(ValueError):correct(u,'cus_test',self.tmp.name,'   ')
  archived=correct(u,'cus_test',self.tmp.name,'大阪拠点の重複を除外')
  self.assertEqual(archived['invoice'],'in_orig');self.assertEqual(archived['corrected_reason'],'大阪拠点の重複を除外')
  with self.assertRaises(ValueError):correct(u,'cus_test',self.tmp.name,'二重に訂正しようとした場合')
  def fixed(path,data,key,identity):return {'livemode':False,'status':'draft','id':'in_fixed' if path=='invoices' else 'ii_fixed'}
  r=send(corrected,'cus_test',self.tmp.name,'sk_test_fake',fixed);self.assertEqual(r['invoice'],'in_fixed')
 def test_stripe_refund(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09-01',force=True)
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'まだ何も送っていない')
  def original(path,data,key,identity):return {'livemode':False,'status':'draft','id':'in_orig' if path=='invoices' else 'ii_orig'}
  send(u,'cus_test',self.tmp.name,'sk_test_fake',original)
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'   ')
  refunded=record_refund(u,'cus_test',self.tmp.name,'計算誤りのため全額返金')
  self.assertTrue(refunded['refunded']);self.assertEqual(refunded['refund_amount'],10400);self.assertEqual(refunded['refund_reason'],'計算誤りのため全額返金')
  self.assertEqual(refunded['invoice'],'in_orig')
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'二重に返金しようとした場合')
  # send() after a refund is a no-op replay (already 'complete'); it does not re-create anything.
  r=send(u,'cus_test',self.tmp.name,'sk_test_fake',original);self.assertTrue(r['refunded'])
 def test_stripe_partial_refund(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09-01',force=True)
  def transport(path,data,key,identity):return {'livemode':False,'status':'draft','id':'in_p' if path=='invoices' else 'ii_p'}
  send(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'金額不正','not-an-int')
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'上限超過',10401)
  with self.assertRaises(ValueError):record_refund(u,'cus_test',self.tmp.name,'ゼロ以下',0)
  refunded=record_refund(u,'cus_test',self.tmp.name,'大阪拠点分のみ過大請求',300)
  self.assertEqual((refunded['refund_amount'],refunded['refund_full_amount']),(300,10400))
 def test_stripe_finalize_and_deliver(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09-01',force=True)
  def transport(path,data,key,identity):
   if path=='invoices':return {'livemode':False,'status':'draft','id':'in_test'}
   if path=='invoiceitems':return {'livemode':False,'id':'ii_test'}
   if path=='invoices/in_test/finalize':return {'livemode':False,'status':'open','id':'in_test'}
   if path=='invoices/in_test/send':return {'livemode':False,'status':'open','id':'in_test'}
   raise AssertionError('unexpected path '+path)
  with self.assertRaises(ValueError):finalize(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
  send(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
  with self.assertRaises(ValueError):deliver(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
  with self.assertRaises(ValueError):finalize(u,'cus_test',self.tmp.name,'sk_live_fake',transport)
  r=finalize(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertEqual(r['finalized_status'],'open')
  r=finalize(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertTrue(r['finalized'])
  r=deliver(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertTrue(r['delivered'])
  r=deliver(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertTrue(r['delivered'])
 def test_stripe_event_wiring(self):
  no_meta={'type':'checkout.session.completed','data':{'object':{'metadata':{}}}}
  self.assertEqual(handle_stripe_event(self.s,no_meta),{'action':'skipped','reason':'missing account/hub metadata'})
  checkout={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'newco','hub':'newco-hub1','base':'5000','unit':'50'}}}}
  r=handle_stripe_event(self.s,checkout);self.assertEqual((r['action'],r['account'],r['hub']),('provisioned','newco','newco-hub1'))
  self.assertTrue(re.fullmatch('[a-f0-9]{64}',r['hub_token']))
  r=handle_stripe_event(self.s,checkout);self.assertEqual(r,{'action':'skipped','reason':'hub already provisioned'})
  cancel_no_meta={'type':'customer.subscription.deleted','data':{'object':{'metadata':{}}}}
  self.assertEqual(handle_stripe_event(self.s,cancel_no_meta),{'action':'skipped','reason':'missing account metadata'})
  cancel={'type':'customer.subscription.deleted','data':{'object':{'metadata':{'account':'a'}}}}
  self.assertEqual(handle_stripe_event(self.s,cancel),{'action':'suspended','account':'a'})
  with self.assertRaises(Unauthorized):self.s.status(self.a,self.now)
  paid_no_meta={'type':'invoice.paid','data':{'object':{'metadata':{}}}}
  self.assertEqual(handle_stripe_event(self.s,paid_no_meta),{'action':'skipped','reason':'missing account/month metadata'})
  # account 'a' is currently suspended with reason 'subscription_canceled' (from just above);
  # an unrelated invoice.paid must NOT silently reactivate it.
  paid={'type':'invoice.paid','data':{'object':{'metadata':{'account':'a','month':'2026-09-01'},'amount_paid':10400}}}
  self.assertEqual(handle_stripe_event(self.s,paid),{'action':'payment_recorded','account':'a','month':'2026-09-01','status':'paid','dunning_action':None})
  status=self.s.payment_status('a','2026-09-01');self.assertEqual((status['status'],status['amount']),('paid',10400))
  with self.assertRaises(Unauthorized):self.s.status(self.a,self.now)
  self.s.unsuspend('a')
  failed={'type':'invoice.payment_failed','data':{'object':{'metadata':{'account':'a','month':'2026-09-01'},'amount_due':10400}}}
  self.assertEqual(handle_stripe_event(self.s,failed),{'action':'payment_recorded','account':'a','month':'2026-09-01','status':'failed','dunning_action':'suspended'})
  self.assertEqual(self.s.payment_status('a','2026-09-01')['status'],'failed')
  with self.assertRaises(Unauthorized):self.s.status(self.a,self.now)
  # A later invoice.paid for the same account DOES reverse this auto-suspend (its own doing).
  self.assertEqual(handle_stripe_event(self.s,paid)['dunning_action'],'unsuspended')
  self.s.status(self.a,self.now)  # no longer raises Unauthorized
  self.assertIsNone(self.s.payment_status('a','2026-10-01'))
  other={'type':'invoice.finalized','data':{'object':{}}}
  self.assertEqual(handle_stripe_event(self.s,other),{'action':'recorded'})
 def test_webhook_receipt_recorded_for_contract_check_screen(self):
  # The WordPress "contract check" screen needs proof that a webhook actually arrived for
  # this account, not just that the local DB agrees with whatever Stripe reports live now.
  self.assertEqual(self.s.webhook_state('a'),{'last_webhook_type':None,'last_webhook_at':None})
  checkout={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'newco2','hub':'newco2-hub1'}}}}
  handle_stripe_event(self.s,checkout)
  state=self.s.webhook_state('newco2')
  self.assertEqual(state['last_webhook_type'],'checkout.session.completed')
  self.assertIsNotNone(state['last_webhook_at'])
  cancel={'type':'customer.subscription.deleted','data':{'object':{'metadata':{'account':'a'}}}}
  handle_stripe_event(self.s,cancel)
  self.assertEqual(self.s.webhook_state('a')['last_webhook_type'],'customer.subscription.deleted')
  with self.assertRaises(Invalid):self.s.webhook_state('no-such-account')
 def test_result_reports_overage_sites(self):
  # Site count above STRIPE_INCLUDED_SITES, exposed for the same screen (mirrors what
  # sync_stripe_meter() reports to Stripe's Billing Meter, see test_meter_event_create).
  self.push(self.a,1,self.ids);self.assertEqual(self.s.status(self.a,self.now)['overage_sites'],0)
  os.environ['STRIPE_INCLUDED_SITES']='2'
  try:self.assertEqual(self.s.status(self.a,self.now)['overage_sites'],2)
  finally:del os.environ['STRIPE_INCLUDED_SITES']
 def test_hub_self_upgrade_status_endpoint_reports_overage_amount(self):
  import service as service_module
  self.push(self.a,1,self.ids)
  os.environ['STRIPE_INCLUDED_SITES']='1';os.environ['STRIPE_OVERAGE_PRICE_ID']='price_overage_status';os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
  self.s.set_stripe_subscription('a','sub_stat2','si_stat2',customer_id='cus_stat2')
  def fake_get(path,key):
   if path=='subscriptions/sub_stat2':return {'status':'active'}
   if path=='prices/price_overage_status':return {'unit_amount':300,'currency':'usd'}
   raise AssertionError('unexpected path '+path)
  service_module.stripe_get=fake_get
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/stripe/test-status',headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:r=json.load(res)
  finally:
   server.shutdown();server.server_close();thread.join()
   del os.environ['STRIPE_INCLUDED_SITES'];del os.environ['STRIPE_OVERAGE_PRICE_ID'];del os.environ['STRIPE_TEST_SECRET_KEY']
  self.assertEqual(r['overage_sites'],3)
  self.assertEqual((r['overage_unit_amount'],r['overage_currency'],r['overage_amount']),(300,'usd',900))
 def test_checkout_completed_links_subscription_out_of_order(self):
  # Simulates customer.subscription.created arriving (and being dropped, since the account
  # didn't exist yet) *before* checkout.session.completed -- checkout.session.completed must
  # still end up linking the subscription itself, using the subscription id Checkout already
  # carries, rather than depending on that earlier event having succeeded.
  import service as service_module
  os.environ['STRIPE_OVERAGE_PRICE_ID']='price_overage9'
  service_module.stripe_get=lambda path,key:{'items':{'data':[
    {'id':'si_base9','price':{'id':'price_base9'}},
    {'id':'si_overage9','price':{'id':'price_overage9'}}]}} if path=='subscriptions/sub_outoforder' else (_ for _ in ()).throw(AssertionError('unexpected path '+path))
  os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
  try:
   checkout={'type':'checkout.session.completed','data':{'object':{
     'metadata':{'account':'outoforder','hub':'outoforder-hub1'},
     'customer':'cus_outoforder','subscription':'sub_outoforder'}}}
   r=handle_stripe_event(self.s,checkout)
   self.assertEqual(r['action'],'provisioned')
   self.assertEqual(self.s.stripe_subscription_item('outoforder'),'si_base9')
   self.assertEqual(self.s.stripe_account_state('outoforder'),{'subscription_id':'sub_outoforder','customer_id':'cus_outoforder'})
  finally:
   del os.environ['STRIPE_OVERAGE_PRICE_ID'];del os.environ['STRIPE_TEST_SECRET_KEY']
 def test_checkout_completed_subscription_link_failure_does_not_block_provisioning(self):
  import service as service_module
  def boom(path,key):raise RuntimeError('stripe unreachable')
  service_module.stripe_get=boom
  os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
  try:
   checkout={'type':'checkout.session.completed','data':{'object':{
     'metadata':{'account':'linkfail','hub':'linkfail-hub1'},
     'customer':'cus_linkfail','subscription':'sub_linkfail'}}}
   r=handle_stripe_event(self.s,checkout)
   self.assertEqual(r['action'],'provisioned')
   self.assertIsNone(self.s.stripe_subscription_item('linkfail'))
  finally:del os.environ['STRIPE_TEST_SECRET_KEY']
 def test_stripe_subscription_created_event(self):
  no_meta={'type':'customer.subscription.created','data':{'object':{'id':'sub_test1','items':{'data':[{'id':'si_test1'}]},'metadata':{}}}}
  self.assertEqual(handle_stripe_event(self.s,no_meta),{'action':'skipped','reason':'missing account metadata'})
  no_item={'type':'customer.subscription.created','data':{'object':{'id':'sub_test1','items':{'data':[]},'metadata':{'account':'a'}}}}
  self.assertEqual(handle_stripe_event(self.s,no_item),{'action':'skipped','reason':'missing subscription or item id'})
  ev={'type':'customer.subscription.created','data':{'object':{'id':'sub_test1','items':{'data':[{'id':'si_test1'}]},'metadata':{'account':'a'}}}}
  r=handle_stripe_event(self.s,ev);self.assertEqual(r,{'action':'subscription_linked','account':'a','subscription':'sub_test1'})
  self.assertEqual(self.s.stripe_subscription_item('a'),'si_test1')
  unknown={'type':'customer.subscription.created','data':{'object':{'id':'sub_test2','items':{'data':[{'id':'si_test2'}]},'metadata':{'account':'no-such-account'}}}}
  self.assertEqual(handle_stripe_event(self.s,unknown),{'action':'skipped','reason':'invalid or unknown account/hub in metadata'})
 def test_snapshot_endpoint_reports_current_overage_to_meter(self):
  import service as service_module
  self.s.set_stripe_customer('a','cus_y')
  calls=[];original=service_module.record_meter_event
  service_module.record_meter_event=lambda event,customer,value,key,identifier:calls.append((event,customer,value,key,identifier))
  os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
  os.environ['STRIPE_METER_EVENT_NAME']='managed_sites_overage'
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':1,'sites':self.ids[:2]}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],2)
   self.assertEqual(calls[0][:4],('managed_sites_overage','cus_y',0,'sk_test_fake'))
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':2,'sites':self.ids}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],4)
   self.assertEqual(calls[1][:4],('managed_sites_overage','cus_y',0,'sk_test_fake'))
   # account 'other' has no Stripe customer: reporting is a no-op, not an error.
   req2=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':1,'sites':self.ids[:1]}).encode(),headers={'Authorization':'Bearer '+self.other})
   with urllib.request.urlopen(req2) as res:self.assertEqual(json.load(res)['peak'],1)
   self.assertEqual(len(calls),2)
  finally:
   service_module.record_meter_event=original;del os.environ['STRIPE_TEST_SECRET_KEY'];del os.environ['STRIPE_METER_EVENT_NAME']
   server.shutdown();server.server_close();thread.join()
 def test_snapshot_endpoint_updates_site_quantity(self):
  import service as service_module
  self.s.set_stripe_subscription('a','sub_qty','si_qty',customer_id='cus_qty')
  calls=[];original=service_module.update_subscription_item_quantity
  service_module.update_subscription_item_quantity=lambda item,quantity,key,**kwargs:calls.append((item,quantity,key,kwargs))
  os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake';os.environ['STRIPE_PRICE_ID']='price_site'
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':1,'sites':self.ids[:3]}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],3)
   self.assertEqual(calls,[('si_qty',3,'sk_test_fake',{})])
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':2,'sites':[]}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],0)
   self.assertEqual(calls[-1],('si_qty',1,'sk_test_fake',{}))
  finally:
   service_module.update_subscription_item_quantity=original;del os.environ['STRIPE_TEST_SECRET_KEY'];del os.environ['STRIPE_PRICE_ID']
   server.shutdown();server.server_close();thread.join()
 def test_signup_page(self):
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(url+'/signup')
   self.assertEqual(err.exception.code,404)
   env={'STRIPE_PRICE_ID':'price_abc','SIGNUP_SUCCESS_URL':'https://x/ok','SIGNUP_CANCEL_URL':'https://x/cancel','STRIPE_TEST_SECRET_KEY':'sk_test_fake'}
   for k,v in env.items():os.environ[k]=v
   try:
    with urllib.request.urlopen(url+'/signup') as res:
     self.assertEqual(res.status,200);self.assertIn('text/html',res.headers['Content-Type'])
     body=res.read().decode();self.assertIn('/v1/signup',body);self.assertIn('AutoRepair AI Hosting',body)
   finally:
    for k in env:del os.environ[k]
  finally:server.shutdown();server.server_close();thread.join()
 def test_stripe_event_email_delivery(self):
  import service as service_module
  original=service_module.mailer.send;sent=[]
  service_module.mailer.send=lambda *a,**k:sent.append(a)
  try:
   checkout={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'withmail','hub':'withmail-hub'},'customer_details':{'email':'c@example.com'}}}}
   r=handle_stripe_event(self.s,checkout)
   self.assertTrue(r['email_delivered']);self.assertEqual(sent[0][0],'c@example.com')
  finally:service_module.mailer.send=original
 def test_stripe_event_issues_management_token(self):
  import service as service_module
  original=service_module.mailer.send;sent=[]
  service_module.mailer.send=lambda *a,**k:sent.append(a)
  os.environ['MANAGE_BASE_URL']='https://central.example.com'
  try:
   checkout={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'withportal','hub':'withportal-hub'},'customer':'cus_withportal','customer_email':'p@example.com'}}}
   handle_stripe_event(self.s,checkout)
   body=sent[0][2]
   self.assertIn('https://central.example.com/v1/manage/portal?token=',body)
   with self.s.db() as c:row=self.s.auth_management(c,body.split('token=')[1].split('\n')[0]);self.assertEqual(row['stripe_customer'],'cus_withportal')
   # No 'customer' on the checkout object -> no management token, no manage link, and no crash.
   sent.clear()
   checkout_no_customer={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'noportal','hub':'noportal-hub'},'customer_email':'q@example.com'}}}
   handle_stripe_event(self.s,checkout_no_customer)
   self.assertNotIn('manage/portal',sent[0][2])
  finally:
   service_module.mailer.send=original;del os.environ['MANAGE_BASE_URL']
 def test_stripe_event_email_delivery_failure_does_not_block_provisioning(self):
  import service as service_module
  original=service_module.mailer.send
  def boom(*a,**k):raise RuntimeError('smtp down')
  service_module.mailer.send=boom
  try:
   checkout={'type':'checkout.session.completed','data':{'object':{'metadata':{'account':'failmail','hub':'failmail-hub'},'customer_email':'c2@example.com'}}}
   r=handle_stripe_event(self.s,checkout)
   self.assertEqual(r['action'],'provisioned');self.assertFalse(r['email_delivered'])
   self.assertTrue(re.fullmatch('[a-f0-9]{64}',r['hub_token']))
  finally:service_module.mailer.send=original
 def test_mailer(self):
  with self.assertRaises(ValueError):mailer.send('a@b.com','s','b',config={'host':'','from_addr':''})
  with self.assertRaises(ValueError):mailer.send('not-an-email','s','b',config={'host':'smtp.example.com','from_addr':'x@y.com'})
  calls=[]
  class FakeSMTP:
   def __init__(self,host,port,timeout=None):calls.append(('connect',host,port))
   def __enter__(self):return self
   def __exit__(self,*a):return False
   def starttls(self,context=None):calls.append(('starttls',))
   def login(self,user,password):calls.append(('login',user,password))
   def send_message(self,msg):calls.append(('sent',msg['To'],msg['Subject']))
  config={'host':'smtp.example.com','port':587,'user':'u','password':'p','from_addr':'noreply@example.com'}
  mailer.send('customer@example.com','件名','本文',config=config,smtp_cls=FakeSMTP)
  self.assertEqual(calls[0],('connect','smtp.example.com',587))
  self.assertIn(('login','u','p'),calls)
  self.assertEqual(calls[-1],('sent','customer@example.com','件名'))
  body=mailer.hub_token_email_body('acct1','hub1','tok123')
  self.assertIn('acct1',body);self.assertIn('hub1',body);self.assertIn('tok123',body)
  self.assertNotIn('manage',body)
  with_manage=mailer.hub_token_email_body('acct1','hub1','tok123','https://example.com/v1/manage/portal?token=xyz')
  self.assertIn('https://example.com/v1/manage/portal?token=xyz',with_manage)
 def test_checkout_session_plan(self):
  with self.assertRaises(ValueError):plan_checkout('BAD ACCOUNT','hub1','price_abc','https://x/ok','https://x/cancel')
  with self.assertRaises(ValueError):plan_checkout('newco','hub1','not-a-price','https://x/ok','https://x/cancel')
  with self.assertRaises(ValueError):plan_checkout('newco','hub1','price_abc','http://insecure','https://x/cancel')
  data=plan_checkout('newco','hub1','price_abc','https://x/ok','https://x/cancel',base=5000,unit=50)
  self.assertEqual(data['mode'],'subscription')
  self.assertEqual(data['line_items[0][price]'],'price_abc')
  self.assertEqual(plan_checkout('newco','hub1','price_abc','https://x/ok','https://x/cancel',overage_price_id='price_overage')['line_items[1][price]'],'price_overage')
  self.assertEqual(data['subscription_data[metadata][account]'],'newco')
  self.assertEqual(data['subscription_data[metadata][hub]'],'hub1')
  self.assertEqual(data['metadata[account]'],'newco')
  self.assertEqual(data['metadata[base]'],'5000')
  self.assertNotIn('customer_email',data)
  self.assertEqual(plan_checkout('newco','hub1','price_abc','https://x/ok','https://x/cancel',customer_email='a@b.com')['customer_email'],'a@b.com')
 def test_checkout_session_create(self):
  with self.assertRaises(ValueError):create_checkout_session('newco','hub1','price_abc','https://x/ok','https://x/cancel','sk_live_fake')
  def transport(path,data,key,identity):
   self.assertEqual(path,'checkout/sessions')
   return {'livemode':False,'id':'cs_test_123','url':'https://checkout.stripe.com/pay/cs_test_123'}
  r=create_checkout_session('newco','hub1','price_abc','https://x/ok','https://x/cancel','sk_test_fake',transport=transport)
  self.assertEqual(r,{'id':'cs_test_123','url':'https://checkout.stripe.com/pay/cs_test_123'})
  def live_transport(path,data,key,identity):return {'livemode':True,'id':'cs_live_123','url':'https://checkout.stripe.com/pay/cs_live_123'}
  self.assertEqual(create_checkout_session('newco','hub1','price_abc','https://x/ok','https://x/cancel','sk_live_fake',transport=live_transport,allow_live=True),{'id':'cs_live_123','url':'https://checkout.stripe.com/pay/cs_live_123'})
  def bad_transport(path,data,key,identity):return {'livemode':False,'id':'not-a-cs-id','url':'https://x'}
  with self.assertRaises(ValueError):create_checkout_session('newco','hub1','price_abc','https://x/ok','https://x/cancel','sk_test_fake',transport=bad_transport)
 def test_meter_event_create(self):
  with self.assertRaises(ValueError):record_meter_event('managed_sites_overage','cus_test',1,'sk_live_fake','id_1')
  with self.assertRaises(ValueError):record_meter_event('bad-event','cus_test',1,'sk_test_fake','id_1')
  def transport(path,data,key,identity):
   self.assertEqual(path,'billing/meter_events')
   self.assertEqual(data,{'event_name':'managed_sites_overage','payload[stripe_customer_id]':'cus_test','payload[value]':'3','identifier':'id_1'})
   return {'livemode':False,'event_name':'managed_sites_overage'}
  self.assertEqual(record_meter_event('managed_sites_overage','cus_test',3,'sk_test_fake','id_1',transport),{'identifier':'id_1','value':3})
  self.assertEqual(record_meter_event('managed_sites_overage','cus_test',3,'sk_live_fake','id_1',lambda *a:{'livemode':True,'event_name':'managed_sites_overage'},allow_live=True),{'identifier':'id_1','value':3})
 def test_subscription_item_quantity_update(self):
  with self.assertRaises(ValueError):update_subscription_item_quantity('not-an-item',1,'sk_test_fake')
  with self.assertRaises(ValueError):update_subscription_item_quantity('si_test',0,'sk_test_fake')
  def transport(path,data,key,identity):
   self.assertEqual(path,'subscription_items/si_test');self.assertEqual(data,{'quantity':'3'})
   return {'livemode':False,'id':'si_test','quantity':3}
  self.assertEqual(update_subscription_item_quantity('si_test',3,'sk_test_fake',transport),{'id':'si_test','quantity':3})
 def test_portal_session_create(self):
  with self.assertRaises(ValueError):create_portal_session('cus_test','https://x/return','sk_live_fake')
  with self.assertRaises(ValueError):create_portal_session('not-a-customer','https://x/return','sk_test_fake')
  with self.assertRaises(ValueError):create_portal_session('cus_test','http://insecure','sk_test_fake')
  def transport(path,data,key,identity):
   self.assertEqual(path,'billing_portal/sessions');self.assertEqual(data,{'customer':'cus_test','return_url':'https://x/return'})
   return {'livemode':False,'id':'bps_test_123','url':'https://billing.stripe.com/session/bps_test_123'}
  r=create_portal_session('cus_test','https://x/return','sk_test_fake',transport=transport)
  self.assertEqual(r,{'id':'bps_test_123','url':'https://billing.stripe.com/session/bps_test_123'})
  self.assertEqual(create_portal_session('cus_test','https://x/return','sk_live_fake',lambda *a:{'livemode':True,'id':'bps_live_123','url':'https://billing.stripe.com/session/bps_live_123'},allow_live=True),{'id':'bps_live_123','url':'https://billing.stripe.com/session/bps_live_123'})
  def bad_transport(path,data,key,identity):return {'livemode':False,'id':'not-a-bps-id','url':'https://x'}
  with self.assertRaises(ValueError):create_portal_session('cus_test','https://x/return','sk_test_fake',transport=bad_transport)
 def test_signup_endpoint(self):
  import service as service_module
  original=service_module.create_checkout_session
  service_module.create_checkout_session=lambda *a,**k:{'id':'cs_test_fake','url':'https://checkout.stripe.com/pay/cs_test_fake'}
  env={'STRIPE_PRICE_ID':'price_abc','SIGNUP_SUCCESS_URL':'https://x/ok','SIGNUP_CANCEL_URL':'https://x/cancel','STRIPE_TEST_SECRET_KEY':'sk_test_fake'}
  for k,v in env.items():os.environ[k]=v
  try:
   server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
   url='http://127.0.0.1:'+str(server.server_port)
   try:
    with urllib.request.urlopen(urllib.request.Request(url+'/v1/signup',data=b'{}')) as res:
     self.assertEqual(json.load(res),{'url':'https://checkout.stripe.com/pay/cs_test_fake'})
    bad=urllib.request.Request(url+'/v1/signup',data=json.dumps({'email':'not-an-email'}).encode())
    with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(bad)
    self.assertEqual(err.exception.code,400)
    for _ in range(3):urllib.request.urlopen(urllib.request.Request(url+'/v1/signup',data=b'{}'))
    with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(urllib.request.Request(url+'/v1/signup',data=b'{}'))
    self.assertEqual(err.exception.code,429)
   finally:server.shutdown();server.server_close();thread.join()
   del os.environ['STRIPE_PRICE_ID']
   server2=HTTPServer(('127.0.0.1',0),handler(self.s));thread2=threading.Thread(target=server2.serve_forever,daemon=True);thread2.start()
   try:
    url2='http://127.0.0.1:'+str(server2.server_port)
    with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(urllib.request.Request(url2+'/v1/signup',data=b'{}'))
    self.assertEqual(err.exception.code,404)
   finally:server2.shutdown();server2.server_close();thread2.join()
  finally:
   for k in env:
    if k in os.environ:del os.environ[k]
   service_module.create_checkout_session=original
 def test_manage_portal_endpoint(self):
  import service as service_module, http.client
  original=service_module.create_portal_session
  service_module.create_portal_session=lambda *a,**k:{'id':'bps_test_fake','url':'https://billing.stripe.com/session/bps_test_fake'}
  token=self.s.issue_management_token('a','cus_test')
  env={'PORTAL_RETURN_URL':'https://x/return','STRIPE_TEST_SECRET_KEY':'sk_test_fake'}
  for k,v in env.items():os.environ[k]=v
  try:
   server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
   try:
    conn=http.client.HTTPConnection('127.0.0.1',server.server_port)
    conn.request('GET','/v1/manage/portal?token='+token)
    resp=conn.getresponse();self.assertEqual(resp.status,302)
    self.assertEqual(resp.getheader('Location'),'https://billing.stripe.com/session/bps_test_fake')
    resp.read();conn.close()
    conn=http.client.HTTPConnection('127.0.0.1',server.server_port)
    conn.request('GET','/v1/manage/portal?token=deadbeef')
    resp=conn.getresponse();self.assertEqual(resp.status,401);resp.read();conn.close()
   finally:server.shutdown();server.server_close();thread.join()
   del os.environ['PORTAL_RETURN_URL']
   server2=HTTPServer(('127.0.0.1',0),handler(self.s));thread2=threading.Thread(target=server2.serve_forever,daemon=True);thread2.start()
   try:
    conn=http.client.HTTPConnection('127.0.0.1',server2.server_port)
    conn.request('GET','/v1/manage/portal?token='+token)
    resp=conn.getresponse();self.assertEqual(resp.status,404);resp.read();conn.close()
   finally:server2.shutdown();server2.server_close();thread2.join()
  finally:
   for k in env:
    if k in os.environ:del os.environ[k]
   service_module.create_portal_session=original
 def test_manage_hubs_endpoint(self):
  token=self.s.issue_management_token('a','cus_test')
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/manage/hubs',data=b'',headers={'Authorization':'Bearer '+token},method='POST')
   with urllib.request.urlopen(req) as res:body=json.load(res)
   self.assertTrue(body['hub'].startswith('hub-'));self.assertTrue(re.fullmatch('[a-f0-9]{64}',body['hub_token']))
   self.assertEqual(self.s.status(body['hub_token'],self.now)['current'],0)
   bad=urllib.request.Request(url+'/v1/manage/hubs',data=b'',headers={'Authorization':'Bearer deadbeef'},method='POST')
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(bad)
   self.assertEqual(err.exception.code,401)
  finally:server.shutdown();server.server_close();thread.join()
 def test_manage_spending_cap_endpoint(self):
  token=self.s.issue_management_token('a','cus_test')
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/manage/spending-cap',data=json.dumps({'spending_cap_yen':5000}).encode(),headers={'Authorization':'Bearer '+token},method='POST')
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res),{'account':'a','spending_cap_yen':5000})
   self.push(self.a,1,self.ids);self.assertTrue(self.s.status(self.a,self.now)['over_spending_cap'])
   bad=urllib.request.Request(url+'/v1/manage/spending-cap',data=json.dumps({'spending_cap_yen':'not-an-int'}).encode(),headers={'Authorization':'Bearer '+token},method='POST')
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(bad)
   self.assertEqual(err.exception.code,400)
  finally:server.shutdown();server.server_close();thread.join()
 def test_webhook_signature(self):
  secret='whsec_test123';body=b'{"id":"evt_1","type":"invoice.paid"}';now=1700000000
  def sign(ts,key=secret):return hmac.new(key.encode(),str(ts).encode()+b'.'+body,hashlib.sha256).hexdigest()
  header='t='+str(now)+',v1='+sign(now)
  self.assertEqual(verify_webhook(body,header,secret,now=now)['id'],'evt_1')
  with self.assertRaises(ValueError):verify_webhook(body,header,secret,now=now+301)
  with self.assertRaises(ValueError):verify_webhook(body,'t='+str(now)+',v1=deadbeef',secret,now=now)
  with self.assertRaises(ValueError):verify_webhook(body,'garbage',secret,now=now)
  with self.assertRaises(ValueError):verify_webhook(body,None,secret,now=now)
  with self.assertRaises(ValueError):verify_webhook(body,header,'not-a-whsec-secret',now=now)
  rotated='t='+str(now)+',v1=deadbeef,v1='+sign(now)
  self.assertEqual(verify_webhook(body,rotated,secret,now=now)['id'],'evt_1')
  wrong_secret=sign(now,key='whsec_other')
  with self.assertRaises(ValueError):verify_webhook(body,'t='+str(now)+',v1='+wrong_secret,secret,now=now)
 def test_record_stripe_event(self):
  with self.assertRaises(Invalid):self.s.record_stripe_event({'id':'','type':'invoice.paid'})
  self.assertTrue(self.s.record_stripe_event({'id':'evt_1','type':'invoice.paid'}))
  self.assertFalse(self.s.record_stripe_event({'id':'evt_1','type':'invoice.paid'}))
 def test_http(self):
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(url+'/v1/usage')
   self.assertEqual(err.exception.code,401)
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':1,'sites':self.ids}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],4)
  finally:server.shutdown();server.server_close();thread.join()
 def test_webhook_endpoint(self):
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  os.environ['STRIPE_WEBHOOK_SECRET']='whsec_test123'
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   body=b'{"id":"evt_http_1","type":"customer.created"}'
   now=int(time.time())
   sig=hmac.new(b'whsec_test123',str(now).encode()+b'.'+body,hashlib.sha256).hexdigest()
   headers={'Stripe-Signature':'t='+str(now)+',v1='+sig,'Content-Type':'application/json'}
   req=urllib.request.Request(url+'/v1/stripe/webhook',data=body,headers=headers)
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res),{'type':'customer.created','new':True,'action':'recorded'})
   req=urllib.request.Request(url+'/v1/stripe/webhook',data=body,headers=headers)
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res),{'type':'customer.created','new':False})
   bad=urllib.request.Request(url+'/v1/stripe/webhook',data=body,headers={'Stripe-Signature':'t='+str(now)+',v1=deadbeef'})
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(bad)
   self.assertEqual(err.exception.code,401)
  finally:
   del os.environ['STRIPE_WEBHOOK_SECRET']
   server.shutdown();server.server_close();thread.join()
 def test_stripe_customer_id_recorded_from_subscription_event(self):
  self.assertEqual(self.s.stripe_account_state('a'),{'subscription_id':None,'customer_id':None})
  ev={'type':'customer.subscription.created','data':{'object':{'id':'sub_cust1','customer':'cus_cust1','items':{'data':[{'id':'si_cust1'}]},'metadata':{'account':'a'}}}}
  handle_stripe_event(self.s,ev)
  self.assertEqual(self.s.stripe_account_state('a'),{'subscription_id':'sub_cust1','customer_id':'cus_cust1'})
  with self.assertRaises(Invalid):self.s.set_stripe_subscription('a','sub_x','si_x',customer_id='not-a-customer-id')
 def test_hub_self_upgrade_checkout_endpoint(self):
  import service as service_module
  service_module.create_checkout_session=lambda *a,**k:{'id':'cs_up_fake','url':'https://checkout.stripe.com/pay/cs_up_fake'}
  os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake';os.environ['STRIPE_PRICE_ID']='price_abc'
  try:
   server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
   try:
    url='http://127.0.0.1:'+str(server.server_port)
    req=urllib.request.Request(url+'/v1/stripe/test-checkout',data=json.dumps({'success_url':'https://x/ok','cancel_url':'https://x/cancel'}).encode(),headers={'Authorization':'Bearer '+self.a})
    with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res),{'mode':'test','checkout_url':'https://checkout.stripe.com/pay/cs_up_fake'})
    # An account with an already-active subscription on file must be refused (409), not sold a second one.
    self.s.set_stripe_subscription('a','sub_active1','si_active1',customer_id='cus_active1')
    service_module.stripe_get=lambda path,key:{'status':'active'}
    req2=urllib.request.Request(url+'/v1/stripe/test-checkout',data=json.dumps({'success_url':'https://x/ok','cancel_url':'https://x/cancel'}).encode(),headers={'Authorization':'Bearer '+self.a})
    with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(req2)
    self.assertEqual(err.exception.code,409)
   finally:server.shutdown();server.server_close();thread.join()
  finally:
   del os.environ['STRIPE_TEST_SECRET_KEY'];del os.environ['STRIPE_PRICE_ID']
 def test_hub_self_upgrade_status_endpoint(self):
  import service as service_module
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/stripe/test-status',headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:
    r=json.load(res);self.assertEqual(r['subscription_status'],'none');self.assertFalse(r['cancellation_pending'])
   self.s.set_stripe_subscription('a','sub_stat1','si_stat1',customer_id='cus_stat1')
   os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
   service_module.stripe_get=lambda path,key:{'status':'active','cancel_at_period_end':True,'current_period_end':1999999999}
   try:
    req2=urllib.request.Request(url+'/v1/stripe/test-status',headers={'Authorization':'Bearer '+self.a})
    with urllib.request.urlopen(req2) as res:
     r=json.load(res);self.assertEqual(r,{'mode':'test','subscription_status':'active','updated':r['updated'],'cancellation_pending':True,'cancellation_at':1999999999,'last_webhook_type':None,'last_webhook_at':None,'overage_sites':0,'overage_unit_amount':None,'overage_currency':None,'overage_amount':None})
   finally:del os.environ['STRIPE_TEST_SECRET_KEY']
  finally:server.shutdown();server.server_close();thread.join()
 def test_hub_self_upgrade_portal_endpoint(self):
  import service as service_module
  service_module.create_portal_session=lambda *a,**k:{'id':'bps_up_fake','url':'https://billing.stripe.com/session/bps_up_fake'}
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   req=urllib.request.Request(url+'/v1/stripe/test-portal',data=json.dumps({'return_url':'https://x/return'}).encode(),headers={'Authorization':'Bearer '+self.a})
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(req)  # no Stripe customer on file yet
   self.assertEqual(err.exception.code,400)
   self.s.set_stripe_subscription('a','sub_port1','si_port1',customer_id='cus_port1')
   os.environ['STRIPE_TEST_SECRET_KEY']='sk_test_fake'
   try:
    req2=urllib.request.Request(url+'/v1/stripe/test-portal',data=json.dumps({'return_url':'https://x/return'}).encode(),headers={'Authorization':'Bearer '+self.a})
    with urllib.request.urlopen(req2) as res:self.assertEqual(json.load(res),{'mode':'test','portal_url':'https://billing.stripe.com/session/bps_up_fake'})
   finally:del os.environ['STRIPE_TEST_SECRET_KEY']
  finally:server.shutdown();server.server_close();thread.join()
if __name__=='__main__':unittest.main(verbosity=2)
