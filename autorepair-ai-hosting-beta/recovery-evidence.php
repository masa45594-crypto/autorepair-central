<?php
if(!defined('ABSPATH'))exit;
/** Recovery evidence derived only from signed, isolated restore-test records. */
final class AAIHB_RecoveryEvidence {
 const MAX_AGE=2592000; // 30 days
 const EVENTS='aaihb_recovery_timeline';
 public static function score($test){
  $checks=['files'=>25,'database'=>25,'homepage'=>20,'wordpress'=>20,'cleanup'=>10];$score=0;
  if(($test['status']??'')==='passed')foreach($checks as $check=>$points)if(($test['checks'][$check]??false)===true)$score+=$points;
  $at=$test['finished_at']??($test['at']??'');$time=is_string($at)?strtotime($at):false;$age=$time?max(0,time()-$time):null;
  if($score===100&&$age!==null&&$age>self::MAX_AGE)$score=max(0,$score-($age>self::MAX_AGE*3?35:15));
  return ['score'=>$score,'verified_at'=>$at,'fresh'=>$score===100&&$age!==null&&$age<=self::MAX_AGE,'age_days'=>$age===null?null:(int)floor($age/86400)];
 }
 public static function latest(){
  try{$root=AAIHB_VaultIO::root();$dirs=glob($root.'/[0-9]*',GLOB_ONLYDIR)?:[];rsort($dirs);foreach($dirs as $dir){try{[$path,$point]=AAIHB_VaultIO::point(basename($dir));if(($point['kind']??'')!=='full')continue;$test=AAIHB_RestoreTest::result($path,$point);return ['path'=>$path,'point'=>$point,'test'=>$test,'score'=>self::score($test)];}catch(Throwable $e){continue;}}}catch(Throwable $e){}
  return null;
 }
 public static function gate(){
  $latest=self::latest();if(!$latest)return ['state'=>'block','message'=>'サイト全体の保存ポイントがありません。'];
  try{$root=AAIHB_VaultIO::root();$dirs=glob($root.'/[0-9]*',GLOB_ONLYDIR)?:[];rsort($dirs);foreach($dirs as $dir){try{[$path,$point]=AAIHB_VaultIO::point(basename($dir));if(($point['kind']??'')!=='full')continue;$test=AAIHB_RestoreTest::result($path,$point);$score=self::score($test);if($score['fresh'])return ['state'=>'ready','message'=>'復元テスト合格・30日以内に確認済みです。','latest'=>['path'=>$path,'point'=>$point,'test'=>$test,'score'=>$score]];}catch(Throwable $e){continue;}}}catch(Throwable $e){}
  if(($latest['test']['status']??'')==='passed')return ['state'=>'warning','message'=>'復元テストは合格していますが、確認日が30日を超えています。再検証してください。','latest'=>$latest];
  return ['state'=>'block','message'=>'サイト全体バックアップは、隔離環境での復元テストに合格していません。','latest'=>$latest];
 }
 public static function event($type,$id='',$note=''){
  $events=get_option(self::EVENTS,[]);if(!is_array($events))$events=[];$events[]=['at'=>gmdate('c'),'type'=>sanitize_key($type),'id'=>preg_replace('/[^A-Za-z0-9_-]/','',substr((string)$id,0,80)),'note'=>sanitize_text_field(substr((string)$note,0,180)),'actor'=>(int)get_current_user_id()];
  update_option(self::EVENTS,array_slice($events,-100),false);
 }
 public static function timeline(){ $events=get_option(self::EVENTS,[]);return is_array($events)?array_reverse($events):[]; }
 public static function event_label($type){return ['backup_created'=>'保存ポイントを作成','backup_audit'=>'バックアップ健全性を確認','recovery_test_started'=>'隔離復元テストを開始','recovery_test_passed'=>'隔離復元テストに合格','recovery_test_failed'=>'隔離復元テストが不合格','restore_started'=>'復元を開始','restore_completed'=>'復元を完了','update_gate_blocked'=>'更新をセーフティゲートで停止','update_gate_override'=>'管理者が更新を一時承認','update_recorded'=>'更新差分を記録','diagnostic_alert'=>'診断で要確認を検出'][$type]??'復旧操作';}
 public static function create_share($id){
  self::record($id);
  $token=bin2hex(random_bytes(24));$shares=get_option('aaihb_recovery_shares',[]);if(!is_array($shares))$shares=[];$shares[$token]=['id'=>$id,'expires'=>time()+604800];update_option('aaihb_recovery_shares',$shares,false);return self::share_url($token);
 }
 public static function share_url($token){return add_query_arg('aaihb_recovery_certificate',$token,home_url('/'));}
 private static function record($id){
  try{[$path,$point]=AAIHB_VaultIO::point($id);if(($point['kind']??'')!=='full')throw new RuntimeException('サイト全体の保存ポイントを選択してください。');$test=AAIHB_RestoreTest::result($path,$point);return ['point'=>$point,'test'=>$test,'score'=>self::score($test)];}catch(Throwable $e){throw new RuntimeException('証明書の根拠を確認できません。');}
 }
 public static function certificate_html($id,$public=false){
  $r=self::record($id);$labels=['files'=>'バックアップのファイル照合','database'=>'DBの復元・照合','homepage'=>'トップページ HTTP 200 と HTML','wordpress'=>'WordPress起動の証明','cleanup'=>'検証環境の後片付け'];$rows='';foreach($labels as $key=>$label){$ok=($r['test']['checks'][$key]??false)===true;$rows.='<tr><td>'.esc_html($label).'</td><td>'.($ok?'確認済み':'未確認').'</td></tr>';}
  $verified=$r['score']['verified_at']?:'未実施';$score=(int)$r['score']['score'];$name=esc_html(get_bloginfo('name'));
  return '<!doctype html><html lang="ja"><meta charset="utf-8"><title>AutoRepair AI 復元証明レポート</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#15223a;max-width:820px;margin:40px auto;padding:0 24px}.hero{background:linear-gradient(120deg,#172554,#7c3aed);color:#fff;border-radius:22px;padding:32px}.score{font-size:54px;font-weight:800}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{border:1px solid #d8e0ef;padding:13px;text-align:left}th{background:#f4f7fc}.note{background:#fff8e8;padding:16px;border-radius:12px}@media print{button{display:none}body{margin:0}}</style><body><div class="hero"><p>RECOVERY EVIDENCE REPORT</p><h1>復元証明レポート</h1><div class="score">'.$score.' / 100</div><p>'.$name.' ／ 発行 '.esc_html(gmdate('Y-m-d H:i \U\T\C')).'</p></div><h2>確認対象</h2><p>保存ポイント：'.esc_html($r['point']['id']).'<br>バックアップ作成：'.esc_html($r['point']['at']??'').'<br>最終検証：'.esc_html($verified).'</p><h2>隔離環境で確認した範囲</h2><table><thead><tr><th>確認項目</th><th>結果</th></tr></thead><tbody>'.$rows.'</tbody></table><div class="note"><strong>確認範囲の注意</strong><br>このレポートは、署名済みバックアップを隔離環境で検証した記録です。購入処理、フォーム送信、外部連携、本番環境への実復元は確認対象に含みません。</div>'.($public?'':'<p><button onclick="window.print()">印刷／PDFとして保存</button></p>').'</body></html>';
 }
 public static function print_certificate(){if(!current_user_can('manage_options'))wp_die('権限がありません。',403);$id=sanitize_text_field(wp_unslash($_GET['id']??''));check_admin_referer('aaihb_certificate_'.$id);nocache_headers();echo self::certificate_html($id);exit;}
 public static function public_certificate(){
  $token=isset($_GET['aaihb_recovery_certificate'])?preg_replace('/[^a-f0-9]/','',wp_unslash($_GET['aaihb_recovery_certificate'])):'';if(!$token)return;$shares=get_option('aaihb_recovery_shares',[]);$share=is_array($shares)?($shares[$token]??null):null;if(!is_array($share)||(int)($share['expires']??0)<time()){status_header(404);exit;}
  nocache_headers();echo self::certificate_html((string)$share['id'],true);exit;
 }
 public static function boot(){add_action('admin_post_aaihb_certificate',[__CLASS__,'print_certificate']);add_action('template_redirect',[__CLASS__,'public_certificate']);}
}
