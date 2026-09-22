<?php
if(!defined('ABSPATH'))exit;
final class AAIHB_Central {
 const OPT='aaihb_central';
 public static function settings(){return wp_parse_args(get_option(self::OPT,[]),['url'=>'','token'=>'','error'=>'','result'=>[]]);}
 public static function boot(){add_action('admin_post_aaihb_central',[__CLASS__,'save']);add_action('aaihb_registry_saved',[__CLASS__,'enqueue']);add_action('aaihb_central_tick',[__CLASS__,'sync']);add_action('aaihb_central_drain',[__CLASS__,'sync']);add_action('init',function(){if(!wp_next_scheduled('aaihb_central_tick'))wp_schedule_event(time()+60,'hourly','aaihb_central_tick');});}
 public static function configured(){ $s=self::settings();return $s['url']!==''&&$s['token']!==''; }
 public static function test_checkout_url($success,$cancel){
  if(!self::configured())throw new RuntimeException('先に中央サーバーへ接続してください。');
  if(!is_ssl())throw new RuntimeException('管理画面をHTTPSで開いてください。');
  $s=self::settings();$token=AAIHB_Beta::crypt($s['token'],true);
  $r=wp_safe_remote_post($s['url'].'/v1/stripe/test-checkout',['timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode(['success_url'=>$success,'cancel_url'=>$cancel])]);
  if(is_wp_error($r))throw new RuntimeException('Stripeテスト決済を開始できません。中央サーバーの設定を確認してください。');
  $code=wp_remote_retrieve_response_code($r);
  if($code===409)throw new RuntimeException('すでに有効なテスト契約があります。Stripeテスト用ポータルから契約を管理できます。');
  if($code!==200)throw new RuntimeException('Stripeテスト決済を開始できません。中央サーバーの設定を確認してください。');
  $data=json_decode(wp_remote_retrieve_body($r),true);$url=is_array($data)?($data['checkout_url']??''):'';$p=wp_parse_url($url);
  if(($data['mode']??'')!=='test'||!is_string($url)||!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='checkout.stripe.com'||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Stripeテスト決済URLを検証できません。');
  return $url;
 }
 public static function stripe_test_status(){
  if(!self::configured())return ['status'=>'not_connected','updated'=>''];
  $s=self::settings();$token=AAIHB_Beta::crypt($s['token'],true);
  $r=wp_safe_remote_get($s['url'].'/v1/stripe/test-status',['timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>['Authorization'=>'Bearer '.$token]]);
  if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Stripeテスト契約の状態を取得できません。');
  $data=json_decode(wp_remote_retrieve_body($r),true);$status=is_array($data)?($data['subscription_status']??''):'';$updated=is_array($data)?($data['updated']??''):'';$pending=is_array($data)?($data['cancellation_pending']??false):false;$at=is_array($data)?($data['cancellation_at']??0):0;
  if(($data['mode']??'')!=='test'||!is_string($status)||!is_string($updated)||!is_bool($pending)||!is_int($at)||$at<0||!in_array($status,['','active','trialing','past_due','unpaid','canceled','incomplete','incomplete_expired'],true))throw new RuntimeException('Stripeテスト契約の状態を検証できません。');
  return ['status'=>$status?:'none','updated'=>$updated,'cancellation_pending'=>$pending,'cancellation_at'=>$at];
 }
 public static function stripe_test_portal_url($return){
  if(!self::configured())throw new RuntimeException('先に中央サーバーへ接続してください。');
  if(!is_ssl())throw new RuntimeException('管理画面をHTTPSで開いてください。');
  $s=self::settings();$token=AAIHB_Beta::crypt($s['token'],true);
  $r=wp_safe_remote_post($s['url'].'/v1/stripe/test-portal',['timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode(['return_url'=>$return])]);
  if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Stripeテスト用ポータルを開けません。Stripe側のポータル設定を確認してください。');
  $data=json_decode(wp_remote_retrieve_body($r),true);$url=is_array($data)?($data['portal_url']??''):'';$p=wp_parse_url($url);
  if(($data['mode']??'')!=='test'||!is_string($url)||!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='billing.stripe.com'||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Stripeテスト用ポータルURLを検証できません。');
  return $url;
 }
 public static function url($url){$p=wp_parse_url($url);if(!$p||($p['scheme']??'')!=='https'||!wp_http_validate_url($url)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||(isset($p['port'])&&$p['port']!==443))throw new RuntimeException('公開HTTPSの中央サーバーURLを入力してください。');return rtrim(esc_url_raw($url),'/');}
 public static function enqueue($ignored=null){
  if(!self::configured())return;
  if(!add_option('aaihb_central_lock',time(),'',false)){update_option('aaihb_central_gap',true,false);return;}
  try{
   $ids=array_keys(AAIHB_Beta::sites());sort($ids);$q=get_option('aaihb_central_queue',[]);
   $last=$q?end($q):null;
   if($last&&$last['sites']===$ids)return;
   $seq=(int)get_option('aaihb_central_seq',0)+1;
   $q[]=['sequence'=>$seq,'sites'=>$ids];
   if(!update_option('aaihb_central_queue',$q,false))throw new RuntimeException('利用数の送信待ち記録を保存できません。');
   update_option('aaihb_central_seq',$seq,false);if(!wp_next_scheduled('aaihb_central_drain'))wp_schedule_single_event(time()+10,'aaihb_central_drain');
  }catch(Throwable $e){update_option('aaihb_central_gap',true,false);$s=self::settings();$s['error']='利用数の記録を確認してください。';update_option(self::OPT,$s,false);}
  finally{delete_option('aaihb_central_lock');}
 }
 public static function sync(){
  if(!self::configured())return;
  if(!add_option('aaihb_central_send_lock',time(),'',false))return;
  try{
   $s=self::settings();$q=get_option('aaihb_central_queue',[]);$token=AAIHB_Beta::crypt($s['token'],true);
   $batch=$q?[$q[0]]:[];
   $args=['timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>16000,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json']];
   if($batch){$args['method']='POST';$args['body']=wp_json_encode($batch[0]);$path='/v1/snapshot';}else{$args['method']='GET';$path='/v1/usage';}
   $r=wp_safe_remote_request($s['url'].$path,$args);
   $code=is_wp_error($r)?0:wp_remote_retrieve_response_code($r);
   if($code===401){update_option('aaihb_central_suspended',true,false);throw new RuntimeException('中央サーバーが接続を拒否しました(契約停止または認証情報の誤りの可能性)。解消するまで新しいサイトの登録を停止します。');}
   if(is_wp_error($r)||$code!==200)throw new RuntimeException('中央サーバーに反映できません。接続・認証を確認してください。送信待ちは保持します。');
   update_option('aaihb_central_suspended',false,false);
   $data=json_decode(wp_remote_retrieve_body($r),true);
   if(!is_array($data)||($data['mode']??'')!=='pilot'||($data['billable']??true)!==false)throw new RuntimeException('中央サーバーの応答形式を確認してください。');
   foreach(['current','peak','base_yen','unit_yen','estimate_yen','stale_hubs','sequence'] as $k)if(!isset($data[$k])||!is_int($data[$k])||$data[$k]<0)throw new RuntimeException('集計データの形式が不正です。');
   if(!is_string($data['observed_at']??null)||strtotime($data['observed_at'])===false||$data['peak']<$data['current']||$data['estimate_yen']!==$data['base_yen']+$data['unit_yen']*$data['peak'])throw new RuntimeException('集計値を検証できません。');
   if($batch&&$data['sequence']!==$batch[0]['sequence'])throw new RuntimeException('送信番号が一致しません。');
   if($batch){if(!add_option('aaihb_central_lock',time(),'',false))return;try{$q=get_option('aaihb_central_queue',[]);if($q&&$q[0]['sequence']===$batch[0]['sequence']){array_shift($q);update_option('aaihb_central_queue',$q,false);}}finally{delete_option('aaihb_central_lock');}}
   $s['result']=$data;$s['error']='';update_option(self::OPT,$s,false);
   if($q&&!wp_next_scheduled('aaihb_central_drain'))wp_schedule_single_event(time()+10,'aaihb_central_drain');
  }catch(Throwable $e){$s=self::settings();$s['error']=$e->getMessage();update_option(self::OPT,$s,false);}
  finally{delete_option('aaihb_central_send_lock');}
 }
 public static function save(){AAIHB_Beta::allowed();check_admin_referer('aaihb_central');
  try{
   if(AAIHB_Beta::input('op')==='configure'){
    if(!is_ssl())throw new RuntimeException('管理画面をHTTPSで開いてください。');
    if(self::configured())throw new RuntimeException('接続済みの中央拠点は画面から変更できません。送信待ち・拠点IDの移行確認が必要です。');
    $url=self::url(AAIHB_Beta::input('url'));$token=AAIHB_Beta::input('token');if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('中央サーバーで発行した64桁トークンを入力してください。');
    update_option(self::OPT,['url'=>$url,'token'=>AAIHB_Beta::crypt($token),'result'=>[],'error'=>''],false);
   }
   self::enqueue();self::sync();
  }catch(Throwable $e){$s=self::settings();$s['error']=$e->getMessage();update_option(self::OPT,$s,false);}
  wp_safe_redirect(admin_url('admin.php?page=aaihb-license'));exit;
 }
 public static function page(){AAIHB_Beta::allowed();nocache_headers();$s=self::settings();$r=$s['result'];$q=get_option('aaihb_central_queue',[]);
  echo '<div class="wrap aaihb-app aaihb-selfserve"><header class="ss-hero"><small>ONE PLAN · USAGE</small><h1>使った分を、ひとつに。</h1><p>固定枠をなくし、中央で管理サイト数を合算します。</p></header>';AAIHB_Dashboard::nav('aaihb-license');
  echo '<section class="ss-result"><strong>従量課金の検証モード</strong><p>表示は試算です。支払い・契約の有効化・請求書の送信は行いません。</p></section><div class="ss-grid"><section class="ss-card"><h2>中央で集計した利用数</h2><p style="font-size:32px;font-weight:800">'.($r?number_format($r['current']):'—').' サイト</p><p>当月最大：'.($r?number_format($r['peak']):'—').' サイト</p><p>この管理拠点の登録数：'.AAIHB_Beta::sites_count().'／送信待ち：'.count($q).' 件</p><p>最終観測：'.esc_html($r['observed_at']??'未接続').'</p></section><section class="ss-card"><h2>月額の試算</h2><p style="font-size:32px;font-weight:800">'.($r?number_format($r['estimate_yen']).' 円':'—').'</p><p>基本料金 '.($r?number_format($r['base_yen']):'—').' 円 ＋ 当月最大サイト数 × '.($r?number_format($r['unit_yen']):'—').' 円</p><p>金額は中央サーバーの検証設定です。税・割引・日割りは含みません。</p></section></div>';
  if(get_option('aaihb_central_gap',false))echo '<section class="ss-result">利用記録に欠落の可能性があります。運用者による照合が必要です。</section>';
  if($s['error'])echo '<section class="ss-result" role="status">'.esc_html($s['error']).'</section>';
  if(!empty($r['stale_hubs']))echo '<section class="ss-result">未同期の管理拠点があります。試算を請求に使用しないでください。</section>';
  if(!empty($r['over_spending_cap']))echo '<section class="ss-result">月額試算が支出上限('.number_format($r['spending_cap_yen']??0).' 円)を超えています。利用は継続されます。</section>';
  echo '<section class="ss-card"><h2>'.(self::configured()?'利用数を同期する':'中央サーバーに接続する').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_central">';wp_nonce_field('aaihb_central');
  if(!self::configured())echo '<input type="hidden" name="op" value="configure"><p>中央サーバーを別途起動し、この拠点専用のトークンを発行してください。</p><label>中央サーバーURL<input type="url" name="url" required placeholder="https://usage.example.com"></label><label>拠点トークン<input type="password" name="token" autocomplete="new-password" required></label>';
  else echo '<input type="hidden" name="op" value="sync"><p>送信待ちを1件ずつ順番に反映します。自動処理はWP-Cronに依存します。</p>';
  submit_button('接続・同期を実行');echo '</form></section><section class="ss-card"><h2>この版での数え方</h2><p>同じ顧客の複数拠点を合算します。同じ接続URLから作ったサイトIDは重複を除きます。停止中も登録に含め、接続解除が中央に届くまでは登録中です。月はUTCです。遅れて届いた記録は受信月に計上するため、通信遅延がある月は請求用に確定できません。</p><p>従量プランに10・50・200件の区切りはありません。試作の送信上限は1拠点10万件で、性能保証ではありません。</p></section></div>';
 }
}
