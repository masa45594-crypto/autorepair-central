<?php
if (!defined('ABSPATH')) exit;
/** Independent read-only diagnostics. No dependency on WPAI classes or jobs. */
final class AAIHB_Diagnostics {
 public static function labels(){return ['home'=>'公開ページ','rest_api'=>'REST API','database'=>'データベース','https'=>'HTTPS設定','disk'=>'ディスク空き容量','filesystem'=>'ファイル書き込み権限','cron'=>'予約処理','maintenance'=>'メンテナンスファイル','updates'=>'プラグイン更新','debug_log'=>'標準ログのエラー兆候'];}
 private function http($url,$rest=false){
  $r=wp_safe_remote_get($url,['timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>32768,'headers'=>['Cache-Control'=>'no-cache']]);
  if(is_wp_error($r))return $rest?'warning':'critical';$code=wp_remote_retrieve_response_code($r);
  if(!$rest&&$code>=500)return 'critical';
  if($code<200||$code>=300)return 'warning';
  if($rest){$body=json_decode(wp_remote_retrieve_body($r),true);if(!is_array($body)||!isset($body['namespaces']))return 'warning';}
  return 'healthy';
 }
 public static function disk_status($bytes){if($bytes===false||!is_numeric($bytes))return 'warning';return $bytes<500*MB_IN_BYTES?'critical':($bytes<GB_IN_BYTES?'warning':'healthy');}
 public static function cron_status($jobs,$now){
  if(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON)return 'warning';
  if(!is_array($jobs)||!$jobs)return 'warning';
  foreach($jobs as $at=>$hooks)if(is_numeric($at)&&(int)$at<$now-3600)return 'warning';
  return 'healthy'; // Scheduled entries only; does not prove execution.
 }
 public static function log_status($file){
  if(!is_file($file)||!is_readable($file))return 'warning';
  $h=@fopen($file,'rb');if(!$h)return 'warning';
  try{$size=fstat($h)['size'];if(fseek($h,max(0,$size-131072))!==0)return 'warning';$tail=fread($h,131072);if($tail===false)return 'warning';
   return preg_match('/PHP Fatal error|Uncaught (?:Error|Exception)|Allowed memory size .* exhausted/i',$tail)?'warning':'healthy';
  }finally{fclose($h);}
 }
 public function run(){
  global $wpdb;$checks=[];
  foreach(array_keys(self::labels()) as $id){
   try{
    switch($id){
     case 'home':$status=$this->http(home_url('/'));break;
     case 'rest_api':$status=$this->http(add_query_arg('_fields','namespaces',rest_url()),true);break;
     case 'database':$status=(string)$wpdb->get_var('SELECT 1')==='1'?'healthy':'critical';break;
     case 'https':$status=is_ssl()&&strpos(home_url(),'https://')===0&&strpos(site_url(),'https://')===0?'healthy':'warning';break;
     case 'disk':$status=self::disk_status(function_exists('disk_free_space')?@disk_free_space(ABSPATH):false);break;
     case 'filesystem':$status=is_writable(WP_CONTENT_DIR)?'healthy':'warning';break;
     case 'cron':$status=self::cron_status(_get_cron_array(),time());break;
     case 'maintenance':$status=is_file(ABSPATH.'.maintenance')?'warning':'healthy';break;
     case 'updates':$u=get_site_transient('update_plugins');$status=is_object($u)&&isset($u->last_checked)&&$u->last_checked>time()-DAY_IN_SECONDS&&isset($u->response)&&is_array($u->response)&&count($u->response)===0?'healthy':'warning';break;
     case 'debug_log':$status=self::log_status(WP_CONTENT_DIR.'/debug.log');break;
    }
   }catch(Throwable $e){$status='warning';}
   $checks[]=['id'=>$id,'status'=>$status];
  }
  $score=100;foreach($checks as $c)$score-=$c['status']==='critical'?25:($c['status']==='warning'?10:0);
  return ['score'=>max(0,$score),'checks'=>$checks];
 }
 public static function boot(){add_action('admin_post_aaihb_local_scan',[__CLASS__,'local_scan']);}
 public static function local_scan(){AAIHB_Beta::allowed();check_admin_referer('aaihb_local_scan');$r=AAIHB_Beta::agent_scan();
  if(is_wp_error($r))set_transient('aaihb_local_notice_'.get_current_user_id(),$r->get_error_message(),120);
  else { $data=$r->get_data(); update_option('aaihb_local_report',$data,false); foreach($data['checks'] as $check)if(($check['status']??'')==='critical'){if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('diagnostic_alert','','重要項目を検出');break;} }
  wp_safe_redirect(admin_url('admin.php?page=aaihb-start'));exit;
 }
 public static function panel(){
  echo '<section class="ss-card"><h2>まず、このサイトを診断</h2><p>AutoRepair AI本体は不要です。Hosting Beta内蔵の10項目診断を実行します。別サイトへの接続設定も不要です。</p>';
  $message=get_transient('aaihb_local_notice_'.get_current_user_id());delete_transient('aaihb_local_notice_'.get_current_user_id());if($message)echo '<p role="status">'.esc_html($message).'</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_local_scan">';wp_nonce_field('aaihb_local_scan');submit_button('このサイトを診断する');echo '</form>';
  $r=get_option('aaihb_local_report');if(AAIHB_Beta::validate_report($r)){
   echo '<p>最終診断（UTC）：'.esc_html($r['generated_at']).' ／ スコア '.(int)$r['score'].' / 100</p><ul>';
   foreach($r['checks'] as $c)echo '<li><strong>'.esc_html(self::labels()[$c['id']]).'</strong>：'.esc_html(['healthy'=>'確認範囲は正常','warning'=>'要確認・計測できない項目を含む','critical'=>'重大な異常の兆候'][$c['status']]).'</li>';
   echo '</ul>';
  }
  echo '<p>診断は読み取り専用です。この診断ボタンでは修復しません。修復・設定の復元は「自動修復・復元」で操作します。ログがない場合や更新情報が古い場合も「要確認」です。予約の登録状況は実行成功の証明ではありません。</p></section>';
 }
}
