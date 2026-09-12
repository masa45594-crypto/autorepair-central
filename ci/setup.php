<?php
// Synthetic WordPress site only. No production imports or credentials.
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
define('WP_INSTALLING',true);
require '/work/site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
wp_install('AutoRepair disposable fixture','fixture_admin','fixture@example.invalid',false,'',bin2hex(random_bytes(20)));
activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');
AAIHB_Beta::upgrade();
update_option('default_comment_status','open');update_option('comment_registration',0);update_option('comment_moderation',1);update_option('require_name_email',1);update_option('comments_notify',0);update_option('moderation_notify',0);
wp_update_post(['ID'=>1,'post_status'=>'publish','comment_status'=>'open']);
file_put_contents('/work/fixture-ready.json',json_encode(['post_id'=>1]));
