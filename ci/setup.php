<?php
// Synthetic WordPress site only. No production imports or credentials.
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
define('WP_INSTALLING',true);
require '/work/site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
wp_install('AutoRepair disposable fixture','fixture_admin','fixture@example.invalid',false,'',bin2hex(random_bytes(20)));
// A disposable directory plugin used only to prove the update rollback path.
// Version one is intentionally harmless; the acceptance script replaces it
// with a fatal version after the guard has created a real pre-update backup.
$rollback_dir=WP_PLUGIN_DIR.'/aaihb-rollback-fixture';
if(!is_dir($rollback_dir))mkdir($rollback_dir,0755,true);
file_put_contents($rollback_dir.'/aaihb-rollback-fixture.php',"<?php\n/* Plugin Name: AAIHB Rollback Fixture */\nadd_action('init',function(){ update_option('aaihb_rollback_fixture_loaded','v1',false); });\n");
file_put_contents(ABSPATH.'aaihb-rollback-health.php',"<?php\n\$fixture=__DIR__.'/wp-content/plugins/aaihb-rollback-fixture/aaihb-rollback-fixture.php';\n\$source=@file_get_contents(\$fixture);\nif(is_string(\$source)&&strpos(\$source,'aaihb_broken_fixture')!==false){http_response_code(500);echo 'fixture update unhealthy';exit;}\nhttp_response_code(200);echo 'fixture update healthy';\n");
activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');
activate_plugin('aaihb-rollback-fixture/aaihb-rollback-fixture.php');
activate_plugin('contact-form-7/wp-contact-form-7.php');
// Newly-activated plugins' init-time setup (CPT registration, etc.) hasn't run yet in this request.
do_action('plugins_loaded');do_action('init');
AAIHB_Beta::upgrade();
if(class_exists('WPCF7_ContactForm')){
    $cf7=WPCF7_ContactForm::get_template();
    $cf7->set_title('AAIHB CI Fixture');
    $cf7->save();
    $mu_dir=WP_CONTENT_DIR.'/mu-plugins';
    if(!is_dir($mu_dir))mkdir($mu_dir,0755,true);
    file_put_contents($mu_dir.'/aaihb-cf7-mail-intercept.php',"<?php\n// Disposable fixture only: short-circuits real mail delivery and records the subject for acceptance checks.\nadd_filter('pre_wp_mail',function(\$null,\$atts){update_option('aaihb_cf7_mail_marker',(string)(\$atts['subject']??''),false);return true;},10,2);\n");
}
update_option('default_comment_status','open');update_option('comment_registration',0);update_option('comment_moderation',1);update_option('require_name_email',1);update_option('comments_notify',0);update_option('moderation_notify',0);
wp_update_post(['ID'=>1,'post_status'=>'publish','comment_status'=>'open']);
file_put_contents('/work/fixture-ready.json',json_encode(['post_id'=>1]));
