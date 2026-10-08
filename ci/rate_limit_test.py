#!/usr/bin/env python3
"""Regression test for the central-service rate limiter.

Why this file exists
--------------------
The limiter used to key on the raw socket peer. Behind the platform edge that peer
is the edge proxy, so a burst from one client was spread over many buckets and the
limiter never fired. No CI job covered the limiter, so the defect shipped and was
only found by hand. This script is the missing coverage.

Checks
------
1. client_key() precedence: CF-Connecting-IP, X-Real-IP, right-most X-Forwarded-For,
   socket peer; the left-most X-Forwarded-For entry is ignored (client spoof).
2. One identity bursting 250 requests: exactly 240 served, exactly 10 refused (429).
3. The same burst with a forged left-most X-Forwarded-For on every request: still
   240/10, so a client cannot escape its bucket by rotating that header.
4. Two identities each get their own 240/60s budget.
5. Every refusal is audited as action='rate_limited' with the query string stripped.
6. POST /v1/signup's own budget (the function defaults, 5 requests / 600s) refuses #6.
"""
import sys,tempfile,threading,urllib.error,urllib.request
from concurrent.futures import ThreadPoolExecutor
from http.server import ThreadingHTTPServer
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'central-service'))

import service  # noqa: E402

LIMIT=240
WINDOW=60
BURST=LIMIT+10
WORKERS=25
PROBE='/v1/__rate_probe__'
RESULTS=[]
FAILURES=[]


class Server(ThreadingHTTPServer):
    daemon_threads=True
    request_queue_size=128


def check(name,ok,detail=''):
    print('%s  %s%s'%('PASS' if ok else 'FAIL',name,('   '+detail) if detail else ''))
    RESULTS.append((name,ok))
    if not ok:
        FAILURES.append(name)


def reset():
    service._RATE_HITS.clear()


def start(store):
    server=Server(('127.0.0.1',0),service.handler(store))
    threading.Thread(target=server.serve_forever,daemon=True).start()
    return server


def hit(url,headers=None,timeout=30):
    req=urllib.request.Request(url,headers=headers or {})
    try:
        with urllib.request.urlopen(req,timeout=timeout) as r:
            r.read()
            return r.status
    except urllib.error.HTTPError as e:
        e.read()
        return e.code


def burst(url,n,headers=None):
    """Send n requests. headers is a dict, a callable(i)->dict, or None."""
    codes={}
    errors=[]
    lock=threading.Lock()

    def one(i):
        h=headers(i) if callable(headers) else headers
        try:
            code=hit(url,h)
        except Exception as e:
            with lock:
                errors.append(type(e).__name__+': '+str(e)[:100])
            return
        with lock:
            codes[code]=codes.get(code,0)+1

    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        list(pool.map(one,range(n)))
    return codes,errors


def fmt(codes,errors):
    parts=['%s=%d'%(k,v) for k,v in sorted(codes.items())]
    if errors:
        parts.append('errors=%d (%s)'%(len(errors),errors[0]))
    return ', '.join(parts) or '(no responses)'


def served(codes):
    """Responses that got past the limiter (everything that is not a 429)."""
    return sum(v for k,v in codes.items() if k!=429)


def test_client_key():
    peer='10.0.0.1'
    check('client_key: CF-Connecting-IP is preferred',
          service.client_key({'CF-Connecting-IP':'203.0.113.9','X-Real-IP':'203.0.113.8','X-Forwarded-For':'198.51.100.1'},peer)=='203.0.113.9')
    check('client_key: X-Real-IP when CF-Connecting-IP is absent',
          service.client_key({'X-Real-IP':'203.0.113.8'},peer)=='203.0.113.8')
    check('client_key: right-most X-Forwarded-For entry',
          service.client_key({'X-Forwarded-For':'198.51.100.1, 203.0.113.7'},peer)=='203.0.113.7')
    check('client_key: left-most X-Forwarded-For entry is ignored (spoof)',
          service.client_key({'X-Forwarded-For':'9.9.9.9, 203.0.113.7'},peer)=='203.0.113.7')
    check('client_key: socket peer fallback',
          service.client_key({},peer)==peer)
    check('client_key: blank header values fall through to the peer',
          service.client_key({'CF-Connecting-IP':'  ','X-Forwarded-For':''},peer)==peer)


