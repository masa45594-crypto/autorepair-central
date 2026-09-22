"""Local Docker restore rehearsal. No live-site or vault mounts, no published ports.
Result goes to stdout. Docker/SQL/page contents are deliberately not logged.
"""
import hashlib
import json
import os
from pathlib import Path
import secrets
import re
import signal
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

PHP_IMAGE = 'aaihb-restore-test:0.20.5'
MYSQL_IMAGE = 'mysql:8.4'
MARIADB_IMAGE = 'mariadb:10.5'


def command(args, data=None, timeout=120):
    return subprocess.run(['docker', *args], input=data, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=timeout, check=True).stdout


def safe_name(name):
    return bool(name) and not any(c in name for c in ('\\', ':', '\x00')) and all(x not in ('', '.', '..') for x in name.split('/'))


def database_image(manifest):
    """Select only a reviewed disposable database image from backup metadata."""
    version = manifest.get('database_server', '')
    if isinstance(version, str) and 'mariadb' in version.lower():
        if re.search(r'(?:^|[ -])10\.(?:[5-9]|[1-9][0-9])\.', version, re.I):
            return MARIADB_IMAGE
        raise ValueError('unsupported MariaDB version')
    if isinstance(version, str) and re.match(r'^8\.', version):
        return MYSQL_IMAGE
    raise ValueError('unsupported database server')


def extract_verified(archive, target, inventory):
    seen = set()
    total = 0
    if len(inventory) > 50000:
        raise ValueError('inventory limit')
    with zipfile.ZipFile(archive) as z:
        for info in z.infolist():
            name = info.filename.rstrip('/')
            if not safe_name(name) or name in seen or name not in inventory:
                raise ValueError('invalid path')
            spec = inventory[name]
            if ((info.external_attr >> 16) & 0o170000) == 0o120000:
                raise ValueError('symlink')
            seen.add(name)
            dest = target / name
            if spec['dir']:
                if not info.is_dir():
                    raise ValueError('entry type')
                dest.mkdir(parents=True, exist_ok=True)
                continue
            if info.is_dir() or info.file_size != spec['size']:
                raise ValueError('size')
            total += info.file_size
            if total > 2147483648:
                raise ValueError('size limit')
            dest.parent.mkdir(parents=True, exist_ok=True)
            digest = hashlib.sha256()
            with z.open(info) as src, dest.open('xb') as out:
                while True:
                    chunk = src.read(65536)
                    if not chunk:
                        break
                    digest.update(chunk)
                    out.write(chunk)
            if digest.hexdigest() != spec['sha256']:
                raise ValueError('file hash')
            dest.chmod(0o644)  # Clone permissions only; source modes are not mutated.
    if seen != set(inventory):
        raise ValueError('missing entry')


