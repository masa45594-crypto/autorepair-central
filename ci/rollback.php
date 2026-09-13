<?php
// Real rollback proof. Runs only in the disposable Docker fixture.
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
require '/work/site/wp-load.php';

$plugin='aaihb-rollback-fixture/aaihb-rollback-fixture.php';
$path=WP_PLUGIN_DIR.'/'.$plugin;
if(!is_plugin_active($plugin)||!is_file($path))exit(2);

update_option('aaihb_guard_enabled',true,false);
update_option('aaihb_rollback_db_marker','before-update',false);
if(AAIHB_PluginGuard::before(true,['plugin'=>$plugin])!==true)exit(3);

// This is the simulated failed update: the next HTTP request must return 500.
file_put_contents($path,"<?php\n/* Plugin Name: AAIHB Rollback Fixture */\nadd_action('init',function(){ aaihb_intentional_missing_function(); });\n");
AAIHB_PluginGuard::after(null,['type'=>'plugin','action'=>'update','plugins'=>[$plugin]]);

$last=get_option('aaihb_guard_last',[]);
$source=file_get_contents($path);
$response=wp_remote_get(home_url('/'),['timeout'=>15,'redirection'=>0,'headers'=>['Cache-Control'=>'no-cache']]);
$code=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
$restored=strpos($source,"aaihb_rollback_fixture_loaded","v1")!==false && strpos($source,'aaihb_intentional_missing_function')===false;
$history=isset($last['id'],$last['plugin'],$last['message']) && $last['plugin']===$plugin && strpos($last['message'],'戻しました')!==false;
$database=get_option('aaihb_rollback_db_marker')==='before-update';
$result=['pre_update_backup_created'=>isset($last['id']),'real_http_500_detected_and_rolled_back'=>$restored&&$history,'restored_plugin_files'=>$restored,'site_http_after_restore'=>$code,'database_preserved'=>$database,'operation_history_saved'=>$history];
file_put_contents('/work/rollback.json',json_encode($result));
if(!$result['pre_update_backup_created']||!$result['real_http_500_detected_and_rolled_back']||!$result['restored_plugin_files']||$code!==200||!$database||!$history)exit(4);