def test_signup_default_budget():
    reset()
    verdicts=[service.rate_limited('signup-test-key') for _ in range(6)]
    check('POST /v1/signup budget: calls 1-5 allowed, call 6 refused',
          verdicts==[False]*5+[True],str(verdicts))
    check('POST /v1/signup budget defaults are 5 requests / 600s',
          tuple(service.rate_limited.__defaults__ or ())==(5,600),str(service.rate_limited.__defaults__))


def test_single_identity_burst(url,store):
    reset()
    codes,errors=burst(url,BURST)
    check('single identity, 250 requests: no connection errors',not errors,fmt(codes,errors))
    check('single identity, 250 requests: exactly %d served'%LIMIT,served(codes)==LIMIT,fmt(codes,errors))
    check('single identity, 250 requests: exactly %d refused with 429'%(BURST-LIMIT),codes.get(429,0)==BURST-LIMIT,fmt(codes,errors))
    with store.db() as c:
        audited=c.execute("SELECT COUNT(*) FROM audit_log WHERE action='rate_limited'").fetchone()[0]
        leaked=c.execute("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%?%'").fetchone()[0]
    check('every refusal is audited (rate_limited rows = %d)'%(BURST-LIMIT),audited==BURST-LIMIT,'rows=%d'%audited)
    check('audited path carries no query string (redact_path)',leaked==0,'rows_with_query=%d'%leaked)


def test_rotating_leftmost_xff_cannot_escape(url):
    reset()
    codes,errors=burst(url,BURST,headers=lambda i:{'X-Forwarded-For':'198.51.100.%d, 203.0.113.77'%(i%250+1)})
    check('forged left-most XFF on every request: still exactly %d refused'%(BURST-LIMIT),
          codes.get(429,0)==BURST-LIMIT and not errors,fmt(codes,errors))


def test_two_identities_are_independent(url):
    reset()
    ca,ea=burst(url,BURST,{'CF-Connecting-IP':'203.0.113.61'})
    check('identity A: exactly %d refused'%(BURST-LIMIT),ca.get(429,0)==BURST-LIMIT and not ea,fmt(ca,ea))
    cb,eb=burst(url,BURST,{'CF-Connecting-IP':'203.0.113.62'})
    check('identity B: its own budget, exactly %d refused'%(BURST-LIMIT),cb.get(429,0)==BURST-LIMIT and not eb,fmt(cb,eb))


def main():
    tmp=tempfile.TemporaryDirectory()
    store=service.Store(str(Path(tmp.name)/'rate.db'))
    server=start(store)
    url='http://127.0.0.1:%d%s'%(server.server_port,PROBE)
    print('rate limiter regression: window %d/%ds, probe %s'%(LIMIT,WINDOW,PROBE))
    print()
    try:
        probe=hit(url)
        check('probe route answers 404 before the burst',probe==404,'status=%d'%probe)
        test_client_key()
        test_signup_default_budget()
        test_single_identity_burst(url,store)
        test_rotating_leftmost_xff_cannot_escape(url)
        test_two_identities_are_independent(url)
    finally:
        server.shutdown()
        server.server_close()
    print()
    passed=sum(1 for _,ok in RESULTS if ok)
    print('checks: %d passed, %d failed'%(passed,len(RESULTS)-passed))
    if FAILURES:
        print('FAILED: '+'; '.join(FAILURES))
        return 1
    print('OK - the limiter fires per client identity and cannot be evaded via X-Forwarded-For')
    return 0


if __name__=='__main__':
    sys.exit(main())
