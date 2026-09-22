<?php
if(!defined('ABSPATH'))exit;
final class AAIHB_Selfserve {
    const SETTINGS='aaihb_store';
    public static function boot(){add_action('admin_menu',[__CLASS__,'menu']);add_action('admin_post_aaihb_selfserve',[__CLASS__,'save']);add_action('admin_post_aaihb_stripe_test_checkout',[__CLASS__,'stripe_test_checkout']);add_action('admin_post_aaihb_stripe_test_portal',[__CLASS__,'stripe_test_portal']);}
    public static function menu(){foreach(['aaihb-start'=>'かんたん接続','aaihb-buy'=>'購入・契約管理','aaihb-help'=>'困ったとき'] as $slug=>$label)add_submenu_page('aaihb-home',$label,$label,'manage_options',$slug,[__CLASS__,'page']);}
    public static function store(){return wp_parse_args(get_option(self::SETTINGS,[]),['checkout'=>'','portal'=>'','description'=>'','ready'=>false]);}
    public static function link($url){if($url==='')return '';if(strlen($url)>2000||!wp_http_validate_url($url)||wp_parse_url($url,PHP_URL_SCHEME)!=='https'||wp_parse_url($url,PHP_URL_USER)||wp_parse_url($url,PHP_URL_FRAGMENT))throw new RuntimeException('公開HTTPSのURLを入力してください。');return esc_url_raw($url,['https']);}
    public static function configure($checkout,$portal,$description,$ready){AAIHB_Beta::allowed();$c=self::link($checkout);$p=self::link($portal);$d=sanitize_textarea_field($description);if(strlen($d)>3000)throw new RuntimeException('案内は3000バイト以内で入力してください。');if($ready&&(!$c||!$d))throw new RuntimeException('購入先とプラン説明を設定してください。');update_option(self::SETTINGS,['checkout'=>$c,'portal'=>$p,'description'=>$d,'ready'=>(bool)$ready],false);}
    public static function parse($raw){
        if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('接続情報を4096バイト以内で貼り付けてください。');
        $d=json_decode(trim($raw),true);if(!is_array($d)||($d['format']??'')!=='aaihb-connect-1'||!is_string($d['endpoint']??null)||!is_string($d['token']??null)||!is_string($d['name']??null))throw new RuntimeException('対象サイトでコピーした接続情報を、そのまま貼り付けてください。');
        $url=AAIHB_Beta::endpoint($d['endpoint']);if(!$url||!preg_match('/^[a-f0-9]{64}$/D',$d['token']))throw new RuntimeException('公開HTTPSの接続先と64桁のトークンが必要です。');
        return ['endpoint'=>$url,'token'=>$d['token'],'name'=>wp_html_excerpt(sanitize_text_field($d['name']),80,'')];
    }
    public static function connect($raw,$replace){
        AAIHB_Beta::allowed();if(!is_ssl())throw new RuntimeException('管理側もHTTPSで開いてください。');$d=self::parse($raw);$id=hash('sha256',$d['endpoint']);
        if(!add_option('aaihb_registry_lock',time(),'',false))throw new RuntimeException('別の登録が処理中です。少し待ってください。');
        try{
            AAIHB_Billing::meter();
            if(AAIHB_Beta::sites_exists($id)&&!$replace)throw new RuntimeException('登録済みです。更新する場合は「既存の接続情報を置き換える」にチェックしてください。');
            AAIHB_Billing::assert_registration($id);
            $token=AAIHB_Beta::crypt($d['token']);AAIHB_Beta::site_put($id,['name'=>$d['name']?:'登録サイト','endpoint'=>$d['endpoint'],'token'=>$token,'report'=>null,'error'=>'','attempt'=>'']);
            $stored=AAIHB_Beta::site($id);if(!$stored||$stored['token']!==$token)throw new RuntimeException('登録を保存できませんでした。データベースを確認してください。');
            do_action('aaihb_registry_saved',AAIHB_Beta::sites_count());
        }finally{delete_option('aaihb_registry_lock');}
        $r=AAIHB_Beta::scan_site($id);return ['id'=>$id,'ok'=>!is_wp_error($r),'message'=>is_wp_error($r)?$r->get_error_message():'登録・診断データの取得が完了しました。','help'=>is_wp_error($r)?self::category($r->get_error_message()):''];
    }
    public static function category($error){if(preg_match('/HTTP\s+(\d+)/',$error,$m)){return ['403'=>'auth','429'=>'wait','503'=>'dependency','404'=>'url'][$m[1]]??'network';}if(strpos($error,'停止中')!==false)return 'paused';if(strpos($error,'形式')!==false)return 'format';if(strpos($error,'トークン')!==false)return 'auth';return 'network';}
    public static function guides(){return [
        'auth'=>['403・認証できない','対象サイトで接続が有効か確認します。接続情報を再発行した場合は、管理側へ新しい情報を貼り付けて更新します。WAFによる拒否でも403になるため、認証だけが原因とは限りません。'],
        'wait'=>['429・診断が混み合っている','同じサイトの前回診断から60秒以上待ちます。定期診断や別の画面で診断している場合は、重ねて実行しないでください。'],
        'dependency'=>['503・対象サイトの準備を確認','対象サイトのHosting Betaを0.11.0以降へ更新します。旧版の対象サイトではAutoRepair AI本体が必要です。新しい版は内蔵診断を使用します。'],
        'url'=>['404・接続先が見つからない','診断対象の「かんたん接続」で発行した接続情報を使います。公開ページのURLや管理画面のURLをそのまま入力しないでください。'],
        'network'=>['接続・SSL・DNSのエラー','両方のWordPressを公開HTTPSで開けるか確認します。証明書、DNS、WAF、外向き通信制限を確認します。SSL検証を無効にして回避しないでください。ローカル専用URLや内部IPは対象外です。'],
        'paused'=>['診断を停止中','「グループ・予約」で対象サイトを選び、診断を再開してから実行します。'],
        'format'=>['診断データの形式が違う','対象サイトの両プラグインのバージョンと、WAFやログイン画面が応答に混ざっていないか確認します。'],
        'token'=>['コピーした接続情報が見つからない','接続情報は発行直後の画面に一度だけ表示されます。再発行すると古いトークンは失効します。再発行後は接続済みの管理側も更新してください。'],
        'cron'=>['画面を閉じると診断が進まない','ホームの手動診断は画面を開いておきます。閉じて進めたい場合は「グループ・予約」を使います。予約にはWP-Cronの実行が必要です。'],
        'billing'=>['購入したのに表示が変わらない','現在は中央集計の検証モードです。「利用数と料金」で試算を確認できます。購入受付・実請求は開始していません。以前の契約は購入先で確認してください。'],
        'report'=>['報告書をPDFにしたい','顧客向け報告書を開き、ブラウザーの印刷で「PDFに保存」を選択します。社内の対応メモが載るため、内容を確認してから渡してください。'],
        'repair'=>['異常があるのに自動修復されない','「自動修復・復元」で限定修復を利用できます。自動修復は初期OFFで、空の内部URLルールだけが対象です。修復の計画・承認ボタンは記録用です。']
    ];}
    private static function form($op){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_selfserve"><input type="hidden" name="op" value="'.esc_attr($op).'">';wp_nonce_field('aaihb_selfserve');}
    public static function save(){
        AAIHB_Beta::allowed();check_admin_referer('aaihb_selfserve');$page='aaihb-start';$result=[];
        try{
            $op=AAIHB_Beta::input('op');
            if($op==='issue'){
                if(!is_ssl())throw new RuntimeException('HTTPSでこの画面を開いてください。');
                if(AAIHB_Beta::input('confirm')!=='1')throw new RuntimeException('再発行で古い接続情報が失効することを確認してください。');
                $token=bin2hex(random_bytes(32));$s=AAIHB_Beta::settings();$s['agent']=1;$s['hash']=hash('sha256',$token);update_option(AAIHB_Beta::SETTINGS,$s,false);if(AAIHB_Beta::settings()['hash']!==$s['hash'])throw new RuntimeException('接続情報を保存できませんでした。再度お試しください。');
                set_transient('aaihb_pack_'.get_current_user_id(),wp_json_encode(['format'=>'aaihb-connect-1','name'=>get_bloginfo('name'),'endpoint'=>rest_url('aaihb/v1/scan'),'token'=>$token],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),120);
                $result=['message'=>'接続情報を発行しました。下の欄をコピーして管理側へ貼り付けてください。'];
            }elseif($op==='connect'){$result=self::connect(AAIHB_Beta::input('pack'),AAIHB_Beta::input('replace')==='1');}
            elseif($op==='store'){$page='aaihb-buy';self::configure(AAIHB_Beta::input('checkout'),AAIHB_Beta::input('portal'),AAIHB_Beta::input('description'),AAIHB_Beta::input('ready')==='1');$result=['message'=>'購入導線の設定を保存しました。'];}
            else throw new RuntimeException('操作が不正です。');
        }catch(Throwable $e){$result=['message'=>$e->getMessage()];}
        set_transient('aaihb_flow_result_'.get_current_user_id(),$result,120);wp_safe_redirect(admin_url('admin.php?page='.$page));exit;
    }
    public static function stripe_test_checkout(){
        AAIHB_Beta::allowed();check_admin_referer('aaihb_stripe_test_checkout');
        try{
            $base=admin_url('admin.php');$success=add_query_arg(['page'=>'aaihb-buy','aaihb_checkout'=>'success'],$base);$cancel=add_query_arg(['page'=>'aaihb-buy','aaihb_checkout'=>'cancel'],$base);
            $url=AAIHB_Central::test_checkout_url($success,$cancel);wp_redirect($url,302);exit;
        }catch(Throwable $e){set_transient('aaihb_flow_result_'.get_current_user_id(),['message'=>$e->getMessage()],120);wp_safe_redirect(admin_url('admin.php?page=aaihb-buy'));exit;}
    }
    public static function stripe_test_portal(){
        AAIHB_Beta::allowed();check_admin_referer('aaihb_stripe_test_portal');
        try{
            $return=admin_url('admin.php?page=aaihb-buy');$url=AAIHB_Central::stripe_test_portal_url($return);wp_redirect($url,302);exit;
        }catch(Throwable $e){set_transient('aaihb_flow_result_'.get_current_user_id(),['message'=>$e->getMessage()],120);wp_safe_redirect(admin_url('admin.php?page=aaihb-buy'));exit;}
    }
    public static function page(){
        AAIHB_Beta::allowed();nocache_headers();$page=isset($_GET['page'])&&is_string($_GET['page'])?sanitize_key($_GET['page']):'aaihb-start';
        $titles=['aaihb-start'=>['CONNECT IN THREE STEPS','貼り付けて、つなぐ。','診断されるサイトで発行 → 管理側へ貼り付け → 結果を確認'], 'aaihb-buy'=>['YOUR PLAN','購入・契約を、迷わず確認。','購入先の条件を確認してから、お手続きください。'],'aaihb-help'=>['SELF SERVICE HELP','困ったときは、ここから。','表示されたエラーや、やりたいことから手順を探せます。']];$title=$titles[$page]??$titles['aaihb-start'];
        echo '<div class="wrap aaihb-app aaihb-selfserve"><header class="ss-hero"><small>'.$title[0].'</small><h1>'.$title[1].'</h1><p>'.$title[2].'</p></header>';AAIHB_Dashboard::nav($page);
        $r=get_transient('aaihb_flow_result_'.get_current_user_id());delete_transient('aaihb_flow_result_'.get_current_user_id());
        if($r){echo '<section class="ss-result" role="status"><strong>'.esc_html($r['message']).'</strong>';if(isset($r['ok']))echo '<p>'.($r['ok']?'接続テスト成功。異常の有無は「全体を見る」で確認してください。':'登録は保存済みですが、診断は完了していません。').'</p>';if(!empty($r['help']))echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-help#help-'.$r['help'])).'">このエラーの解決手順 →</a>';echo '<p><a href="'.esc_url(admin_url('admin.php?page=aaihb')).'">接続設定・再診断を開く</a></p></section>';}
        if($page==='aaihb-buy')self::buy();elseif($page==='aaihb-help')self::help();else self::start();echo '</div>';
    }
    private static function start(){
        AAIHB_Diagnostics::panel();
        $pack=get_transient('aaihb_pack_'.get_current_user_id());delete_transient('aaihb_pack_'.get_current_user_id());
        echo '<div class="ss-grid"><section class="ss-card"><span class="ss-number">01</span><h2>診断されるサイトで操作</h2><p>対象サイトにもHosting Betaを設置し、この画面を開きます。接続情報には認証トークンが含まれます。管理者以外に渡さないでください。</p><ul class="ss-checks"><li>'.(is_ssl()?'✓':'要確認').' HTTPSの管理画面</li><li>'.(class_exists('AAIHB_Diagnostics')?'✓':'要確認').' Hosting Beta内蔵診断（本体不要）</li></ul>';
        if($pack){echo '<label for="ss-pack">今回だけ表示する接続情報</label><textarea id="ss-pack" readonly autocomplete="off" spellcheck="false">'.esc_textarea($pack).'</textarea><button type="button" class="button button-primary" data-copy="ss-pack">接続情報をまとめてコピー</button><p>画面を閉じる前にコピーしてください。ブラウザー内に自動保存しません。</p>';}
        self::form('issue');echo '<p><label><input required type="checkbox" name="confirm" value="1">発行すると古い接続情報が失効することを確認しました</label></p>';submit_button('接続情報を発行する','secondary');echo '</form></section><section class="ss-card"><span class="ss-number">02</span><h2>まとめて管理する側で操作</h2><p>対象サイトでコピーした情報を、下の欄へ一度だけ貼り付けます。URLとトークンを別々に入力する必要はありません。</p>';
        self::form('connect');echo '<label for="ss-import">対象サイトの接続情報</label><textarea id="ss-import" name="pack" required autocomplete="off" spellcheck="false" placeholder="ここに接続情報を貼り付け"></textarea><p id="ss-import-preview" aria-live="polite"></p><p><label><input type="checkbox" name="replace" value="1">登録済みの場合、既存の接続情報を置き換える</label></p>';submit_button('登録して接続テスト');echo '</form></section></div><section class="ss-card"><span class="ss-number">03</span><h2>結果を確認して、運用を始める</h2><p>接続成功は診断データを取得できた意味です。異常がないことや、修復が完了したこととは異なります。</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-home')).'">全体を見る →</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-help')).'">うまく接続できない場合</a><p>対象サイトが旧版の場合は、従来の「接続設定」でURLとトークンを別々に登録できます。</p></section><p id="ss-copy-status" role="status"></p>';
    }
    private static function buy(){
        $state=isset($_GET['aaihb_checkout'])&&is_string($_GET['aaihb_checkout'])?sanitize_key($_GET['aaihb_checkout']):'';
        if($state==='success')echo '<section class="ss-result" role="status"><strong>Stripeのテスト決済が完了しました。</strong><p>Webhookの受信処理には少し時間がかかる場合があります。実際の請求は発生しません。</p></section>';
        elseif($state==='cancel')echo '<section class="ss-result" role="status"><strong>テスト決済を中止しました。</strong><p>実際の請求は発生していません。</p></section>';
        echo '<section class="ss-card"><h2>Stripeテスト決済</h2><p>テストモード専用です。実際のカード請求は発生しません。中央サーバーとの接続後、StripeのテストカードでWebhook受信まで確認できます。</p>';
        if(AAIHB_Central::configured())try{$stripe=AAIHB_Central::stripe_test_status();$labels=['active'=>'テスト契約は有効です','trialing'=>'テストトライアル中です','past_due'=>'テスト決済の確認が必要です','unpaid'=>'テスト決済は未払いです','canceled'=>'テスト契約はキャンセル済みです','incomplete'=>'テスト契約の処理中です','incomplete_expired'=>'テスト契約の期限が切れました','none'=>'テスト契約はまだありません'];echo '<p><strong>現在の状態：'.esc_html($labels[$stripe['status']]??'確認できません').'</strong></p>';if($stripe['cancellation_pending']&&$stripe['cancellation_at'])echo '<p><strong>キャンセル予定：'.esc_html(wp_date('Y年n月j日',$stripe['cancellation_at'])).'に終了します</strong></p>';if($stripe['updated'])echo '<p>最終更新：'.esc_html($stripe['updated']).'</p>';if($stripe['status']!=='none'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_stripe_test_portal">';wp_nonce_field('aaihb_stripe_test_portal');submit_button('Stripeテスト用ポータルを開く','secondary','submit',false);echo '</form>';}}catch(Throwable $e){echo '<p>現在の状態：取得できません。しばらく待って再読み込みしてください。</p>';}
        if(AAIHB_Central::configured()){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_stripe_test_checkout">';wp_nonce_field('aaihb_stripe_test_checkout');submit_button('Stripeテスト決済を開始する','primary','submit',false);echo '</form>';}else echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-license')).'">先に中央サーバーへ接続する →</a></p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-license')).'">利用数と料金を見る →</a></p></section>';
    }
    private static function help(){
        echo '<section class="ss-card"><label for="ss-search">困っていることを検索</label><input id="ss-search" type="search" placeholder="例：403、トークン、購入、PDF"><p id="ss-help-count" aria-live="polite"></p></section><div class="ss-faq">';foreach(self::guides() as $id=>$g)echo '<details class="ss-card" id="help-'.$id.'"><summary>'.esc_html($g[0]).'</summary><p>'.esc_html($g[1]).'</p></details>';echo '</div><section class="ss-card"><h2>解決しない場合：確認用メモをコピー</h2><p>サイトURL・トークン・顧客名は含めません。下の環境情報に、試した操作とエラー番号を添えて販売元へ問い合わせてください。送信は自動では行いません。</p><textarea readonly id="ss-support">'.esc_textarea('Hosting Beta 0.14.2 / WordPress '.get_bloginfo('version').' / PHP '.PHP_VERSION.' / HTTPS '.(is_ssl()?'yes':'no').' / 登録サイト '.AAIHB_Beta::sites_count()).'</textarea><button type="button" class="button" data-copy="ss-support">確認用メモをコピー</button><p id="ss-copy-status" role="status"></p></section>';
    }
}
