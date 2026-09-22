<?php
/** May be copied with dependencies outside the public directory. Requires bootstrapped WP-CLI. */
if(!defined('ABSPATH'))exit;
foreach(['vault-io.php','vault-db.php','vault-full.php','vault-test.php','recovery-evidence.php'] as $file)require_once __DIR__.'/'.$file;
if(defined('WP_CLI')&&WP_CLI){
 WP_CLI::add_command('aaihb-vault',function($args,$assoc){
  try{
   if(($args[0]??'')==='verify'){if(empty($args[1]))throw new RuntimeException('保存IDが必要です。');$r=AAIHB_RestoreTest::run($args[1]);if($r['status']!=='passed')WP_CLI::error(AAIHB_RestoreTest::label($r).' / '.($r['stage']??''));WP_CLI::success(AAIHB_RestoreTest::label($r));return;}
   if(empty($assoc['ack-quiesced']))throw new RuntimeException('Webアクセス・外部ジョブ・書き込みを停止し、--ack-quiescedを指定してください。');
   if(($args[0]??'')==='backup'){$r=AAIHB_FullSite::backup();WP_CLI::success('Backup: '.$r);if(isset($assoc['verify'])){$test=AAIHB_RestoreTest::run($r);if($test['status']!=='passed')WP_CLI::error(AAIHB_RestoreTest::label($test).' / '.($test['stage']??''));WP_CLI::success(AAIHB_RestoreTest::label($test));}}
   elseif(($args[0]??'')==='restore'){
    if(empty($args[1])||($assoc['confirm-site']??'')!==untrailingslashit(ABSPATH))throw new RuntimeException('--confirm-siteに、このサイトのABSPATH（末尾/なし）を指定してください。');
    $r=AAIHB_FullSite::restore($args[1]);WP_CLI::success(wp_json_encode($r));
   }else throw new RuntimeException('backup [--verify]、verify <保存ID>、restore <保存ID> を指定してください。');
  }catch(Throwable $e){WP_CLI::error($e->getMessage());}
 });
}
