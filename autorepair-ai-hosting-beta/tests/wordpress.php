<?php
// Destructive fixtures: ONLY run in a disposable WordPress installation.
if (!defined('AAIHB_TESTING') || !AAIHB_TESTING || !defined('ABSPATH')) { exit; }
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');
AAIHB_Value::init();
AAIHB_Fleet::init();
$n = 0;
function verify($ok, $label) { global $n; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } $n++; echo 'PASS '.$label."\n"; }
function reject($fn, $label) { $failed=false; try {$fn();} catch (RuntimeException $e) {$failed=true;} verify($failed,$label); }
function mutation($op,$data=[]) { $_POST=array_map('strval',array_merge(['op'=>$op],$data)); AAIHB_Value::mutate(gmdate('Y-m')); }
function all_cases() {return get_posts(['post_type'=>'aaihb_case','post_status'=>'private','numberposts'=>-1,'orderby'=>'ID','order'=>'ASC']);}
function last_entry() {$r=AAIHB_Value::entries();return end($r);}
function sums($extra=[]) {return AAIHB_Value::calculate(AAIHB_Value::entries(gmdate('Y-m')),array_merge(['peak'=>1,'hourly'=>3000,'other'=>0],$extra));}
$report=['protocol'=>1,'score'=>100,'generated_at'=>gmdate('c'),'checks'=>array_map(function($id){return ['id'=>$id,'status'=>'healthy'];},['home','rest_api','database','https','disk','filesystem','cron','maintenance','updates','debug_log'])];
$bad=$report; $bad['checks'][0]['status']='critical';
AAIHB_Value::observe('one','テストサイト',$bad,''); verify(count(all_cases())===1,'abnormal report creates case');
$case=all_cases()[0]->ID;
AAIHB_Value::observe('one','テストサイト',$bad,''); verify(count(all_cases())===1,'repeat failure deduplicated');
AAIHB_Value::observe('one','テストサイト',null,'timeout'); verify(count(all_cases())===2,'connection failure creates separate case');
verify(AAIHB_Value::case_data($case)['signal_open'],'failed scan does not mark previous fault healthy');
AAIHB_Value::observe('one','テストサイト',$report,''); verify(!AAIHB_Value::case_data($case)['signal_open'],'healthy observation updates signal');
verify(count(AAIHB_Value::entries())===0,'healthy observation never credits savings');
AAIHB_Value::observe('one','テストサイト',$bad,''); verify(count(all_cases())===3,'recurrence creates new episode');
reject(function()use($case){mutation('close',['case'=>$case,'minutes'=>20,'basis'=>'measured','note'=>'control 20 min']);},'completion requires actual work record');
mutation('work',['case'=>$case,'minutes'=>5,'note'=>'stopwatch 5 min']); $work=last_entry();
mutation('close',['case'=>$case,'minutes'=>20,'basis'=>'measured','note'=>'comparison ticket C1 same scope 20 min']); $close=last_entry();
verify(sums()['strict']===650.0,'20 minus 5 minutes at 3000 yen less 100 equals 650');
reject(function()use($case){mutation('close',['case'=>$case,'minutes'=>20,'basis'=>'measured','note'=>'duplicate']);},'duplicate completion rejected');
reject(function()use($work){mutation('void',['target'=>$work['id'],'reason'=>'correction']);},'cannot remove actual work while comparison is credited');
mutation('void',['target'=>$close['id'],'reason'=>'correct baseline']);
verify(sums()['strict']===-350.0,'reversal removes credit but retains costs');
verify(count(AAIHB_Value::entries())===3,'reversal preserves original ledger');
reject(function()use($close){mutation('void',['target'=>$close['id'],'reason'=>'duplicate']);},'duplicate reversal rejected');
mutation('close',['case'=>$case,'minutes'=>20,'basis'=>'estimate','note'=>'estimate only']);
verify(sums()['strict']===-350.0 && sums()['modeled']===650.0,'estimates excluded from measured comparison');
mutation('work',['case'=>0,'minutes'=>10,'note'=>'false positive and shared overhead']);
verify(sums()['strict']===-850.0,'common work and false positives reduce value');
verify(sums(['other'=>200])['modeled']===-50.0,'other operating costs subtracted');
mutation('routine',['title'=>'対象10サイト・点検比較']); $list=all_cases(); $routine=end($list)->ID;
verify(AAIHB_Value::case_data($routine)['check']==='routine','routine comparison possible without failures');
mutation('work',['case'=>$routine,'minutes'=>1,'note'=>'measured 1 min']);
mutation('close',['case'=>$routine,'minutes'=>99,'basis'=>'none','note'=>'no comparable reference']);
verify(sums()['unknown']===1 && sums()['measured']===0,'missing evidence never credited');
reject(function(){mutation('work',['case'=>0,'minutes'=>-10,'note'=>'bad']);},'negative actual time rejected');
reject(function(){AAIHB_Value::number('1e999');},'nonfinite number rejected');
reject(function(){AAIHB_Value::month('2026-13');},'invalid month rejected');
reject(function(){mutation('work',['case'=>987654321,'minutes'=>1,'note'=>'bad']);},'nonexistent case rejected');
verify(AAIHB_Value::csv_cell("\t=HYPERLINK(\"x\")")[0]==="'",'CSV formula injection neutralized');
verify(AAIHB_Value::csv_cell('通常の記録')==='通常の記録','CSV preserves Japanese text');
AAIHB_Value::meter(['a'=>[],'b'=>[]]); AAIHB_Value::meter([]);
verify(AAIHB_Value::config(gmdate('Y-m'))['peak']===2,'deleting sites does not erase monthly fee basis');
reject(function(){mutation('config',['peak'=>1,'hourly'=>3000,'other'=>0]);},'monthly recorded peak cannot be reduced');
mutation('config',['peak'=>2,'hourly'=>3000,'other'=>0,'confirmed'=>1]);
verify(AAIHB_Value::config(gmdate('Y-m'))['confirmed'],'cost assumptions explicitly confirmed');
mutation('work',['case'=>0,'minutes'=>1,'note'=>'new work']);
verify(!AAIHB_Value::config(gmdate('Y-m'))['confirmed'],'new work invalidates earlier signoff');
verify(!get_post_type_object('aaihb_entry')->show_in_rest && !get_post_type_object('aaihb_case')->public,'private records not exposed in public REST');
mutation('auto',['enabled'=>1]); verify((bool)wp_next_scheduled('aaihb_value_tick'),'cron opt-in schedules event');
mutation('auto',['enabled'=>0]); verify(!wp_next_scheduled('aaihb_value_tick'),'cron opt-out removes event');
$calls=0;
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,$bad){$calls++;return ['headers'=>[],'body'=>wp_json_encode($bad),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];},10,3);
$token=AAIHB_Beta::crypt(str_repeat('a',64));
$site=['name'=>'Scheduled test','endpoint'=>'https://wordpress.org/wp-json/aaihb/v1/scan','token'=>$token,'report'=>null,'error'=>'','attempt'=>''];
AAIHB_Beta::site_put('scheduled1',$site);AAIHB_Beta::site_put('scheduled2',$site);
update_option('aaihb_auto_scan',true);
wp_set_current_user(0); AAIHB_Value::tick();
verify($calls===1,'cron makes at most one remote call per tick');
$scheduled=AAIHB_Beta::sites();
verify($scheduled['scheduled1']['report']!==null && $scheduled['scheduled2']['attempt']==='','cron stores report and leaves next site queued');
verify(count(all_cases())===5,'cron can create private cases without logged-in operator');
AAIHB_Value::tick(); verify($calls===2,'next cron tick advances to next due site');
AAIHB_Value::tick(); verify($calls===2,'fresh sites are not scanned again within one day');
wp_set_current_user(1); mutation('auto',['enabled'=>0]);
$_GET=[]; ob_start(); AAIHB_Value::page(); $html=ob_get_clean();
verify(strpos($html,'判定保留')!==false,'incomplete data displayed as pending');
verify(strpos($html,'実際にかかった時間')!==false && strpos($html,'CSV')!==false,'admin workflow renders with WordPress');
wp_set_current_user(0); $blocked=false;
add_filter('wp_die_handler',function(){return function(){throw new RuntimeException('denied');};});
try { AAIHB_Value::page(); } catch (RuntimeException $e) {$blocked=true;}
verify($blocked,'anonymous user cannot access value dashboard');
echo $n." WordPress integration checks passed\n";
