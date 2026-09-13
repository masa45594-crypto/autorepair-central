import unittest
from stripe_sandbox import run
class SandboxTests(unittest.TestCase):
 def test_no_key_not_pass(self):self.assertEqual(run('')['status'],'not_configured')
 def test_live_key_never_called(self):
  def forbidden(*a):raise AssertionError('Live key called')
  self.assertEqual(run('sk_live_DO_NOT_USE',forbidden)['status'],'not_configured')
 def test_success_requires_test_mode(self):
  def api(*a):return 200,{'livemode':True,'status':'succeeded','id':'pi_fixture'}
  self.assertEqual(run('sk_test_fixture',api)['status'],'failed')
 def test_all_gateway_checks_required(self):
  answers=iter([(200,{'livemode':False,'status':'succeeded','id':'pi_fixture'}),(200,{'status':'succeeded'}),(402,{'error':{'code':'card_declined','payment_intent':{'livemode':False}}})])
  result=run('sk_test_fixture',lambda *a:next(answers))
  self.assertEqual(result['status'],'passed');self.assertEqual(result['checkout_to_license'],'not_tested')
 def test_refund_failure_fails(self):
  answers=iter([(200,{'livemode':False,'status':'succeeded','id':'pi_fixture'}),(500,{}),(402,{'error':{'code':'card_declined','payment_intent':{'livemode':False}}})])
  self.assertEqual(run('sk_test_fixture',lambda *a:next(answers))['status'],'failed')
if __name__=='__main__':unittest.main()
