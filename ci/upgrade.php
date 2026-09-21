<?php
// Real old-release -> current-release update proof in the disposable fixture.
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
$out=['active_after_update'=>false,'version_is_current'=>false,'legacy_data_preserved'=>false,'new_control_center_loaded'=>false];
try{
 require '/work/site/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
 $plugin='autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php';
 $out['active_after_update']=is_plugin_active($plugin);
 $data=get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin,false,false);$out['version_is_current']=($data['Version']??'')==='0.20.2';
 $out['legacy_data_preserved']=get_option('aaihb_upgrade_probe')==='legacy-0.20.1';
 $out['new_control_center_loaded']=class_exists('AAIHB_Verification')&&class_exists('AAIHB_ControlCenter');
 AAIHB_Beta::upgrade();
}catch(Throwable $e){}
file_put_contents('/work/upgrade.json',json_encode($out));
exit(!in_array(false,$out,true)?0:4);
