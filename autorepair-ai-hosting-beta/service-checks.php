<?php
/**
 * Safe, site-specific service checks.
 *
 * These checks deliberately prove readiness before they perform a write.  A
 * comment, order, payment, mail, or webhook delivery must never be created on
 * a customer production site merely because a scheduled maintenance job ran.
 */
if (!defined('ABSPATH')) { exit; }

final class AAIHB_ServiceChecks {
    const OPTION = 'aaihb_service_checks';
    const HISTORY = 'aaihb_service_check_history';

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_service_check', [__CLASS__, 'save']);
    }

    public static function allowed() {
        if (!current_user_can('manage_options') || is_multisite()) {
            wp_die('単一サイトの管理者権限が必要です。', '', ['response'=>403]);
        }
    }

    public static function menu() {
        add_submenu_page('aaihb', '業務テスト', '業務テスト', 'manage_options', 'aaihb-service-checks', [__CLASS__, 'page']);
    }

    public static function types() {
        return [
            'wordpress_comment' => ['WordPressコメント', '投稿・DB・コメント受付の準備を確認', '書き込みなし'],
            'contact_form_7' => ['Contact Form 7', 'フォーム用プラグインとテストURLの準備を確認', '書き込みなし'],
            'woocommerce_cart' => ['WooCommerceカート', 'WooCommerceとテスト商品URLの準備を確認', '書き込みなし'],
            'stripe_webhook' => ['Stripe Webhook', 'HTTPSのWebhook受信先とテストモード設定を確認', '書き込みなし'],
        ];
    }

    public static function checks() {
        $saved = get_option(self::OPTION, []);
        $out = [];
        foreach (self::types() as $type => $meta) {
            $row = isset($saved[$type]) && is_array($saved[$type]) ? $saved[$type] : [];
            $out[$type] = [
                'enabled' => !empty($row['enabled']),
                'test_url' => isset($row['test_url']) ? (string)$row['test_url'] : '',
                'allow_write' => !empty($row['allow_write']),
                'last' => isset($row['last']) && is_array($row['last']) ? $row['last'] : null,
            ];
        }
        return $out;
    }

    private static function test_url($value) {
        $url = esc_url_raw(trim((string)$value), ['https']);
        if (!$url) { return ''; }
        $p = wp_parse_url($url); $host = strtolower((string)($p['host'] ?? ''));
        if (!$host || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return ''; }
        return $url;
    }

    private static function is_test_host($url) {
        $host = strtolower((string)(wp_parse_url($url, PHP_URL_HOST) ?: ''));
        // This is intentionally conservative. Public production hosts are not
        // eligible for write-capable automation from this screen.
        return (bool)preg_match('/(^|[.-])(staging|stage|test|dev|local)([.-]|$)|\\.test$|\\.local$/', $host);
    }

    private static function summarize_url($url) {
        $p = wp_parse_url($url);
        if (!$p) { return ''; }
        return ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . ($p['path'] ?? '/');
    }

    private static function http_ready($url) {
        if (!$url) { return ['status'=>'not_configured', 'detail'=>'テストURLが未設定です。']; }
        $response = wp_safe_remote_head($url, ['timeout'=>15, 'redirection'=>0, 'sslverify'=>true]);
        if (is_wp_error($response)) { return ['status'=>'failed', 'detail'=>'HTTPS接続に失敗しました。DNS・SSL・ファイアウォールを確認してください。']; }
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 400) { return ['status'=>'passed', 'detail'=>'HTTPS応答を確認しました（HTTP '.$code.'）。']; }
        return ['status'=>'failed', 'detail'=>'HTTPS応答が正常ではありません（HTTP '.$code.'）。'];
    }

    public static function run($type) {
        $all = self::checks();
        if (!isset($all[$type])) { throw new RuntimeException('テスト種類が不正です。'); }
        $row = $all[$type]; $result = ['at'=>gmdate('c'), 'status'=>'not_configured', 'detail'=>'このテストはまだ有効化されていません。', 'target'=>''];
        if (!$row['enabled']) { self::record($type, $row, $result); return $result; }
        $url = $row['test_url']; $result['target'] = self::summarize_url($url);

        if ($type === 'wordpress_comment') {
            $open = (int)get_option('default_comment_status') === 1 || get_option('default_comment_status') === 'open';
            $post = get_posts(['post_type'=>'post','post_status'=>'publish','comment_status'=>'open','numberposts'=>1,'fields'=>'ids']);
            $result = $post ? ['at'=>gmdate('c'),'status'=>'passed','detail'=>'コメント受付中の公開投稿を確認しました。送信はしていません。','target'=>home_url('/')] : ['at'=>gmdate('c'),'status'=>'failed','detail'=>'コメント受付中の公開投稿が見つかりません。テスト専用の投稿を1件用意してください。','target'=>home_url('/')];
        } elseif ($type === 'contact_form_7') {
            if (!defined('WPCF7_VERSION')) { $result['status']='failed'; $result['detail']='Contact Form 7 が有効ではありません。'; }
            else { $result = array_merge(['at'=>gmdate('c'),'target'=>self::summarize_url($url)], self::http_ready($url)); }
        } elseif ($type === 'woocommerce_cart') {
            if (!class_exists('WooCommerce')) { $result['status']='failed'; $result['detail']='WooCommerce が有効ではありません。'; }
            else { $result = array_merge(['at'=>gmdate('c'),'target'=>self::summarize_url($url)], self::http_ready($url)); }
        } elseif ($type === 'stripe_webhook') {
            $result = array_merge(['at'=>gmdate('c'),'target'=>self::summarize_url($url)], self::http_ready($url));
            if ($result['status'] === 'passed' && !self::is_test_host($url)) { $result['status']='needs_review'; $result['detail']='HTTPS応答は確認できました。本番らしいURLのため、Webhookの送信テストは実行しません。ステージングURLを登録してください。'; }
        }
        if ($row['allow_write']) {
            // A future authenticated target agent may run an explicit write test.
            // For now this evidence makes it clear that no write was made.
            if (!self::is_test_host($url) && $type !== 'wordpress_comment') { $result['status']='needs_review'; $result['detail']='書き込み許可はテスト用ホストでのみ使えます。本番らしいURLでは実行しません。'; }
            else { $result['detail'] .= ' 書き込みテストはこの版では未実装のため実行していません。'; }
        }
        self::record($type, $row, $result); return $result;
    }

    private static function record($type, $row, $result) {
        $row['last'] = ['at'=>(string)$result['at'], 'status'=>sanitize_key($result['status']), 'detail'=>sanitize_text_field($result['detail']), 'target'=>esc_url_raw($result['target'], ['https'])];
        $all = self::checks(); $all[$type] = array_merge($all[$type], $row); update_option(self::OPTION, $all, false);
        $history = get_option(self::HISTORY, []); if (!is_array($history)) { $history=[]; }
        array_unshift($history, ['type'=>$type] + $row['last']); update_option(self::HISTORY, array_slice($history, 0, 100), false);
    }

    public static function save() {
        self::allowed(); check_admin_referer('aaihb_service_check'); $op=AAIHB_Beta::input('op'); $type=AAIHB_Beta::input('type');
        try {
            if (!isset(self::types()[$type])) { throw new RuntimeException('テスト種類が不正です。'); }
            if ($op === 'save') {
                $all=self::checks(); $url=self::test_url(AAIHB_Beta::input('test_url'));
                if (AAIHB_Beta::input('enabled') === '1' && $type !== 'wordpress_comment' && !$url) { throw new RuntimeException('有効にするにはHTTPSのテストURLを入力してください。'); }
                $all[$type]=['enabled'=>AAIHB_Beta::input('enabled')==='1','test_url'=>$url,'allow_write'=>AAIHB_Beta::input('allow_write')==='1','last'=>$all[$type]['last']]; update_option(self::OPTION,$all,false); $message='設定を保存しました。';
            } elseif ($op === 'run') { $r=self::run($type); $message='確認しました：'.$r['detail']; }
            else { throw new RuntimeException('操作が不正です。'); }
        } catch (Throwable $e) { $message=$e->getMessage(); }
        set_transient('aaihb_service_check_notice_'.get_current_user_id(),$message,60); wp_safe_redirect(admin_url('admin.php?page=aaihb-service-checks')); exit;
    }

    public static function page() {
        self::allowed(); nocache_headers(); $checks=self::checks(); $notice=get_transient('aaihb_service_check_notice_'.get_current_user_id()); delete_transient('aaihb_service_check_notice_'.get_current_user_id());
        echo '<div class="wrap aaihb-app"><h1>業務テスト</h1><p>フォーム・購入・外部連携が、保守の前後で使える状態かを確認します。<strong>初期状態では送信・決済・注文・Webhook送信を行いません。</strong></p>';
        if ($notice) { echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; }
        echo '<div class="notice notice-warning"><p><strong>安全ルール：</strong>本番URLでは読み取り確認だけです。送信を伴う試験は、将来の認証済みAgent機能で、ステージング環境・テストキー・明示許可をそろえた場合だけ実行します。</p></div>';
        foreach (self::types() as $type=>$meta) { $row=$checks[$type]; $last=$row['last'];
            echo '<section style="background:#fff;border:1px solid #dcdcde;border-left:5px solid #2271b1;padding:18px;margin:18px 0;max-width:900px"><h2 style="margin-top:0">'.esc_html($meta[0]).'</h2><p>'.esc_html($meta[1]).' ／ <strong>'.esc_html($meta[2]).'</strong></p>';
            if($last){$labels=['passed'=>'確認済み','failed'=>'要確認','not_configured'=>'未設定','needs_review'=>'人の確認が必要'];echo '<p><strong>前回：'.esc_html($labels[$last['status']]??$last['status']).'</strong>（'.esc_html($last['at']).' UTC）<br>'.esc_html($last['detail']).'</p>';}
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_service_check"><input type="hidden" name="op" value="save"><input type="hidden" name="type" value="'.esc_attr($type).'">';wp_nonce_field('aaihb_service_check');
            echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($row['enabled'],true,false).'> このテストを有効にする</label></p>';
            if($type !== 'wordpress_comment'){echo '<p><label>テスト用HTTPS URL（ステージング推奨）<br><input class="large-text code" type="url" name="test_url" placeholder="https://staging.example.test/" value="'.esc_attr($row['test_url']).'"></label></p>';}
            echo '<p><label><input type="checkbox" name="allow_write" value="1" '.checked($row['allow_write'],true,false).'> 将来の書き込み試験を許可する（この版では実行されません）</label></p><p><button class="button">設定を保存</button></p></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_service_check"><input type="hidden" name="op" value="run"><input type="hidden" name="type" value="'.esc_attr($type).'">';wp_nonce_field('aaihb_service_check');submit_button('安全な確認を実行','primary','submit',false);echo '</form></section>';
        }
        echo '<h2>この画面で確認できること</h2><ul><li>コメント：コメント受付可能な公開投稿の有無</li><li>Contact Form 7：プラグイン有効化とテストURLのHTTPS応答</li><li>WooCommerce：プラグイン有効化とテスト商品URLのHTTPS応答</li><li>Stripe：Webhook受信先のHTTPS応答（決済・イベント送信はしません）</li></ul></div>';
    }
}
