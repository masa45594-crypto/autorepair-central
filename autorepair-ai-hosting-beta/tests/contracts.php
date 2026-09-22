<?php
if (PHP_SAPI !== 'cli') { exit; }
// Run with PHP CLI; isolated WP stubs, not a substitute for WordPress integration tests.
define('ABSPATH', __DIR__); define('WPAI_VERSION', '0.3.2');
$options = []; $ssl = true;
function add_action(...$a) {} function register_deactivation_hook(...$a) {}
function add_filter(...$a) {}
function wp_parse_args($a,$b) { return array_merge($b,$a); }
function get_option($k,$default=false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k,$v,...$a) { $GLOBALS['options'][$k]=$v; }
function add_option($k,$v,...$a) { if(array_key_exists($k,$GLOBALS['options']))return false; $GLOBALS['options'][$k]=$v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); }
function is_multisite() { return false; } function is_ssl() { return $GLOBALS['ssl']; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/','',strtolower($s)); }
function wp_salt($s) { return 'isolated-test-salt'; }
function wp_parse_url($s) { return parse_url($s); }
function wp_http_validate_url($s) { return strpos($s,'https://public.example/')===0; }
function esc_url_raw($s) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
class WP_Error { public $code; function __construct($c,...$a) { $this->code=$c; } }
class WP_REST_Response { public $data; function __construct($d) { $this->data=$d; } function header(...$a){} }
class WPAI_Logger {}
class WPAI_Diagnostics {
    function __construct(...$a){}
    function run() { return ['score'=>90,'checks'=>array_map(function($id) { return ['id'=>$id,'status'=>'healthy','message'=>'SECRET /private/path']; }, ['home','rest_api','database','https','disk','filesystem','cron','maintenance','updates','debug_log'])]; }
}
class Request { public $token; function __construct($t) {$this->token=$t;} function get_header($h) {return $this->token;} }
/** Minimal in-memory stand-in for $wpdb, just enough to exercise AAIHB_Beta's site_* queries. Not a substitute for real MySQL. */
class FakeWpdb {
    public $prefix = 'wp_'; public $rows = [];
    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sd]/', function($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string)(int)$v : "'" . addslashes((string)$v) . "'"; }, $sql);
    }
    function get_charset_collate() { return ''; }
    function get_row($sql) { if (preg_match("/WHERE id = '([^']*)'/", $sql, $m)) { return isset($this->rows[$m[1]]) ? (object)array_merge(['id'=>$m[1]], $this->rows[$m[1]]) : null; } return null; }
    function get_var($sql) {
        if (strpos($sql, 'SELECT COUNT(*)') === 0) { return count($this->rows); }
        if (preg_match("/SELECT 1 FROM .*WHERE id = '([^']*)'/", $sql, $m)) { return isset($this->rows[$m[1]]) ? 1 : null; }
        return null;
    }
    function get_results($sql) {
        $rows = $this->rows; uasort($rows, function($a,$b) { return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''); });
        if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $m)) { $rows = array_slice($rows, (int)$m[2], (int)$m[1], true); }
        $out = []; foreach ($rows as $id=>$r) { $out[] = (object)array_merge(['id'=>$id], $r); } return $out;
    }
    function insert($table, $data) { $id = $data['id']; unset($data['id']); $this->rows[$id] = $data; }
    function update($table, $data, $where) { $id = $where['id']; if (isset($this->rows[$id])) { $this->rows[$id] = array_merge($this->rows[$id], $data); } }
    function delete($table, $where) { unset($this->rows[$where['id']]); }
}
$GLOBALS['wpdb'] = new FakeWpdb();
require dirname(__DIR__).'/autorepair-ai-hosting-beta.php';
$count=0;
function check($yes,$label) { global $count; if(!$yes) {throw new Exception('FAIL: '.$label);} $count++; echo 'PASS '.$label."\n"; }
$token=str_repeat('a',64); $request=new Request($token);
check(AAIHB_Beta::authenticate($request) instanceof WP_Error,'disabled Agent rejects token');
update_option(AAIHB_Beta::SETTINGS,['agent'=>1,'hash'=>hash('sha256',$token)]);
check(AAIHB_Beta::authenticate($request)===true,'valid token accepted');
check(AAIHB_Beta::authenticate(new Request(str_repeat('b',64))) instanceof WP_Error,'wrong token rejected');
$ssl=false; check(AAIHB_Beta::authenticate($request) instanceof WP_Error,'HTTP rejected'); $ssl=true;
$r=AAIHB_Beta::agent_scan(); check($r instanceof WP_REST_Response,'read-only scan returns report');
check(strpos(json_encode($r->data),'SECRET')===false,'private details stripped');
check(AAIHB_Beta::validate_report($r->data),'valid response accepted');
$verified=['status'=>'passed','checks'=>['files'=>true,'database'=>true,'homepage'=>true,'wordpress'=>true,'cleanup'=>true],'finished_at'=>gmdate('c')];
$evidence=AAIHB_RecoveryEvidence::score($verified); check($evidence['score']===100 && $evidence['fresh']===true,'signed restore checks produce a fresh 100 recovery score');
$unverified=['status'=>'failed','checks'=>['files'=>true]]; check(AAIHB_RecoveryEvidence::score($unverified)['score']===0,'failed restore test never receives a recovery score');
check(AAIHB_VaultDB::supported_server('8.4.3'),'MySQL 8 is accepted for full backup');
check(AAIHB_VaultDB::supported_server('10.5.26-MariaDB'),'MariaDB 10.5 is accepted for full backup');
check(!AAIHB_VaultDB::supported_server('10.4.33-MariaDB'),'older MariaDB is rejected for full backup');
$matrix=AAIHB_Verification::matrix(['available'=>true,'jobs'=>['central'=>'success','unit'=>'success','restore'=>'success','stripe'=>'skipped','load'=>'skipped']]);$states=array_column($matrix,'state','key');
check($states['install']==='passed'&&$states['upgrade']==='passed'&&$states['rollback']==='passed','verification matrix maps successful install update and rollback evidence');
check($states['stripe']==='pending'&&$states['paddle']==='preparing'&&$states['load']==='pending','verification matrix never marks optional billing or load work as passed when skipped');
$bad=$r->data; $bad['checks'][1]=$bad['checks'][0]; check(!AAIHB_Beta::validate_report($bad),'duplicate check rejected');
$bad=$r->data; $bad['score']=101; check(!AAIHB_Beta::validate_report($bad),'invalid score rejected');
$bad=$r->data; $bad['checks'][0]['status']='html'; check(!AAIHB_Beta::validate_report($bad),'invalid status rejected');
check(AAIHB_Beta::agent_scan() instanceof WP_Error,'rapid repeat scan rejected');
delete_option('aaihb_last_scan'); add_option('aaihb_scan_lock',time());
check(AAIHB_Beta::agent_scan() instanceof WP_Error,'concurrent scan rejected'); delete_option('aaihb_scan_lock');
check(AAIHB_Beta::endpoint('https://public.example/sub/wp-json/aaihb/v1/scan')!==false,'subdirectory endpoint accepted');
check(AAIHB_Beta::endpoint('https://public.example/sub/?rest_route=%2Faaihb%2Fv1%2Fscan')!==false,'plain permalink endpoint accepted');
check(AAIHB_Beta::endpoint('http://public.example/wp-json/aaihb/v1/scan')===false,'HTTP endpoint rejected');
check(AAIHB_Beta::endpoint('https://user:pass@public.example/wp-json/aaihb/v1/scan')===false,'URL credentials rejected');
check(AAIHB_Beta::endpoint('https://public.example/wp-json/aaihb/v1/scan?extra=1')===false,'unexpected query rejected');
if(function_exists('openssl_encrypt')) {
    $enc=AAIHB_Beta::crypt($token); check(AAIHB_Beta::crypt($enc,true)===$token,'encrypted token round trip');
    $bytes=base64_decode($enc); $bytes[20]=chr(ord($bytes[20])^1); $rejected=false;
    try {AAIHB_Beta::crypt(base64_encode($bytes),true);} catch(Throwable $e) {$rejected=true;}
    check($rejected,'tampered encrypted token rejected');
}
check(AAIHB_Beta::site('missing')===null,'unknown site id returns null');
check(AAIHB_Beta::sites_count()===0,'fresh registry is empty');
AAIHB_Beta::site_put('id1',['name'=>'Site One','endpoint'=>'https://a.example/wp-json/aaihb/v1/scan','token'=>'tok1','report'=>null,'error'=>'','attempt'=>'']);
check(AAIHB_Beta::sites_exists('id1'),'inserted site exists');
check(AAIHB_Beta::sites_count()===1,'count reflects one insert');
check(AAIHB_Beta::site('id1')['name']==='Site One','stored site round-trips through the table');
AAIHB_Beta::site_put('id1',['name'=>'Renamed','endpoint'=>'https://a.example/wp-json/aaihb/v1/scan','token'=>'tok1','report'=>['score'=>80,'checks'=>[]],'error'=>'','attempt'=>'2026-01-01T00:00:00Z']);
check(AAIHB_Beta::sites_count()===1,'re-registering the same id updates rather than duplicates');
check(AAIHB_Beta::site('id1')['name']==='Renamed' && AAIHB_Beta::site('id1')['report']['score']===80,'update overwrites fields including nested report JSON');
for ($i=2; $i<=6; $i++) { AAIHB_Beta::site_put('id'.$i,['name'=>'Site '.$i,'endpoint'=>'https://a.example/wp-json/aaihb/v1/scan','token'=>'t','report'=>null,'error'=>'','attempt'=>'']); }
check(AAIHB_Beta::sites_count()===6,'six sites registered');
check(count(AAIHB_Beta::sites())===6,'sites() returns the full registry');
check(count(AAIHB_Beta::sites_page(0,4))===4 && count(AAIHB_Beta::sites_page(4,4))===2,'sites_page paginates without loading everything at once');
AAIHB_Beta::site_delete('id1');
check(!AAIHB_Beta::sites_exists('id1') && AAIHB_Beta::sites_count()===5,'delete removes exactly one row');
try { AAIHB_Billing::assert_registration('brand-new-id'); $ok=true; } catch (Throwable $e) { $ok=false; }
check($ok,'new registration allowed when central is not suspended');
update_option('aaihb_central_suspended',true);
try { AAIHB_Billing::assert_registration('another-new-id'); $blocked=false; } catch (Throwable $e) { $blocked=true; }
check($blocked,'new registration blocked while central reports suspension');
try { AAIHB_Billing::assert_registration('id2'); $existing_ok=true; } catch (Throwable $e) { $existing_ok=false; }
check($existing_ok,'re-registering an already-registered site is still allowed while suspended');
update_option('aaihb_central_suspended',false);
try { AAIHB_Billing::assert_registration('yet-another-new-id'); $ok2=true; } catch (Throwable $e) { $ok2=false; }
check($ok2,'new registration allowed again once suspension clears');
echo $count." tests passed\n";
