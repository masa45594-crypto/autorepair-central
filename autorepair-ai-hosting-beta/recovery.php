<?php
if(!defined('ABSPATH'))exit;
/** Local, allowlisted repair operations with pre-change snapshots and conflict checks. */
final class AAIHB_Recovery {
 const TYPE='aaihb_restore';
 public static function boot(){
  add_action('init',function(){register_post_type(self::TYPE,['public'=>false,'publicly_queryable'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>[]]);});
  add_action('admin_menu',function(){add_submenu_page('aaihb-home','自動修復・復元','自動修復・復元','manage_options','aaihb-recovery',[__CLASS__,'page']);});
  add_action('admin_post_aaihb_recovery',[__CLASS__,'save']);add_action('aaihb_recovery_tick',[__CLASS__,'tick']);
 }
 private static function snapshot($kind,$before){
  $payload=wp_json_encode(['kind'=>$kind,'before'=>$before,'after'=>null,'state'=>'prepared','at'=>gmdate('c')]);
  if(strlen($payload)>1048576)throw new RuntimeException('復元データが上限を超えています。');
  $id=wp_insert_post(wp_slash(['post_type'=>self::TYPE,'post_status'=>'private','post_title'=>$kind,'post_content'=>$payload]),true);
  if(is_wp_error($id)||!$id)throw new RuntimeException('復元ポイントを保存できません。変更を中止しました。');return $id;
 }
 private static function finish($id,$data){$r=wp_update_post(wp_slash(['ID'=>$id,'post_content'=>wp_json_encode($data)]),true);if(is_wp_error($r)||!$r)throw new RuntimeException('記録の更新に失敗しました。保存済みの変更前データを確認してください。');}
 public static function data($id){$p=get_post($id);if(!$p||$p->post_type!==self::TYPE||$p->post_status!=='private')throw new RuntimeException('復元ポイントが見つかりません。');$d=json_decode($p->post_content,true);if(!is_array($d))throw new RuntimeException('復元記録が不正です。');return $d;}
 private static function rules(){return ['exists'=>get_option('rewrite_rules',null)!==null,'value'=>get_option('rewrite_rules',null)];}
 private static function maintenance(){
  $p=ABSPATH.'.maintenance';if(is_link($p))throw new RuntimeException('シンボリックリンクは操作できません。');
  if(!file_exists($p))return null;
  if(!is_file($p)||filesize($p)>512||!is_readable($p))throw new RuntimeException('メンテナンスファイルを確認できません。');
  $text=file_get_contents($p);
  if(!preg_match('/^\s*<\?php\s+\$upgrading\s*=\s*([0-9]{1,12})\s*;\s*(?:\?>)?\s*$/D',$text,$m))throw new RuntimeException('標準形式以外のメンテナンスファイルは変更しません。');
  return ['content'=>$text,'time'=>(int)$m[1]];
 }
 public static function repair($kind){
  if(!add_option('aaihb_recovery_lock',time(),'',false))throw new RuntimeException('修復処理中です。');
  try{
   if($kind==='rules'){
    if(!get_option('permalink_structure'))throw new RuntimeException('基本パーマリンクでは実行しません。');
    $before=self::rules();$id=self::snapshot($kind,$before);$d=self::data($id);
    // Soft flush only: do not touch .htaccess or server configuration.
    flush_rewrite_rules(false);$after=self::rules();
    if(!is_array($after['value'])||!$after['value'])throw new RuntimeException('ルール生成を確認できません。変更前の復元ポイントを保持しています。');
    $d['after']=$after;$d['state']='applied';self::finish($id,$d);return $id;
   }
   if($kind==='maintenance'){
    $before=self::maintenance();if(!$before||time()-$before['time']<1800)throw new RuntimeException('30分以上経過した標準ファイルが対象です。');
    foreach(['core_updater.lock','auto_updater.lock'] as $k)if((int)get_option($k,0)>time()-3600)throw new RuntimeException('更新処理中の可能性があります。');
    $id=self::snapshot($kind,$before);$d=self::data($id);
    if(self::maintenance()!==$before||!unlink(ABSPATH.'.maintenance'))throw new RuntimeException('ファイルが変化したか、削除できません。');
    $d['state']='applied';$d['after']=null;self::finish($id,$d);return $id;
   }
   throw new RuntimeException('許可されていない修復です。');
  }finally{delete_option('aaihb_recovery_lock');}
 }
 public static function restore($id){
  if(!add_option('aaihb_recovery_lock',time(),'',false))throw new RuntimeException('修復処理中です。');
  try{$d=self::data($id);if($d['state']!=='applied')throw new RuntimeException('自動復元可能な状態ではありません。');
   if($d['kind']==='rules'){
    if(self::rules()!==$d['after'])throw new RuntimeException('修復後に設定が変わっています。上書きせず停止しました。');
    if($d['before']['exists'])update_option('rewrite_rules',$d['before']['value']);else delete_option('rewrite_rules');
    if(self::rules()!==$d['before'])throw new RuntimeException('設定の復元を確認できません。');
    update_option('aaihb_recovery_auto',false,false);wp_clear_scheduled_hook('aaihb_recovery_tick');
   }elseif($d['kind']==='maintenance'){
    $text=$d['before']['content'];if(!is_string($text)||strlen($text)>512||!preg_match('/^\s*<\?php\s+\$upgrading\s*=\s*([0-9]{1,12})\s*;\s*(?:\?>)?\s*$/D',$text))throw new RuntimeException('保存ファイルが標準形式ではありません。');
    $p=ABSPATH.'.maintenance';$f=@fopen($p,'x');if(!$f)throw new RuntimeException('ファイルが存在するか、作成できません。');
    try{$text=$d['before']['content'];if(fwrite($f,$text)!==strlen($text))throw new RuntimeException('書き込みを完了できません。');}finally{fclose($f);}
   }else throw new RuntimeException('復元形式が不正です。');
   $d['state']='restored';$d['restored_at']=gmdate('c');self::finish($id,$d);
  }finally{delete_option('aaihb_recovery_lock');}
 }
 public static function tick(){
  if(!get_option('aaihb_recovery_auto',false)||is_multisite())return;
  // At most once per 24 hours, only absent/empty rules. Never deactivate plugins automatically.
  if(time()-(int)get_option('aaihb_recovery_last',0)<DAY_IN_SECONDS)return;
  if(get_option('permalink_structure')&&!get_option('rewrite_rules')){
   update_option('aaihb_recovery_last',time(),false);
   try{$id=self::repair('rules');update_option('aaihb_recovery_auto_result','復元ポイント #'.$id.' を保存し、ルールを再生成しました。',false);}
   catch(Throwable $e){update_option('aaihb_recovery_auto_result',$e->getMessage(),false);}
  }
 }
 public static function save(){AAIHB_Beta::allowed();check_admin_referer('aaihb_recovery');
  try{if(AAIHB_Beta::input('confirm')!=='1')throw new RuntimeException('変更範囲を確認してチェックしてください。');$op=AAIHB_Beta::input('op');
   if($op==='auto'){ $on=AAIHB_Beta::input('enabled')==='1';update_option('aaihb_recovery_auto',$on,false);wp_clear_scheduled_hook('aaihb_recovery_tick');if($on)wp_schedule_event(time()+60,'hourly','aaihb_recovery_tick'); }
   elseif($op==='restore'){self::restore(absint(AAIHB_Beta::input('id')));}
   else self::repair($op);
   $message='処理を完了しました。対象ページの表示も確認してください。';
  }catch(Throwable $e){$message=$e->getMessage();}
  set_transient('aaihb_recovery_notice_'.get_current_user_id(),$message,120);wp_safe_redirect(admin_url('admin.php?page=aaihb-recovery'));exit;
 }
 private static function form($op){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_recovery"><input type="hidden" name="op" value="'.esc_attr($op).'">';wp_nonce_field('aaihb_recovery');}
 private static function confirm(){echo '<p><label><input type="checkbox" required name="confirm" value="1">変更範囲と復元方法を確認しました</label></p>';}
 public static function page(){AAIHB_Beta::allowed();nocache_headers();echo '<div class="wrap aaihb-app aaihb-selfserve"><header class="ss-hero"><small>REPAIR & RESTORE</small><h1>直す前に保存。必要なら戻す。</h1><p>このWordPressだけを操作します。AutoRepair AI本体は不要です。</p></header>';AAIHB_Dashboard::nav('aaihb-recovery');
  $notice=get_transient('aaihb_recovery_notice_'.get_current_user_id());delete_transient('aaihb_recovery_notice_'.get_current_user_id());if($notice)echo '<section class="ss-result" role="status">'.esc_html($notice).'</section>';
  echo '<section class="ss-card"><h2>修復できる範囲</h2><p>①WordPress内のURLルール再生成 ②30分以上経過した標準メンテナンスファイルの解除。変更前をDBに保存します。サイト全体・投稿・画像・プラグインファイルのバックアップではありません。</p><p>管理画面が起動しない障害やDB障害は、この画面から復元できません。</p></section><div class="ss-grid">';
  foreach(['rules'=>['URLルールを再生成','パーマリンクの内部ルールを再生成します。.htaccessは変更しません。404のすべての原因を直すものではありません。'],'maintenance'=>['古いメンテナンス状態を解除','更新が終了していることを確認して実行してください。30分以上経過した標準形式だけを対象にします。']] as $op=>$text){echo '<section class="ss-card"><h2>'.esc_html($text[0]).'</h2><p>'.esc_html($text[1]).'</p>';self::form($op);self::confirm();submit_button('保存して修復');echo '</form></section>';}
  echo '</div><section class="ss-card"><h2>自動修復：初期状態はOFF</h2><p>有効にすると、内部URLルールが空・未登録の場合だけ再生成します。1時間ごとに確認、修復は最大1日1回。WP-Cronが必要です。メンテナンス解除は自動実行しません。</p>';self::form('auto');echo '<label><input type="checkbox" name="enabled" value="1" '.checked(get_option('aaihb_recovery_auto',false),true,false).'>限定した自動修復を有効にする</label>';self::confirm();submit_button('自動修復の設定を保存');echo '</form><p>自動処理の結果：'.esc_html(get_option('aaihb_recovery_auto_result','まだ実行していません')).'</p></section><section class="ss-card"><h2>復元ポイント（最新30件）</h2><p>修復後に別の変更があれば、上書きせず停止します。preparedは処理中断の可能性があり、手動調査が必要です。</p>';
  foreach(get_posts(['post_type'=>self::TYPE,'post_status'=>'private','numberposts'=>30]) as $p){$d=self::data($p->ID);echo '<p><strong>#'.(int)$p->ID.' '.esc_html($d['kind']).'</strong> / '.esc_html($d['at']).' / '.esc_html($d['state']).'</p>';if($d['state']==='applied'){self::form('restore');echo '<input type="hidden" name="id" value="'.(int)$p->ID.'">';self::confirm();submit_button('この変更を元に戻す','secondary');echo '</form>';}}
  echo '</section></div>';
 }
}