def execute(request):
    checks = {k: False for k in ('files', 'database', 'homepage', 'wordpress', 'cleanup')}
    result = {'nonce': request.get('nonce'), 'status': 'failed', 'stage': 'preflight', 'checks': checks}
    network = request.get('resources', 'aaihb-test-' + secrets.token_hex(8))
    if not re.fullmatch(r'aaihb-test-[a-f0-9]{16}', network):
        return result
    db, php = network + '-db', network + '-php'
    created = []
    volume_created = False
    socket_volume = network + '-socket'
    scratch = None
    try:
        # Require already built/pulled images; do not silently fetch software during a test.
        m = request['manifest']
        db_image = database_image(m)
        try:
            command(['version', '--format', '{{.Server.Version}}'], timeout=15)
            for img in (PHP_IMAGE, db_image):
                command(['image', 'inspect', img], timeout=15)
        except (OSError, subprocess.SubprocessError):
            result['status'] = 'unavailable'
            return result
        source = Path(request['dir']).resolve(strict=True)
        if not re.fullmatch(r'[A-Za-z0-9_]{1,50}', m.get('prefix', '')):
            raise ValueError('prefix')
        if m.get('kind') != 'full' or not all(k in m['payloads'] for k in ('files.zip', 'database.jsonl')):
            raise ValueError('full snapshot required')
        for name in ('files.zip', 'database.jsonl'):
            with (source / name).open('rb') as payload:
                actual_hash = hashlib.file_digest(payload, 'sha256').hexdigest()
            if actual_hash != m['payloads'][name]:
                raise ValueError('payload hash')
        scratch = Path(tempfile.mkdtemp(prefix='aaihb-restore-'))
        site, inputs = scratch / 'site', scratch / 'input'
        site.mkdir(); inputs.mkdir()
        result['stage'] = 'files'
        extract_verified(source / 'files.zip', site, m['files'])
        checks['files'] = True
        # Never execute the source credentials. All replacements happen before PHP starts.
        password = secrets.token_hex(24)
        (site / 'wp-config.php').write_text("<?php\ndefine('DB_NAME','rehearsal');\ndefine('DB_USER','rehearsal');\ndefine('DB_PASSWORD',getenv('TEST_DB_PASSWORD'));\ndefine('DB_HOST','localhost:/socket/mysqld.sock');\ndefine('DB_CHARSET','utf8mb4');\ndefine('DB_COLLATE','');\n$table_prefix=" + repr(m['prefix']) + ";\ndefine('WP_HOME','http://127.0.0.1:8080');define('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('DISALLOW_FILE_MODS',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ENVIRONMENT_TYPE','staging');\ndefine('AUTH_KEY','" + secrets.token_hex(32) + "');\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';\n")
        # Old drop-ins can retain production DB/cache destinations. Disable in this rehearsal.
        for name in ('db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php'):
            path = site / 'wp-content' / name
            if path.is_file():
                path.unlink()
        mu = site / 'wp-content' / 'mu-plugins'
        if mu.exists():
            shutil.rmtree(mu)
        mu.mkdir(parents=True)
        (mu / 'aaihb-test.php').write_text("<?php\nif(('https'===($_SERVER['HTTP_X_FORWARDED_PROTO']??''))){$_SERVER['HTTPS']='on';}\nadd_filter('pre_wp_mail',function($null,$atts){update_option('aaihb_test_last_mail_subject',(string)($atts['subject']??''),false);return true;},10,2);\nadd_filter('wpcf7_skip_spam_check','__return_true');\nadd_action('shutdown',function(){echo \"\\n<!-- AAIHB-Rehearsal: \".htmlspecialchars(getenv('TEST_NONCE'),ENT_QUOTES,'UTF-8').\" -->\";});\n")
        shutil.copyfile(source / 'database.jsonl', inputs / 'database.jsonl')
        (inputs / 'manifest.json').write_text(json.dumps(m))
        helper = Path(__file__).parent
        shutil.copyfile(helper / 'import.php', inputs / 'import.php')
        shutil.copyfile(helper.parent / 'vault-db.php', inputs / 'vault-db.php')
        (inputs / 'probe.php').write_text("""<?php
$ctx=stream_context_create(array('http'=>array('timeout'=>30,'follow_location'=>0,'ignore_errors'=>true)));
$b=@file_get_contents('http://127.0.0.1:8080/',false,$ctx);
$h=isset($http_response_header) ? $http_response_header : array();
$status=0;
$match=array();
if(isset($h[0]) && preg_match('~^HTTP/\\S+ ([0-9][0-9][0-9])(?: |$)~',$h[0],$match)===1){$status=intval($match[1]);}
$has_body=is_string($b);
$bytes=$has_body ? strlen($b) : 0;
$html=$has_body && $bytes>100 && preg_match('/<(?:html|body)\\b/i',$b)===1;
$marker='<!-- AAIHB-Rehearsal: '.htmlspecialchars(getenv('TEST_NONCE'),ENT_QUOTES,'UTF-8').' -->';
$proof=$has_body && strpos($b,$marker)!==false;
echo json_encode(array('homepage'=>$status===200&&$html,'wordpress'=>$proof,'http_status'=>$status,'body_bytes'=>$bytes,'html'=>$html,'marker'=>$proof,'install_page'=>$has_body&&strpos($b,'Installation')!==false,'critical_error'=>$has_body&&strpos($b,'critical error')!==false));
""")
        result['stage'] = 'isolation'
        command(['volume', 'create', '--label', 'aaihb.rehearsal=1', socket_volume])
        volume_created = True
        root_password = secrets.token_hex(24)
        # Both containers have network=none; MySQL uses a private Unix socket volume.
        # --mount only exposes new disposable copies. Neither the live path nor vault is exposed.
        created.append(db)
        command(['run', '-d', '--pull=never', '--name', db, '--network', 'none',
                 '--memory=1g', '--cpus=1', '--pids-limit=256', '--label', 'aaihb.rehearsal=1',
                 '-e', 'MYSQL_ROOT_PASSWORD=' + root_password, '-e', 'MYSQL_DATABASE=rehearsal',
                 '-e', 'MYSQL_USER=rehearsal', '-e', 'MYSQL_PASSWORD=' + password,
                 '--tmpfs', '/var/lib/mysql:rw,size=4g', '--mount', 'type=volume,src=' + socket_volume + ',dst=/var/run/mysqld', db_image])
        result['stage'] = 'database_start'
        for attempt in range(60):
            try:
                command(['exec', '-e', 'MYSQL_PWD=' + password, db, 'mysql', '-urehearsal', '-N', '-e', 'SELECT 1', 'rehearsal'], timeout=5)
                break
            except subprocess.SubprocessError:
                time.sleep(1)
        else:
            raise RuntimeError('database readiness timeout')
        created.append(php)
        command(['run', '-d', '--pull=never', '--name', php, '--network', 'none',
                 '--user', str(os.getuid()) + ':' + str(os.getgid()),
                 '--memory=512m', '--cpus=1', '--pids-limit=128', '--cap-drop=ALL',
                 '--security-opt=no-new-privileges', '--read-only', '--tmpfs', '/tmp:rw,noexec,size=128m',
                 '--label', 'aaihb.rehearsal=1', '--mount', 'type=bind,src=' + str(site) + ',dst=/site',
                 '--mount', 'type=bind,src=' + str(inputs) + ',dst=/input,readonly',
                 '--mount', 'type=volume,src=' + socket_volume + ',dst=/socket,readonly', '-e', 'TEST_DB_PASSWORD=' + password,
                 '-e', 'TEST_NONCE=' + request['nonce'], PHP_IMAGE])
        result['stage'] = 'database'
        output = command(['exec', php, 'php', '/input/import.php'], timeout=600)
        proof = json.loads(output)
        if proof != {'database': True}:
            raise RuntimeError('database not verified')
        checks['database'] = True
        result['stage'] = 'homepage'
        # The PHP development server and WordPress can need a few moments to
        # become ready on a cold CI runner. Keep the proof strict, but wait for
        # the same 200/HTML/MU-plugin conditions instead of sampling only once.
        page = {'homepage': False, 'wordpress': False}
        for _ in range(30):
            page = json.loads(command(['exec', php, 'php', '/input/probe.php'], timeout=45))
            if page.get('homepage') is True and page.get('wordpress') is True:
                break
            time.sleep(1)
        checks['homepage'] = page.get('homepage') is True
        checks['wordpress'] = page.get('wordpress') is True
        if not checks['homepage'] or not checks['wordpress']:
            result['page_evidence'] = {k: page.get(k) for k in ('http_status', 'body_bytes', 'html', 'marker', 'install_page', 'critical_error')}
            raise RuntimeError('page proof failed')
        result['stage'] = 'complete'
        result['status'] = 'passed'
    except Exception as error:
        # Never emit command arguments, SQL, secrets, or source paths in the report.
        result['status'] = 'failed'
        result['failure_type'] = type(error).__name__
        result['failure_returncode'] = getattr(error, 'returncode', None)
        raw_error = getattr(error, 'stderr', b'') or b''
        if isinstance(raw_error, bytes):
            raw_error = raw_error.decode('utf-8', 'replace')
        failure_class = 'other'
        for label, phrase in (('php_parse', 'Parse error'), ('php_syntax', 'syntax error'),
                              ('php_fatal', 'Fatal error'), ('memory', 'Allowed memory size'),
                              ('connection', 'Connection refused'), ('timeout', 'timed out')):
            if phrase.lower() in raw_error.lower():
                failure_class = label
                break
        result['failure_class'] = failure_class
        if result.get('stage') == 'homepage' and php in created:
            runtime = {'container': 'unknown', 'php_cli': False}
            try:
                state = command(['inspect', '--format', '{{.State.Status}}|{{.State.ExitCode}}', php], timeout=10).decode().strip().split('|', 1)
                runtime['container'] = state[0] if state else 'unknown'
                runtime['exit_code'] = int(state[1]) if len(state) == 2 and state[1].isdigit() else None
                runtime['php_cli'] = json.loads(command(['exec', php, 'php', '-r', 'echo json_encode(["ok"=>true]);'], timeout=10)).get('ok') is True
            except Exception:
                pass
            result['runtime_evidence'] = runtime
    finally:
        cleaned = True
        for container in reversed(created):
            try:
                command(['rm', '-f', '-v', container], timeout=30)
            except Exception:
                cleaned = False
        if volume_created:
            try:
                command(['volume', 'rm', socket_volume], timeout=30)
            except Exception:
                cleaned = False
        if scratch:
            try:
                shutil.rmtree(scratch)
            except Exception:
                cleaned = False
        checks['cleanup'] = cleaned
        if not cleaned:
            result['status'] = 'failed'
            result['stage'] = 'cleanup'
    return result


if __name__ == '__main__':
    def interrupted(signum, frame):
        raise RuntimeError('interrupted')
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        request = json.load(sys.stdin)
        print(json.dumps(execute(request)))
    except Exception:
        print(json.dumps({'status': 'failed', 'stage': 'input'}))
        sys.exit(1)
