<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only REST API for a hosting-company partner's own system to pull
 * site status and case data, instead of a human clicking through wp-admin.
 * Nothing here can write data; registration/scanning/case changes still
 * require the existing admin screens.
 */
final class AAIHB_Partner {
    const SETTINGS = 'aaihb_partner_settings';
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_partner', [__CLASS__, 'save']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }
    public static function settings() {
        return wp_parse_args(get_option(self::SETTINGS, []), ['enabled'=>false, 'hash'=>'']);
    }
    public static function menu() {
        add_submenu_page('aaihb-home', 'パートナーAPI', 'パートナーAPI', 'manage_options', 'aaihb-partner', [__CLASS__, 'page']);
    }
    public static function routes() {
        register_rest_route('aaihb/v1', '/partner/sites', ['methods'=>'GET', 'permission_callback'=>[__CLASS__, 'authenticate'], 'callback'=>[__CLASS__, 'api_sites']]);
        register_rest_route('aaihb/v1', '/partner/cases', ['methods'=>'GET', 'permission_callback'=>[__CLASS__, 'authenticate'], 'callback'=>[__CLASS__, 'api_cases']]);
    }
    public static function authenticate($request) {
        $s = self::settings();
        if (is_multisite() || !is_ssl() || !$s['enabled'] || !$s['hash']) { return new WP_Error('aaihb_partner_denied', 'パートナーAPIは無効です。', ['status'=>403]); }
        $key = (string)$request->get_header('x-aaihb-partner-key');
        if (!$key || !hash_equals($s['hash'], hash('sha256', $key))) { return new WP_Error('aaihb_partner_denied', '認証できません。', ['status'=>403]); }
        return true;
    }
    private static function page_param($request) {
        $page = $request->get_param('page');
        return ($page !== null && is_scalar($page) && ctype_digit((string)$page) && (int)$page >= 1) ? (int)$page : 1;
    }
    public static function api_sites($request) {
        $per_page = 50; $page = self::page_param($request);
        $total = AAIHB_Beta::sites_count();
        $sites = AAIHB_Beta::sites_page(($page - 1) * $per_page, $per_page);
        $items = [];
        foreach ($sites as $id => $s) {
            $items[] = ['id'=>$id, 'name'=>$s['name'], 'status'=>$s['error'] ? 'error' : ($s['report'] ? 'ok' : 'unknown'), 'score'=>$s['report']['score'] ?? null, 'generated_at'=>$s['report']['generated_at'] ?? null, 'error'=>$s['error'] ?: null];
        }
        $out = new WP_REST_Response(['items'=>$items, 'total'=>$total, 'page'=>$page, 'per_page'=>$per_page, 'pages'=>max(1, (int)ceil($total / $per_page))]);
        $out->header('Cache-Control', 'no-store');
        return $out;
    }
    public static function api_cases($request) {
        $filter = [];
        foreach (['search', 'status', 'severity', 'overdue'] as $key) {
            $value = $request->get_param($key);
            if ($value !== null && !is_scalar($value)) { return new WP_Error('invalid_filter', '検索条件が不正です。', ['status'=>400]); }
            $filter[$key] = $value === null ? '' : sanitize_text_field((string)$value);
        }
        $page = self::page_param($request);
        $q = AAIHB_Operations::query($filter, $page);
        $items = [];
        foreach ($q->posts as $p) {
            $d = AAIHB_Value::case_data($p->ID); $s = AAIHB_Operations::state($p->ID);
            $items[] = ['id'=>(int)$p->ID, 'title'=>$p->post_title, 'check'=>$d['check'], 'severity'=>$d['severity'], 'open'=>(bool)$d['signal_open'], 'last_observed'=>$d['last'], 'status'=>$s['status'], 'due'=>$s['due'] ?: null];
        }
        $out = new WP_REST_Response(['items'=>$items, 'total'=>(int)$q->found_posts, 'page'=>$page, 'pages'=>(int)$q->max_num_pages]);
        $out->header('Cache-Control', 'no-store');
        return $out;
    }
    private static function form($op) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_partner"><input type="hidden" name="op" value="' . esc_attr($op) . '">'; wp_nonce_field('aaihb_partner');
    }
    public static function save() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_partner');
        try {
            $op = AAIHB_Beta::input('op'); $s = self::settings();
            if ($op === 'rotate') {
                if (!is_ssl()) { throw new RuntimeException('HTTPSで管理画面を開いてください。'); }
                $key = bin2hex(random_bytes(32));
                $s['hash'] = hash('sha256', $key); $s['enabled'] = true;
                update_option(self::SETTINGS, $s, false);
                set_transient('aaihb_partner_key_' . get_current_user_id(), $key, 120);
            } elseif ($op === 'revoke') {
                $s['hash'] = ''; $s['enabled'] = false;
                update_option(self::SETTINGS, $s, false);
                delete_transient('aaihb_partner_key_' . get_current_user_id());
            } else { throw new RuntimeException('操作が不正です。'); }
            set_transient('aaihb_partner_notice_' . get_current_user_id(), '保存しました。', 60);
        } catch (Throwable $e) { set_transient('aaihb_partner_notice_' . get_current_user_id(), $e->getMessage(), 60); }
        wp_safe_redirect(admin_url('admin.php?page=aaihb-partner')); exit;
    }
    public static function page() {
        AAIHB_Beta::allowed(); nocache_headers();
        $s = self::settings();
        $notice = get_transient('aaihb_partner_notice_' . get_current_user_id()); delete_transient('aaihb_partner_notice_' . get_current_user_id());
        $key = get_transient('aaihb_partner_key_' . get_current_user_id()); delete_transient('aaihb_partner_key_' . get_current_user_id());
        echo '<div class="wrap aaihb-app aaihb-workspace"><h1>パートナーAPI</h1>'; AAIHB_Dashboard::nav('aaihb-partner');
        echo '<p>保守会社が自社の管理画面から、このWordPress管理画面を開かずにサイト状態・案件一覧を取得するための読み取り専用APIです。登録・診断・案件の変更はここからはできません。</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        echo '<p>状態：' . ($s['enabled'] ? '有効' : '無効') . '</p>';
        if ($key) { echo '<p><strong>今回だけ表示するAPIキー（コピーして保管）</strong><br><code>' . esc_html($key) . '</code></p>'; }
        self::form('rotate'); submit_button('APIキーを発行（旧キーは失効）', 'secondary'); echo '</form>';
        self::form('revoke'); submit_button('APIを無効化', 'secondary'); echo '</form>';
        echo '<h2>使い方</h2><p>リクエストヘッダーに <code>X-AAIHB-Partner-Key: 発行したキー</code> を付けて、HTTPSでGETしてください。</p>';
        echo '<table class="widefat striped"><thead><tr><th>エンドポイント</th><th>内容</th></tr></thead><tbody>';
        echo '<tr><td><code>' . esc_html(rest_url('aaihb/v1/partner/sites?page=1')) . '</code></td><td>登録サイトの状態一覧（50件ずつページ送り）</td></tr>';
        echo '<tr><td><code>' . esc_html(rest_url('aaihb/v1/partner/cases?page=1')) . '</code></td><td>対応案件一覧（status・severity・search・overdueで絞り込み可）</td></tr>';
        echo '</tbody></table><p>トークン・パスワード等の秘匿情報は一切返しません。</p></div>';
    }
}
