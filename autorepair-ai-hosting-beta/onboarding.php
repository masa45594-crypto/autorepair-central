<?php
if(!defined('ABSPATH'))exit;
/** Five-step, task-first onboarding. It never auto-updates WordPress. */
final class AAIHB_Onboarding {
 public static function boot(){add_action('admin_menu',function(){add_submenu_page('aaihb-home','はじめる','はじめる','manage_options','aaihb-get-started',[__CLASS__,'page']);});}
 private static function storage(){try{AAIHB_VaultIO::root();return true;}catch(Throwable $e){return false;}}
 public static function steps(){ $latest=AAIHB_RecoveryEvidence::latest();$backup=is_array($latest);$test=$backup?($latest['test']['status']??'untested'):'unset';$ready=AAIHB_RecoveryEvidence::gate()['state']==='ready';return [
 ['label'=>'保管先を設定','ok'=>self::storage(),'href'=>admin_url('admin.php?page=aaihb-vault'),'button'=>'保管先を設定する','done'=>'保管先を確認済み','next'=>'次にすること：テストサイトを接続してください'],
 ['label'=>'テストサイトを接続','ok'=>AAIHB_Beta::sites_count()>0,'href'=>admin_url('admin.php?page=aaihb-start'),'button'=>'テスト接続する','done'=>'テストサイト接続済み','next'=>'次にすること：最初のバックアップを作成してください'],
 ['label'=>'最初のバックアップを作成','ok'=>$backup,'href'=>admin_url('admin.php?page=aaihb-vault#full-backup'),'button'=>'バックアップを作成','done'=>'バックアップ作成済み','next'=>'次にすること：復元テストを実行してください'],
 ['label'=>'隔離復元テストを実行','ok'=>$test==='passed','href'=>admin_url('admin.php?page=aaihb-vault#evidence'),'button'=>'復元テストを開始','done'=>'復元テスト合格','next'=>$test==='failed'?'次にすること：失敗したテスト内容を確認してください':'次にすること：復元テストを実行してください'],
 ['label'=>'結果を確認','ok'=>$ready,'href'=>admin_url('admin.php?page=aaihb-vault#evidence'),'button'=>'結果を見る','done'=>'更新前の安全確認が完了','next'=>'次にすること：更新画面で対象を確認してください'],
 ];}
 private static function tone($s){if($s['ok'])return ['green','確認済み'];if($s['label']==='保管先を設定')return ['gray','未設定'];if($s['label']==='隔離復元テストを実行')return ['red','要対応'];return ['yellow','操作待ち'];}
 public static function page(){AAIHB_Beta::allowed();$steps=self::steps();$complete=true;foreach($steps as $s)if(!$s['ok'])$complete=false;echo '<div class="wrap aaihb-app aaihb-selfserve"><header class="ss-hero"><small>GET STARTED</small><h1>AutoRepair AI を始めましょう</h1><p>最初は、この5つだけを順番に完了してください。</p></header>';AAIHB_Dashboard::nav('aaihb-get-started');echo '<section class="aaihb-onboarding">';foreach($steps as $n=>$s){[$tone,$label]=self::tone($s);echo '<article class="aaihb-onboarding-step is-'.esc_attr($tone).'"><span class="aaihb-onboarding-number">'.($n+1).'</span><div><p class="aaihb-onboarding-state">'.esc_html($label).'</p><h2>'.esc_html($s['label']).'</h2><p>'.esc_html($s['ok']?$s['done']:$s['next']).'</p></div><a class="button '.($s['ok']?'':'button-primary').'" href="'.esc_url($s['href']).'">'.esc_html($s['button']).'</a></article>';}echo '</section><section class="ss-card"><h2>更新前の一本道</h2><p><strong>保存 → 隔離復元テスト → 安全なら更新 → 異常なら戻す</strong></p><p>'.($complete?'復元確認済みです。更新そのものはWordPress標準の更新画面で、対象と変更内容を確認してから実行してください。':'上の5項目が完了するまで、更新は始めないでください。').'</p><a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-protect')).'">更新前の安全確認を開く</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-emergency')).'">異常時の緊急モードを開く</a></section></div>';}
}
