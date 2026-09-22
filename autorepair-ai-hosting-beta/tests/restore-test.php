<?php
if(!defined('AAIHB_TESTING'))exit;
require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/template.php';wp_set_current_user(1);activate_plugin('autorepair-ai-hosting-beta/autorepair-ai-hosting-beta.php');AAIHB_Beta::upgrade();
mkdir('/private-verify',0700);chmod('/private-verify',0700);define('AAIHB_VAULT_DIR','/private-verify');define('AAIHB_PUBLIC_ROOT','/wordpress');
$n=0;function tcheck($v,$s){global $n;if(!$v)throw new RuntimeException('FAIL '.$s);$n++;echo "PASS $s\n";}function treject($f,$s){try{$f();}catch(RuntimeException $e){tcheck(true,$s);return;}tcheck(false,$s);}
[$id,$dir]=AAIHB_VaultIO::fresh();$m=['id'=>$id,'kind'=>'full','state'=>'ready','payloads'=>[]];AAIHB_VaultIO::manifest($dir,$m);
tcheck(AAIHB_RestoreTest::result($dir,$m)['status']==='untested','saved does not imply verified');
$checks=array_fill_keys(AAIHB_RestoreTest::CHECKS,true);$raw=json_encode(['nonce'=>'fixture','status'=>'passed','checks'=>$checks,'stage'=>'complete']);
tcheck(AAIHB_RestoreTest::accept($raw,'fixture')['status']==='passed','complete evidence accepted');treject(function()use($raw){AAIHB_RestoreTest::accept($raw,'other');},'wrong run nonce rejected');
foreach(AAIHB_RestoreTest::CHECKS as $c){$partial=$checks;unset($partial[$c]);treject(function()use($partial){AAIHB_RestoreTest::accept(json_encode(['nonce'=>'fixture','status'=>'passed','checks'=>$partial]),'fixture');},'missing '.$c.' cannot pass');}
$r=['id'=>$id,'source'=>hash_file('sha256',$dir.'/manifest.json'),'at'=>gmdate('c'),'status'=>'passed','checks'=>$checks,'stage'=>'complete'];AAIHB_RestoreTest::record($dir,$r);tcheck(AAIHB_RestoreTest::result($dir,$m)['status']==='passed','signed result read');
file_put_contents($dir.'/restore-test.json','tampered');tcheck(AAIHB_RestoreTest::result($dir,$m)['status']==='invalid','tampered result rejected');
AAIHB_RestoreTest::record($dir,$r);$m['changed']=true;AAIHB_VaultIO::manifest($dir,$m);tcheck(AAIHB_RestoreTest::result($dir,$m)['status']==='invalid','changed source invalidates evidence');
$r['source']=hash_file('sha256',$dir.'/manifest.json');$r['status']='running';AAIHB_RestoreTest::record($dir,$r);tcheck(AAIHB_RestoreTest::result($dir,$m)['status']==='running','interrupted run cannot imply pass');
$r['status']='unavailable';AAIHB_RestoreTest::record($dir,$r);tcheck(strpos(AAIHB_RestoreTest::label(AAIHB_RestoreTest::result($dir,$m)),'未準備')!==false,'missing infrastructure shown separately');
$r['status']='passed';AAIHB_RestoreTest::record($dir,$r);
ob_start();AAIHB_VaultAdmin::page();$html=ob_get_clean();file_put_contents('/qa/verify-page.html',$html);tcheck(strpos($html,'復元テスト合格（隔離環境）')!==false,'UI scopes passed state');tcheck(strpos($html,'aaihb-vault verify')!==false,'UI exposes test command');
tcheck(strpos($html,'復旧リハーサルを予約')!==false,'UI exposes queued isolated rehearsal action');tcheck(strpos($html,'復元信頼度スコア')!==false,'UI exposes recovery confidence score');
tcheck(strpos($html,'定期リハーサルと失敗通知')!==false,'UI exposes scheduled rehearsal settings');tcheck(strpos($html,'復元前の影響範囲を確認する')!==false,'UI exposes restore impact preview');
tcheck(strpos($html,'バックアップの改ざん・破損検知')!==false,'UI exposes backup integrity audit');
ob_start();AAIHB_ControlCenter::respond();$respond=ob_get_clean();tcheck(strpos($respond,'復旧後の自動チェック')!==false,'UI exposes automatic post-restore checks');
ob_start();AAIHB_ControlCenter::protect();$protect=ob_get_clean();tcheck(strpos($protect,'更新前後の変更記録')!==false,'UI exposes update change evidence');
echo "TOTAL $n checks passed\n";
