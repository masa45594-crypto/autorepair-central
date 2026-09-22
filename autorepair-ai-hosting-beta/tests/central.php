<?php
if(!defined('AAIHB_TESTING')||!AAIHB_TESTING)exit;
require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/template.php';wp_set_current_user(1);$_SERVER['HTTPS']='on';activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');AAIHB_Beta::upgrade();AAIHB_Value::init();AAIHB_Fleet::init();
$n=0;function ccheck($ok,$label){global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
ccheck(!function_exists('aaihb_commerce_fs'),'fixed-tier SDK bootstrap removed');
update_option(AAIHB_Central::OPT,['url'=>'https://8.8.8.8','token'=>AAIHB_Beta::crypt(str_repeat('a',64)),'error'=>'','result'=>[]]);
$id=hash('sha256','https://8.8.8.8/wp-json/aaihb/v1/scan');AAIHB_Beta::site_put($id,['name'=>'test','endpoint'=>'https://8.8.8.8/wp-json/aaihb/v1/scan','token'=>AAIHB_Beta::crypt(str_repeat('b',64)),'report'=>null,'error'=>'','attempt'=>'']);
AAIHB_Central::enqueue();$q=get_option('aaihb_central_queue');ccheck(count($q)===1&&$q[0]['sequence']===1,'initial snapshot queued');ccheck($q[0]['sites']===[$id],'only hashed IDs sent');
AAIHB_Central::enqueue();ccheck(count(get_option('aaihb_central_queue'))===1,'identical pending snapshot deduplicated');
$GLOBALS['central_failure']=true;
add_filter('pre_http_request',function($pre,$args,$url){
 if($GLOBALS['central_failure'])return new WP_Error('timeout');
 $body=isset($args['body'])?json_decode($args['body'],true):['sequence'=>2];
 return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['mode'=>'pilot','billable'=>false,'sequence'=>$body['sequence'],'current'=>1,'peak'=>2,'base_yen'=>10000,'unit_yen'=>100,'estimate_yen'=>10200,'stale_hubs'=>0,'observed_at'=>gmdate('c')])];
},10,3);
AAIHB_Central::sync();ccheck(count(get_option('aaihb_central_queue'))===1,'network failure retains snapshot');ccheck(AAIHB_Central::settings()['error']!=='','failure visible');
AAIHB_Beta::site_delete($id);AAIHB_Central::enqueue();ccheck(count(get_option('aaihb_central_queue'))===2,'offline removal queued after addition');
$GLOBALS['central_failure']=false;AAIHB_Central::sync();$q=get_option('aaihb_central_queue');ccheck(count($q)===1&&$q[0]['sequence']===2,'ack removes only oldest snapshot');
AAIHB_Central::sync();ccheck(count(get_option('aaihb_central_queue'))===0,'queue drained');ccheck(AAIHB_Central::settings()['result']['estimate_yen']===10200,'central estimate shown');
add_option('aaihb_central_lock',time(),'',false);AAIHB_Central::enqueue();ccheck(get_option('aaihb_central_gap')===true,'contention leaves persistent reconciliation flag');delete_option('aaihb_central_lock');
ccheck(AAIHB_Billing::scan_error()===null,'pilot does not enforce old fixed tier');
$id2=hash('sha256','https://8.8.8.8/wp-json/aaihb/v1/scan2');AAIHB_Beta::site_put($id2,['name'=>'test2','endpoint'=>'https://8.8.8.8/wp-json/aaihb/v1/scan2','token'=>AAIHB_Beta::crypt(str_repeat('c',64)),'report'=>null,'error'=>'','attempt'=>'']);
AAIHB_Central::enqueue();
add_filter('pre_http_request',function(){return ['response'=>['code'=>401],'headers'=>[],'body'=>wp_json_encode(['error'=>'unauthorized'])];},20,3);
AAIHB_Central::sync();ccheck(get_option('aaihb_central_suspended')===true,'401 from central marks the account suspended');
try{AAIHB_Billing::assert_registration('brand-new');ccheck(false,'new registration blocked while suspended');}catch(RuntimeException $e){ccheck(true,'new registration blocked while suspended');}
AAIHB_Billing::assert_registration($id2);ccheck(true,'existing site still manageable while suspended');
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url){$body=isset($args['body'])?json_decode($args['body'],true):['sequence'=>3];return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['mode'=>'pilot','billable'=>false,'sequence'=>$body['sequence'],'current'=>1,'peak'=>2,'base_yen'=>10000,'unit_yen'=>100,'estimate_yen'=>10200,'stale_hubs'=>0,'observed_at'=>gmdate('c')])];},10,3);
AAIHB_Central::sync();ccheck(get_option('aaihb_central_suspended')===false,'suspension clears once central accepts the account again');
try{AAIHB_Billing::assert_registration('brand-new-2');ccheck(true,'new registration allowed again');}catch(RuntimeException $e){ccheck(false,'new registration allowed again');}
ob_start();AAIHB_Central::page();$html=ob_get_clean();file_put_contents('/qa/usage-central.html',$html);ccheck(strpos($html,str_repeat('a',64))===false,'credential absent from HTML');
add_filter('wp_die_handler',function(){return function(){throw new RuntimeException('Denied');};});wp_set_current_user(wp_create_user('central_reader','test-only-password'));try{AAIHB_Central::page();ccheck(false,'role denied');}catch(RuntimeException $e){ccheck(true,'role denied');}
echo "TOTAL $n checks passed\n";
