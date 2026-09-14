"""Real Stripe test-mode acceptance for central-service's own Stripe HTTP routes.

stripe_sandbox.py only exercises the raw Stripe API. This drives service.py's
dispatch() over real HTTP with a hub Bearer token -- the same way the
WordPress plugin (central.php) calls it -- against the real Stripe test API.
No mocks: the checkout session, customer, payment method and subscription are
all created for real in Stripe test mode.
"""
import hashlib
import hmac
import json
import os
import secrets
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import HTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'central-service'))


def stripe_api(method, path, key, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    headers = {'Authorization': 'Bearer ' + key}
    if body is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    req = urllib.request.Request('https://api.stripe.com/v1/' + path, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read())


def call(url, headers=None, body=None, method='GET'):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            return response.status, json.loads(response.read() or b'{}')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'{}')


def sign(secret, payload):
    stamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), stamp.encode() + b'.' + payload, hashlib.sha256).hexdigest()
    return 't=' + stamp + ',v1=' + signature


def run(key):
    result = {'scope': 'central_service_stripe_http', 'status': 'not_configured', 'checks': {}}
    if not key or not key.startswith('sk_test_'):
        return result
    result['status'] = 'failed'
    admin_token = secrets.token_hex(16)
    webhook_secret = 'whsec_' + secrets.token_hex(16)
    os.environ['STRIPE_SECRET_KEY'] = key
    os.environ['STRIPE_WEBHOOK_SECRET'] = webhook_secret
    os.environ['ADMIN_TOKEN'] = admin_token
    subscription_id = None
    server = None
    try:
        # A disposable test-mode product/price, so this test needs nothing
        # pre-configured in the Stripe dashboard beyond the secret key.
        _, product = stripe_api('POST', 'products', key, {'name': 'AAIHB CI ' + secrets.token_hex(4)})
        _, price = stripe_api('POST', 'prices', key, {'product': product['id'], 'unit_amount': '100', 'currency': 'jpy', 'recurring[interval]': 'month'})
        os.environ['STRIPE_PRICE_ID'] = price['id']

        from service import Store, handler  # env vars above must be set first
        with tempfile.TemporaryDirectory() as tmp:
            store = Store(str(Path(tmp) / 'test.db'))
            token = store.provision('a', 'hub1')
            server = HTTPServer(('127.0.0.1', 0), handler(store))
            threading.Thread(target=server.serve_forever, daemon=True).start()
            base = 'http://127.0.0.1:' + str(server.server_port)
            auth = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'}

            code, body = call(base + '/v1/stripe/test-checkout', auth, {'success_url': 'https://example.com/ok', 'cancel_url': 'https://example.com/cancel'}, 'POST')
            checkout_url = body.get('checkout_url', '')
            result['checks']['checkout_session'] = code == 200 and body.get('mode') == 'test' and checkout_url.startswith('https://checkout.stripe.com/')

            # A hosted Checkout page can't be completed headlessly. Create the
            # subscription directly against the real Stripe test API instead,
            # the same state a completed Checkout would leave behind.
            _, customer = stripe_api('POST', 'customers', key, {})
            _, pm = stripe_api('POST', 'payment_methods', key, {'type': 'card', 'card[token]': 'tok_visa'})
            stripe_api('POST', 'payment_methods/' + pm['id'] + '/attach', key, {'customer': customer['id']})
            stripe_api('POST', 'customers/' + customer['id'], key, {'invoice_settings[default_payment_method]': pm['id']})
            code, subscription = stripe_api('POST', 'subscriptions', key, {'customer': customer['id'], 'items[0][price]': price['id'], 'metadata[account]': 'a', 'payment_behavior': 'error_if_incomplete'})
            subscription_id = subscription.get('id')
            result['checks']['subscription_created'] = code == 200 and subscription.get('status') == 'active'

            event = json.dumps({'type': 'customer.subscription.updated', 'data': {'object': subscription}}).encode()
            code, body = call(base + '/v1/stripe/webhook', {'Stripe-Signature': sign(webhook_secret, event)}, None, 'POST')
            result['checks']['webhook_accepted'] = code == 200
            code, body = call(base + '/v1/stripe/webhook', {'Stripe-Signature': sign('whsec_wrong', event)}, None, 'POST')
            result['checks']['webhook_bad_signature_rejected'] = code == 401

            code, body = call(base + '/v1/stripe/test-status', auth)
            result['checks']['status_reflects_active'] = code == 200 and body.get('mode') == 'test' and body.get('subscription_status') == 'active'

            code, body = call(base + '/v1/stripe/test-portal', auth, {'return_url': 'https://example.com/account'}, 'POST')
            result['checks']['portal_session'] = code == 200 and body.get('mode') == 'test' and str(body.get('portal_url', '')).startswith('https://billing.stripe.com/')

            sites = [hashlib.sha256(str(i).encode()).hexdigest() for i in range(3)]
            call(base + '/v1/snapshot', auth, {'sequence': 1, 'sites': sites}, 'POST')
            admin = {'X-Admin-Token': admin_token, 'Content-Type': 'application/json'}
            code, body = call(base + '/v1/admin/stripe/sync', admin, {'account': 'a'}, 'POST')
            result['checks']['usage_synced_to_subscription_quantity'] = code == 200 and body.get('peak') == 3 and body.get('quantity') == 3

            result['status'] = 'passed' if all(result['checks'].values()) else 'failed'
    except Exception as error:
        result['checks']['error'] = str(error)
    finally:
        if server is not None:
            server.shutdown()
            server.server_close()
        if subscription_id:
            try:
                stripe_api('DELETE', 'subscriptions/' + subscription_id, key)
            except Exception:
                pass
    return result


if __name__ == '__main__':
    outcome = run(os.environ.get('STRIPE_TEST_SECRET_KEY', ''))
    path = ROOT / 'reports'
    path.mkdir(exist_ok=True)
    (path / 'stripe-central-service.json').write_text(json.dumps(outcome, indent=2) + '\n')
    print(json.dumps({'status': outcome['status'], 'scope': outcome['scope']}))
    sys.exit(0 if outcome['status'] == 'passed' else 1)
