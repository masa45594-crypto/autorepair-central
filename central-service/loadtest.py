"""Standard-library-only load test for service.py. Not shipped to production;
a local diagnostic to measure throughput/latency before choosing what (if anything)
to change. Runs the server in-process against a temporary database."""
import argparse, json, statistics, tempfile, threading, time, urllib.error, urllib.request
from concurrent.futures import ThreadPoolExecutor
from http.server import HTTPServer, ThreadingHTTPServer
from pathlib import Path
from service import Store, digest, handler

def run(hubs, requests_per_hub, workers, threaded):
    tmp = tempfile.TemporaryDirectory()
    try:
        store = Store(str(Path(tmp.name) / 'loadtest.sqlite3'))
        tokens = [store.provision('load', f'hub{i}', now='2026-09-01T00:00:00+00:00') for i in range(hubs)]
        server_cls = ThreadingHTTPServer if threaded else HTTPServer
        server = server_cls(('127.0.0.1', 0), handler(store))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        url = f'http://127.0.0.1:{server.server_port}'
        latencies = []
        error_kinds = {}
        lock = threading.Lock()

        def one_request(token, seq, site):
            body = json.dumps({'sequence': seq, 'sites': [site]}).encode()
            req = urllib.request.Request(
                f'{url}/v1/snapshot', data=body,
                headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'},
            )
            start = time.perf_counter()
            try:
                with urllib.request.urlopen(req, timeout=10) as r:
                    r.read()
                with lock:
                    latencies.append(time.perf_counter() - start)
            except urllib.error.HTTPError as e:
                with lock:
                    error_kinds[f'http_{e.code}'] = error_kinds.get(f'http_{e.code}', 0) + 1
            except Exception as e:
                with lock:
                    key = f'{type(e).__name__}:{getattr(e, "reason", e)}'
                    error_kinds[key] = error_kinds.get(key, 0) + 1

        def hub_worker(hub_index):
            # Each hub's own WP-Cron submits its snapshots one at a time, in order;
            # only different hubs run concurrently with each other.
            token = tokens[hub_index]
            for n in range(1, requests_per_hub + 1):
                one_request(token, n, digest(f'hub{hub_index}-site-{n}'))

        total = hubs * requests_per_hub
        started = time.perf_counter()
        with ThreadPoolExecutor(max_workers=min(workers, hubs)) as pool:
            list(pool.map(hub_worker, range(hubs)))
        elapsed = time.perf_counter() - started
        errors = sum(error_kinds.values())

        server.shutdown()
        server.server_close()
        thread.join()

        latencies.sort()
        def pct(p):
            if not latencies:
                return float('nan')
            idx = min(len(latencies) - 1, int(len(latencies) * p))
            return latencies[idx] * 1000
        print(json.dumps({
            'server': type(server).__name__,
            'hubs': hubs, 'total_requests': total, 'concurrency': workers,
            'elapsed_s': round(elapsed, 3),
            'req_per_s': round(total / elapsed, 1),
            'errors': errors, 'error_kinds': error_kinds,
            'p50_ms': round(pct(0.50), 1), 'p95_ms': round(pct(0.95), 1), 'p99_ms': round(pct(0.99), 1),
        }, indent=2))
    finally:
        tmp.cleanup()

if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--hubs', type=int, default=20, help='distinct hubs sending snapshots, e.g. a customer with many WordPress sites managed as separate hubs')
    p.add_argument('--requests-per-hub', type=int, default=25)
    p.add_argument('--concurrency', type=int, default=20, help='concurrent client threads (simulates simultaneous WP-Cron ticks from different hubs)')
    p.add_argument('--threaded', action='store_true', help='use ThreadingHTTPServer instead of the single-threaded default')
    a = p.parse_args()
    run(a.hubs, a.requests_per_hub, a.concurrency, a.threaded)
