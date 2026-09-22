<?php
// Run ONLY in a disposable WordPress Playground with AAIHB_TESTING enabled.
if(!defined('AAIHB_TESTING')||!AAIHB_TESTING)exit;
wp_set_current_user(1);$_SERVER['HTTPS']='on';
require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/template.php';
activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');AAIHB_Beta::upgrade();AAIHB_Value::init();AAIHB_Fleet::init();
$n=0;function check_ss($ok,$name){global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}function reject_ss($f,$name){try{$f();}catch(Throwable $e){check_ss(true,$name);return;}check_ss(false,$name);}
// Fixed public DNS for isolated validation; HTTP responses are mocked below.
add_filter('pre_http_request',function($pre,$args,$url){return ['response'=>['code'=>$GLOBALS['ss_status']??200],'headers'=>[],'body'=>wp_json_encode(['protocol'=>1,'score'=>100,'generated_at'=>gmdate('c'),'checks'=>array_map(function($id){return ['id'=>$id,'status'=>'healthy'];},['home','rest_api','database','https','disk','filesystem','cron','maintenance','updates','debug_log'])])];},10,3);
$pack=wp_json_encode(['format'=>'aaihb-connect-1','name'=>'接続テスト','endpoint'=>'https://8.8.8.8/wp-json/aaihb/v1/scan','token'=>str_repeat('a',64)]);
check_ss(AAIHB_Selfserve::parse($pack)['name']==='接続テスト','valid pack');
foreach(['{}','broken',str_repeat('x',4097),str_replace('https:','http:',$pack),str_replace('8.8.8.8','127.0.0.1',$pack),str_replace(str_repeat('a',64),'short',$pack)] as $i=>$bad)reject_ss(function()use($bad){AAIHB_Selfserve::parse($bad);},'invalid pack '.$i);
$r=AAIHB_Selfserve::connect($pack,false);check_ss($r['ok'],'registration and mocked scan');$id=$r['id'];check_ss(AAIHB_Beta::site($id)['token']!==str_repeat('a',64),'stored credential encrypted');check_ss(AAIHB_Beta::sites_count()===1,'one registry entry');
reject_ss(function()use($pack){AAIHB_Selfserve::connect($pack,false);},'duplicate needs explicit replacement');
$GLOBALS['ss_status']=403;$r=AAIHB_Selfserve::connect($pack,true);check_ss(!$r['ok']&&$r['help']==='auth','HTTP 403 maps authentication despite other numbers in message');check_ss(AAIHB_Beta::sites_count()===1,'failed connection retains registration');
foreach([429=>'wait',503=>'dependency',404=>'url',500=>'network'] as $code=>$label)check_ss(AAIHB_Selfserve::category('HTTP '.$code.'：403 429 503')===$label,'error mapping '.$code);
reject_ss(function(){AAIHB_Selfserve::configure('','','',true);},'purchase requires configuration');reject_ss(function(){AAIHB_Selfserve::configure('javascript:alert(1)','','x',true);},'reject unsafe checkout');
function render_ss($page,$file){$_GET=['page'=>$page];ob_start();AAIHB_Selfserve::page();$html=ob_get_clean();file_put_contents('/qa/selfserve-'.$file.'.html',$html);return $html;}
$h=render_ss('aaihb-buy','buy-empty');check_ss(strpos($h,'購入受付は未設定')!==false&&strpos($h,'料金・条件を確認して購入（外部サイト）')===false,'unconfigured purchase has no active checkout');
AAIHB_Selfserve::configure('https://8.8.8.8/checkout','https://8.8.8.8/account','テスト用プラン。実際の販売ではありません。',true);$h=render_ss('aaihb-buy','buy');check_ss(strpos($h,'href="https://8.8.8.8/checkout"')!==false,'configured checkout link');check_ss(strpos($h,'自動同期はありません')!==false,'no fake entitlement');
set_transient('aaihb_pack_1',$pack,120);$h=render_ss('aaihb-start','issued');check_ss(strpos($h,'id="ss-pack"')!==false,'one-time pack displayed');$h=render_ss('aaihb-start','start');check_ss(strpos($h,'id="ss-pack"')===false,'one-time pack consumed');render_ss('aaihb-help','help');
$_SERVER['HTTPS']='off';reject_ss(function()use($pack){AAIHB_Selfserve::connect($pack,true);},'HTTPS required');$_SERVER['HTTPS']='on';
add_filter('wp_die_handler',function(){return function($message){throw new RuntimeException('Denied');};});
wp_set_current_user(wp_create_user('ss_subscriber','test-password-123'));reject_ss(function()use($pack){AAIHB_Selfserve::connect($pack,true);},'subscriber registration denied');reject_ss(function(){AAIHB_Selfserve::configure('','','',false);},'subscriber configuration denied');reject_ss(function(){AAIHB_Selfserve::page();},'subscriber page denied');
wp_set_current_user(1);$_POST=[];$_REQUEST=[];reject_ss(function(){AAIHB_Selfserve::save();},'missing nonce denied');
echo "TOTAL $n checks passed\n";
