<?php
if (!defined('ABSPATH')) { exit; }

/** Fires at most one email/Slack alert per open critical case; resets when the case recovers. */
final class AAIHB_Notify {
    const SETTINGS = 'aaihb_notify_settings';
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_notify', [__CLASS__, 'save']);
        add_action('admin_post_aaihb_notify_clear', [__CLASS__, 'clear_slack']);
        add_action('admin_post_aaihb_notify_clear_webhook', [__CLASS__, 'clear_webhook']);
        // Runs after AAIHB_Value::observe (10) has created/updated case posts for this scan.
        add_action('aaihb_observation', [__CLASS__, 'maybe_notify'], 25, 4);
    }
    public static function settings() {
        return wp_parse_args(get_option(self::SETTINGS, []), ['enabled'=>false, 'email'=>'', 'slack'=>'', 'webhook'=>'']);
    }
    public static function menu() {
        add_submenu_page('aaihb-home', '通知設定', '通知設定', 'manage_options', 'aaihb-notify', [__CLASS__, 'page']);
    }
    private static function case_id_for($key) {
        $found = get_posts(['post_type'=>AAIHB_Value::CASE_TYPE, 'post_status'=>'private', 'numberposts'=>1, 'meta_key'=>'aaihb_key', 'meta_value'=>$key, 'orderby'=>'ID', 'order'=>'DESC']);
        return $found ? (int)$found[0]->ID : 0;
    }
    public static function maybe_notify($site, $name, $report, $error) {
        $s = self::settings();
        if (!$s['enabled'] || (!$s['email'] && !$s['slack'] && !$s['webhook']) || $error || !$report) { return; }
        $newly = [];
        foreach ($report['checks'] as $check) {
            $id = self::case_id_for($site . ':' . $check['id']);
            if (!$id) { continue; }
            if ($check['status'] === 'critical') {
                if (get_post_meta($id, 'aaihb_notified', true)) { continue; }
                update_post_meta($id, 'aaihb_notified', 1);
                $newly[] = ['id'=>$id, 'title'=>$name . ' / ' . AAIHB_Value::guide($check['id'])[0]];
            } else {
                // Recovery clears the flag so a future recurrence notifies again.
                delete_post_meta($id, 'aaihb_notified');
            }
        }
        if ($newly) { self::send($s, $newly); }
    }
    private static function send($s, $cases) {
        $lines = array_map(function($c) { return '#' . $c['id'] . ' ' . $c['title']; }, $cases);
        $body = "重大な異常を検知しました（同じ案件は正常化するまで再通知しません）。\n\n" . implode("\n", $lines) . "\n\n対応画面：" . admin_url('admin.php?page=aaihb-ops');
        if ($s['email']) {
            $addrs = array_filter(array_map('trim', explode(',', $s['email'])), 'is_email');
            if ($addrs) { wp_mail($addrs, '[AutoRepair AI] 重大な異常を検知しました', $body); }
        }
        if ($s['slack']) {
            try {
                $url = AAIHB_Beta::crypt($s['slack'], true);
                if ($url && wp_http_validate_url($url) && wp_parse_url($url, PHP_URL_SCHEME) === 'https') {
                    wp_safe_remote_post($url, ['timeout'=>10, 'sslverify'=>true, 'headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode(['text'=>$body])]);
                }
            } catch (Throwable $e) { /* Slack delivery is best-effort; email above already carries the alert. */ }
        }
        if ($s['webhook']) {
            try {
                $url = AAIHB_Beta::crypt($s['webhook'], true);
                if ($url && wp_http_validate_url($url) && wp_parse_url($url, PHP_URL_SCHEME) === 'https') {
                    // Structured payload for a partner's own system, distinct from Slack's {text:...} shape.
                    $payload = ['event'=>'critical_case_detected', 'at'=>gmdate('c'), 'cases'=>array_map(function($c) { return ['id'=>$c['id'], 'title'=>$c['title']]; }, $cases)];
                    wp_safe_remote_post($url, ['timeout'=>10, 'sslverify'=>true, 'headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode($payload)]);
                }
            } catch (Throwable $e) { /* Webhook delivery is best-effort; email above already carries the alert. */ }
        }
    }
    private static function form($action) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">'; wp_nonce_field($action);
    }
    public static function save() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_notify');
        try {
            $s = self::settings();
            $s['enabled'] = AAIHB_Beta::input('enabled') === '1';
            $addrs = array_values(array_filter(array_map('trim', explode(',', AAIHB_Beta::input('email')))));
            foreach ($addrs as $a) { if (!is_email($a)) { throw new RuntimeException('メールアドレスの形式を確認してください：' . $a); } }
            $s['email'] = implode(',', $addrs);
            $slack = AAIHB_Beta::input('slack');
            if ($slack !== '') {
                if (!wp_http_validate_url($slack) || wp_parse_url($slack, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('Slack Webhook URLはHTTPSの有効なURLにしてください。'); }
                $s['slack'] = AAIHB_Beta::crypt($slack);
            }
            $webhook = AAIHB_Beta::input('webhook');
            if ($webhook !== '') {
                if (!wp_http_validate_url($webhook) || wp_parse_url($webhook, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('汎用Webhook URLはHTTPSの有効なURLにしてください。'); }
                $s['webhook'] = AAIHB_Beta::crypt($webhook);
            }
            update_option(self::SETTINGS, $s, false);
            set_transient('aaihb_notify_notice_' . get_current_user_id(), '保存しました。', 60);
        } catch (Throwable $e) { set_transient('aaihb_notify_notice_' . get_current_user_id(), $e->getMessage(), 60); }
        wp_safe_redirect(admin_url('admin.php?page=aaihb-notify')); exit;
    }
    public static function clear_slack() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_notify_clear');
        $s = self::settings(); $s['slack'] = ''; update_option(self::SETTINGS, $s, false);
        set_transient('aaihb_notify_notice_' . get_current_user_id(), 'Slack Webhookを削除しました。', 60);
        wp_safe_redirect(admin_url('admin.php?page=aaihb-notify')); exit;
    }
    public static function clear_webhook() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_notify_clear_webhook');
        $s = self::settings(); $s['webhook'] = ''; update_option(self::SETTINGS, $s, false);
        set_transient('aaihb_notify_notice_' . get_current_user_id(), '汎用Webhookを削除しました。', 60);
        wp_safe_redirect(admin_url('admin.php?page=aaihb-notify')); exit;
    }
    public static function page() {
        AAIHB_Beta::allowed(); nocache_headers();
        $s = self::settings();
        $notice = get_transient('aaihb_notify_notice_' . get_current_user_id()); delete_transient('aaihb_notify_notice_' . get_current_user_id());
        echo '<div class="wrap aaihb-app aaihb-workspace"><h1>重大な異常の通知</h1>'; AAIHB_Dashboard::nav('aaihb-notify');
        echo '<p>重大な異常が新しく検知された案件について、最初の1回だけメール・Slackへ通知します。その案件が正常化するまで再通知しません。診断そのものは定期診断またはグループ予約で実行してください。</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        self::form('aaihb_notify');
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked($s['enabled'], true, false) . '>通知を有効にする</label></p>';
        echo '<p><label>通知先メールアドレス（カンマ区切りで複数可）<br><input class="regular-text" name="email" value="' . esc_attr($s['email']) . '"></label></p>';
        echo '<p><label>Slack Webhook URL（HTTPSのみ。' . ($s['slack'] ? '設定済み。変更する場合のみ入力' : '未設定') . '）<br><input class="regular-text" type="password" autocomplete="off" name="slack" placeholder="https://hooks.slack.com/services/..."></label></p>';
        echo '<p><label>汎用Webhook URL（HTTPSのみ。パートナー側システム向けにJSON構造体をPOSTします。' . ($s['webhook'] ? '設定済み。変更する場合のみ入力' : '未設定') . '）<br><input class="regular-text" type="password" autocomplete="off" name="webhook" placeholder="https://partner.example.com/webhooks/autorepair"></label></p>';
        echo '<p class="description">汎用Webhookの送信内容：<code>{"event":"critical_case_detected","at":"...","cases":[{"id":123,"title":"..."}]}</code></p>';
        submit_button('通知設定を保存');
        echo '</form>';
        if ($s['slack']) { self::form('aaihb_notify_clear'); submit_button('Slack Webhookを削除', 'secondary'); echo '</form>'; }
        if ($s['webhook']) { self::form('aaihb_notify_clear_webhook'); submit_button('汎用Webhookを削除', 'secondary'); echo '</form>'; }
        echo '</div>';
    }
}
