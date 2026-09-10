import json,tempfile,unittest,threading,urllib.request,urllib.error
from pathlib import Path
from http.server import HTTPServer
from service import Store,Invalid,Conflict,Unauthorized,handler,digest
from stripe_draft import plan,send
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.s=Store(str(Path(self.tmp.name)/'test.db'));self.a=self.s.provision('a','hub1');self.b=self.s.provision('a','hub2');self.other=self.s.provision('other','hub3');self.now='2026-09-10T00:00:00+00:00';self.ids=[digest(str(i)) for i in range(4)]
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
 def test_stripe_preview(self):
  self.push(self.a,1,self.ids);r=plan(self.s.export('a','2026-09'),'cus_test');self.assertEqual(r['amount'],10400);self.assertEqual(r['auto_advance'],'false')
 def test_stripe_live_rejected(self):
  self.push(self.a,1,[])
  with self.assertRaises(ValueError):send(self.s.export('a','2026-09'),'cus_test',self.tmp.name,'sk_live_fake')
 def test_stripe_replay(self):
  self.push(self.a,1,self.ids);u=self.s.export('a','2026-09');calls=[]
  def transport(path,data,key,identity):calls.append((path,data,identity));return {'livemode':False,'status':'draft','id':'in_test' if path=='invoices' else 'ii_test'}
  send(u,'cus_test',self.tmp.name,'sk_test_fake',transport);send(u,'cus_test',self.tmp.name,'sk_test_fake',transport);self.assertEqual(len(calls),2);self.assertEqual(calls[1][1]['invoice'],'in_test')
  u['peak']=3;u['amount_yen']=10300
  with self.assertRaises(ValueError):send(u,'cus_test',self.tmp.name,'sk_test_fake',transport)
 def test_http(self):
  server=HTTPServer(('127.0.0.1',0),handler(self.s));thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
  try:
   url='http://127.0.0.1:'+str(server.server_port)
   with self.assertRaises(urllib.error.HTTPError) as err:urllib.request.urlopen(url+'/v1/usage')
   self.assertEqual(err.exception.code,401)
   req=urllib.request.Request(url+'/v1/snapshot',data=json.dumps({'sequence':1,'sites':self.ids}).encode(),headers={'Authorization':'Bearer '+self.a})
   with urllib.request.urlopen(req) as res:self.assertEqual(json.load(res)['current'],4)
  finally:server.shutdown();server.server_close();thread.join()
if __name__=='__main__':unittest.main(verbosity=2)
