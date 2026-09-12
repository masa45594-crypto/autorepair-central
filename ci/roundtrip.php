<?php
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
require '/work/site/wp-load.php';
$id=trim(file_get_contents('/work/backup-id.txt'));
update_option('aaihb_fixture_roundtrip','changed');file_put_contents(ABSPATH.'fixture.txt','changed');
$result=AAIHB_FullSite::restore($id);
global $wpdb;
if(file_get_contents(ABSPATH.'fixture.txt')!=='original')exit(2);
if($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='aaihb_fixture_roundtrip'")!=='original')exit(3);
if(!isset($result['safety'],$result['journal']))exit(4);
file_put_contents('/work/roundtrip.json',json_encode(['same_site_files_and_db_restore'=>true,'pre_restore_snapshot_created'=>true]));
