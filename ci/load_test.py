"""Load characterization for central-service. Not a pass/fail gate.

service.py ships as a single-threaded http.server.HTTPServer over SQLite --
the README already says so ("試験用の単一処理サーバー"). This script puts
real numbers on that limitation, against the real Store and the real HTTP
handler, no mocks:

  1. baseline latency for /v1/usage and /v1/snapshot at a few site-list sizes
  2. throughput/latency as concurrent HTTP clients increase, against the
     shipped single-threaded server
  3. the same sweep against the underlying SQLite Store directly (bypassing
     HTTP), which is the ceiling you'd hit next if the HTTP layer were made
     concurrent (e.g. ThreadingHTTPServer) -- useful for judging whether that
     change, or a Postgres migration, would actually buy anything
"""
import json
import statistics
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from http.server import HTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'central-service'))
from service import Store, handler, digest  # noqa: E402


def percentile(values, p):
    values = sorted(values)
    k = (len(values) - 1) * p
    f, c = int(k), min(int(k) + 1, len(values) - 1)
    return values[f] if f == c else values[f] + (values[c] - values[f]) * (k - f)


def summarize(latencies_ms):
    return {'n': len(latencies_ms), 'median_ms': round(statistics.median(latencies_ms), 2),
            'p95_ms': round(percentile(latencies_ms, 0.95), 2), 'max_ms': round(max(latencies_ms), 2)}


def timed_get(url, token):
    start = time.perf_counter()
    req = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.urlopen(req, timeout=30):
            pass
        return (time.perf_counter() - start) * 1000, None
    except urllib.error.HTTPError:
        return (time.perf_counter() - start) * 1000, None
    except Exception as error:
        return None, str(error)


def timed_post(url, token, body):
    start = time.perf_counter()
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST',
                                  headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=30):
            pass
        return (time.perf_counter() - start) * 1000, None
    except urllib.error.HTTPError:
        return (time.perf_counter() - start) * 1000, None
    except Exception as error:
        return None, str(error)


def baseline_latency(base, token):
    timed_get(base + '/v1/usage', token)  # warm up the connection/handler before measuring
    out = {}
    for size in (100, 500, 1_000, 20_000):
        sites = [digest('baseline-' + str(size) + '-' + str(i)) for i in range(size)]
        latency, error = timed_post(base + '/v1/snapshot', token, {'sequence': 1, 'sites': sites[:size // 2]})
        latency2, error2 = timed_post(base + '/v1/snapshot', token, {'sequence': 2, 'sites': sites})
        latency3, _ = timed_get(base + '/v1/usage', token)
        out[str(size)] = {'snapshot_ms': round(latency2, 2) if latency2 else None,
                           'usage_ms': round(latency3, 2) if latency3 else None,
                           'error': error or error2}
    return out


def http_concurrency_sweep(base, token, seconds=3):
    out = {}
    for workers in (1, 4, 16, 64):
        stop_at = time.time() + seconds
        latencies, errors = [], 0

        def worker():
            local = []
            while time.time() < stop_at:
                latency, error = timed_get(base + '/v1/usage', token)
                if latency is not None:
                    local.append(latency)
                else:
                    nonlocal_errors.append(error)
            return local

        nonlocal_errors = []
        with ThreadPoolExecutor(max_workers=workers) as pool:
            results = list(pool.map(lambda _: worker(), range(workers)))
        for r in results:
            latencies.extend(r)
        errors = len(nonlocal_errors)
        stats = summarize(latencies) if latencies else {'n': 0}
        stats['requests_per_sec'] = round(stats['n'] / seconds, 1)
        stats['errors'] = errors
        out[str(workers)] = stats
    return out


def store_write_concurrency_sweep(seconds=3):
    """Bypass HTTP entirely: concurrent snapshot() calls on different hubs,
    against the same SQLite file, the way many customers' WP-Cron syncs
    landing at the same moment would look if the HTTP layer weren't serial."""
    out = {}
    for workers in (1, 4, 16, 64):
        with tempfile.TemporaryDirectory() as tmp:
            store = Store(str(Path(tmp) / 'load.sqlite3'))
            tokens = [store.provision('acct' + str(i), 'hub' + str(i)) for i in range(workers)]
            stop_at = time.time() + seconds
            latencies_by_worker = [[] for _ in tokens]
            errors = [0]

            def worker(i):
                seq = 1
                sites = [digest('w' + str(i) + '-' + str(j)) for j in range(50)]
                while time.time() < stop_at:
                    start = time.perf_counter()
                    try:
                        store.snapshot(tokens[i], {'sequence': seq, 'sites': sites})
                        latencies_by_worker[i].append((time.perf_counter() - start) * 1000)
                        seq += 1
                    except Exception:
                        errors[0] += 1

            threads = [threading.Thread(target=worker, args=(i,)) for i in range(workers)]
            for t in threads:
                t.start()
            for t in threads:
                t.join()
            latencies = [v for row in latencies_by_worker for v in row]
            stats = summarize(latencies) if latencies else {'n': 0}
            stats['writes_per_sec'] = round(stats['n'] / seconds, 1)
            stats['lock_errors'] = errors[0]
            out[str(workers)] = stats
    return out


def main():
    with tempfile.TemporaryDirectory() as tmp:
        store = Store(str(Path(tmp) / 'server.sqlite3'))
        token = store.provision('load', 'hub1')
        server = HTTPServer(('127.0.0.1', 0), handler(store))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        base = 'http://127.0.0.1:' + str(server.server_port)
        try:
            report = {
                'scope': 'central_service_load_characterization',
                'note': 'Not pass/fail. Quantifies the single-threaded HTTPServer + SQLite design already flagged as pilot-only in central-service/README-JA.md.',
                'baseline_latency': baseline_latency(base, token),
                'http_concurrency_sweep': http_concurrency_sweep(base, token),
                'store_write_concurrency_sweep_bypassing_http': store_write_concurrency_sweep(),
            }
        finally:
            server.shutdown()
            server.server_close()

    path = ROOT / 'reports'
    path.mkdir(exist_ok=True)
    (path / 'load-test.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
