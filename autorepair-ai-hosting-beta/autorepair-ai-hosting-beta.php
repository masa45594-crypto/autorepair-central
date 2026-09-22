<?php
/**
 * Plugin Name: AutoRepair AI Hosting Beta
 * Description: Independent diagnostics, recovery and isolated restore rehearsal with separately verified backup status.
 * Version: 0.20.6
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: AutoRepair AI
 * License: GPL-2.0-or-later
 * Text Domain: autorepair-ai-hosting-beta
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/recovery.php';
AAIHB_Recovery::boot();
require_once __DIR__ . '/diagnostics.php';
AAIHB_Diagnostics::boot();
require_once __DIR__ . '/central.php';
AAIHB_Central::boot();
require_once __DIR__ . '/billing.php';
AAIHB_Billing::boot();


final class AAIHB_Beta {
    const SITES = 'aaihb_sites';
    const SETTINGS = 'aaihb_settings';
    const DB_VERSION = '1';
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_save', [__CLASS__, 'save']);
        add_action('wp_ajax_aaihb_scan', [__CLASS__, 'ajax_scan']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('plugins_loaded', [__CLASS__, 'upgrade']);
    }
    /**
     * A registered-sites table indexed on id/updated_at, replacing the earlier
     * single-option array so registries can grow past what one wp_options row
     * can hold and so reads can be counted/paginated instead of loaded whole.
     */
    public static function table() {
        global $wpdb; return $wpdb->prefix . 'aaihb_sites';
    }
    public static function upgrade() {
        // Runs on every request but only does work once per version; zip-upload
        // updates never fire register_activation_hook, so this is the real trigger.
        if (get_option('aaihb_db_version') === self::DB_VERSION) { return; }
        if (!add_option('aaihb_upgrade_lock', time(), '', false)) { return; }
        try {
            self::install();
            self::migrate_legacy_sites();
            update_option('aaihb_db_version', self::DB_VERSION, false);
        } finally { delete_option('aaihb_upgrade_lock'); }
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id CHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            endpoint VARCHAR(500) NOT NULL DEFAULT '',
            token TEXT NOT NULL,
            report LONGTEXT NULL,
            error TEXT NOT NULL,
            attempt VARCHAR(32) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY updated_at (updated_at)
        ) $charset_collate;";
        dbDelta($sql);
    }
    /** One-time copy from the legacy option into the table. The option is left in place as a safety net. */
    public static function migrate_legacy_sites() {
        $legacy = get_option(self::SITES, []);
        if (!is_array($legacy) || !$legacy) { return; }
        foreach ($legacy as $id => $s) {
            if (!is_string($id) || self::site($id)) { continue; }
            self::site_put($id, wp_parse_args($s, ['name'=>'', 'endpoint'=>'', 'token'=>'', 'report'=>null, 'error'=>'', 'attempt'=>'']));
        }
    }
    private static function row_to_site($row) {
        return ['name'=>$row->name, 'endpoint'=>$row->endpoint, 'token'=>$row->token, 'report'=>$row->report !== null ? json_decode($row->report, true) : null, 'error'=>$row->error, 'attempt'=>$row->attempt];
    }
    public static function site($id) {
        global $wpdb;
        if (!is_string($id) || $id === '') { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id));
        return $row ? self::row_to_site($row) : null;
    }
    /** Full registry as an id => site array, for call sites that still need to iterate everything. */
    public static function sites() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC');
        $out = [];
        foreach ((array)$rows as $row) { $out[$row->id] = self::row_to_site($row); }
        return $out;
    }
    public static function sites_count() {
        global $wpdb; return (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
    }
    public static function sites_exists($id) {
        global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . self::table() . ' WHERE id = %s', $id));
    }
    /** Indexed, offset-based page for admin screens and the partner API; never loads the whole registry. */
    public static function sites_page($offset, $limit) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d', max(1,(int)$limit), max(0,(int)$offset)));
        $out = [];
        foreach ((array)$rows as $row) { $out[$row->id] = self::row_to_site($row); }
        return $out;
    }
    public static function site_put($id, $data) {
        global $wpdb; $table = self::table(); $now = gmdate('Y-m-d H:i:s');
        $fields = ['name'=>(string)$data['name'], 'endpoint'=>(string)$data['endpoint'], 'token'=>(string)$data['token'], 'report'=>$data['report'] !== null ? wp_json_encode($data['report']) : null, 'error'=>(string)$data['error'], 'attempt'=>(string)$data['attempt'], 'updated_at'=>$now];
        if (self::sites_exists($id)) { $wpdb->update($table, $fields, ['id'=>$id]); }
        else { $fields['id'] = $id; $fields['created_at'] = $now; $wpdb->insert($table, $fields); }
    }
    public static function site_delete($id) {
        global $wpdb; $wpdb->delete(self::table(), ['id'=>$id]);
    }
    public static function settings() {
        return wp_parse_args(get_option(self::SETTINGS, []), ['company'=>'', 'service'=>'AutoRepair AI Hosting Beta', 'logo'=>'', 'agent'=>0, 'hash'=>'', 'accent'=>'#2271b1', 'hide_branding'=>0]);
    }
    public static function menu() {
        add_menu_page('Hosting Beta', 'Hosting Beta', 'manage_options', 'aaihb', [__CLASS__, 'page'], 'dashicons-networking');
    }
    public static function allowed() {
        if (!current_user_can('manage_options') || is_multisite()) { wp_die('単一サイトの管理者権限が必要です。'); }
    }
    public static function input($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : '';
    }
    public static function endpoint($url) {
        $p = wp_parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) { return false; }
        $host = trim(strtolower($p['host']), '[]');
        if ($host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) { return false; }
        // Use the exact Agent endpoint displayed on that installation, including subdirectory/plain permalinks.
        parse_str($p['query'] ?? '', $query);
        $route = '/aaihb/v1/scan';
        if (isset($p['query'])) {
            if (count($query) !== 1 || ($query['rest_route'] ?? '') !== $route) { return false; }
        } elseif (substr(rtrim($p['path'] ?? '', '/'), -strlen($route)) !== $route) { return false; }
        return wp_http_validate_url($url) ? esc_url_raw($url) : false;
    }
    public static function crypt($value, $decrypt = false) {
        if (!function_exists('openssl_encrypt')) { throw new RuntimeException('OpenSSL拡張が必要です。'); }
        $key = hash('sha256', wp_salt('auth') . '|aaihb', true);
        if (!$decrypt) {
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) { throw new RuntimeException('トークンの暗号化に失敗しました。'); }
            return base64_encode($iv . $tag . $cipher);
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) { throw new RuntimeException('トークンを再登録してください。'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new RuntimeException('トークンを再登録してください。'); }
        return $plain;
    }
    public static function save() {
        self::allowed(); check_admin_referer('aaihb_save');
        $op = self::input('op'); $s = self::settings();
        try {
            if ($op === 'settings') {
                $s['company'] = sanitize_text_field(self::input('company'));
                $s['service'] = sanitize_text_field(self::input('service')) ?: 'AutoRepair AI Hosting Beta';
                $s['logo'] = esc_url_raw(self::input('logo'), ['https']);
                $accent = self::input('accent');
                if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/D', $accent)) { throw new RuntimeException('強調色は#rrggbb形式で入力してください。'); }
                $s['accent'] = $accent ?: '#2271b1';
                $s['hide_branding'] = self::input('hide_branding') === '1' ? 1 : 0;
                update_option(self::SETTINGS, $s, false);
            } elseif ($op === 'rotate') {
                if (!is_ssl()) { throw new RuntimeException('HTTPSで管理画面を開いてください。'); }
                $token = bin2hex(random_bytes(32));
                $s['hash'] = hash('sha256', $token); $s['agent'] = 1;
                update_option(self::SETTINGS, $s, false);
                set_transient('aaihb_token_' . get_current_user_id(), $token, 120);
            } elseif ($op === 'revoke') {
                $s['hash'] = ''; $s['agent'] = 0;
                update_option(self::SETTINGS, $s, false);
                delete_transient('aaihb_token_' . get_current_user_id());
            } elseif ($op === 'add' || $op === 'delete') {
                // Serialize registry mutations so concurrent additions cannot exceed the limit.
                if (!add_option('aaihb_registry_lock', time(), '', false)) { throw new RuntimeException('他の登録処理が実行中です。少し待って再試行してください。'); }
                try {
                    AAIHB_Billing::meter();
                    if ($op === 'delete') { self::site_delete(self::input('id')); }
                    else {
                        $url = self::endpoint(self::input('endpoint')); $token = self::input('token');
                        if (!$url || !preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new RuntimeException('Agent画面のHTTPS接続先と64桁トークンを入力してください。内部IPは使用できません。'); }
                        $id = hash('sha256', $url);
                        AAIHB_Billing::assert_registration($id);
                        self::site_put($id, ['name'=>sanitize_text_field(self::input('name')) ?: $url, 'endpoint'=>$url, 'token'=>self::crypt($token), 'report'=>null, 'error'=>'', 'attempt'=>'']);
                    }
                    do_action('aaihb_registry_saved', self::sites_count());
                } finally { delete_option('aaihb_registry_lock'); }
            }
            set_transient('aaihb_notice_' . get_current_user_id(), '保存しました。', 60);
        } catch (Throwable $e) {
            set_transient('aaihb_notice_' . get_current_user_id(), $e->getMessage(), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=aaihb')); exit;
    }
    public static function routes() {
        register_rest_route('aaihb/v1', '/scan', ['methods'=>'POST', 'permission_callback'=>[__CLASS__, 'authenticate'], 'callback'=>[__CLASS__, 'agent_scan']]);
    }
    public static function authenticate($request) {
        $s = self::settings(); $token = $request->get_header('x-aaihb-token');
        if (is_multisite() || !is_ssl() || !$s['agent'] || !$s['hash'] || !preg_match('/^[a-f0-9]{64}$/D', (string)$token) || !hash_equals($s['hash'], hash('sha256', $token))) {
            return new WP_Error('aaihb_denied', '接続が許可されていません。', ['status'=>403]);
        }
        return true;
    }
    public static function agent_scan() {
        $last = (int)get_option('aaihb_last_scan', 0);
        if (time() - $last < 60 || !add_option('aaihb_scan_lock', time(), '', false)) {
            return new WP_Error('aaihb_busy', '診断中です。60秒以上待ってください。', ['status'=>429]);
        }
        try {
            update_option('aaihb_last_scan', time(), false);
            $engine = new AAIHB_Diagnostics();
            $raw = $engine->run(); $checks = [];
            // Never transmit raw error logs, paths, plugin lists, content, or user data.
            foreach ($raw['checks'] as $c) {
                $checks[] = ['id'=>sanitize_key($c['id']), 'status'=>in_array($c['status'], ['healthy','warning','critical'], true) ? $c['status'] : 'warning'];
            }
            $out = new WP_REST_Response(['protocol'=>1, 'score'=>(int)$raw['score'], 'checks'=>$checks, 'generated_at'=>gmdate('c')]);
            $out->header('Cache-Control', 'no-store');
            return $out;
        } catch (Throwable $e) {
            return new WP_Error('aaihb_failed', '診断を完了できませんでした。対象サイトを確認してください。', ['status'=>500]);
        } finally { delete_option('aaihb_scan_lock'); }
    }
    public static function validate_report($r) {
        if (!is_array($r) || ($r['protocol'] ?? null) !== 1 || !isset($r['score']) || !is_int($r['score']) || $r['score'] < 0 || $r['score'] > 100 || !isset($r['checks']) || !is_array($r['checks']) || count($r['checks']) !== 10 || !is_string($r['generated_at'] ?? null) || !strtotime($r['generated_at'])) { return false; }
        $ids = ['home','rest_api','database','https','disk','filesystem','cron','maintenance','updates','debug_log'];
        foreach ($r['checks'] as $c) {
            if (!is_array($c) || !in_array($c['id'] ?? '', $ids, true) || !in_array($c['status'] ?? '', ['healthy','warning','critical'], true)) { return false; }
            $ids = array_values(array_diff($ids, [$c['id']]));
        }
        return !$ids;
    }
    public static function ajax_scan() {
        self::allowed(); check_ajax_referer('aaihb_scan');
        $result = self::scan_site(self::input('id'));
        if (is_wp_error($result)) { wp_send_json_error($result->get_error_message()); }
        wp_send_json_success($result);
    }
    public static function scan_site($id) {
        $license_error = AAIHB_Billing::scan_error(); if ($license_error) return $license_error;
        if (AAIHB_Fleet::paused($id)) { return new WP_Error('paused','このサイトは診断停止中です。グループ設定で再開してください。'); }
        $entry = self::site($id);
        if (!$entry) { return new WP_Error('missing', '登録が見つかりません。'); }
        $report = null; $error = '';
        try {
            $url = self::endpoint($entry['endpoint']);
            if (!$url) { throw new RuntimeException('接続先URLを確認してください。'); }
            $response = wp_safe_remote_post($url, ['timeout'=>45, 'redirection'=>0, 'sslverify'=>true, 'limit_response_size'=>16384, 'headers'=>['X-AAIHB-Token'=>self::crypt($entry['token'], true), 'Accept'=>'application/json']]);
            if (is_wp_error($response)) { throw new RuntimeException('接続できません。SSL・DNS・ファイアウォールを確認してください。'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new RuntimeException('HTTP ' . $code . '：403はトークン、429は診断間隔、503は対象側Hosting Betaの状態・バージョンを確認してください。'); }
            $r = json_decode(wp_remote_retrieve_body($response), true);
            if (!self::validate_report($r)) { throw new RuntimeException('診断データの形式が正しくありません。'); }
            $report = ['score'=>$r['score'], 'checks'=>array_map(function($c) { return ['id'=>$c['id'], 'status'=>$c['status']]; }, $r['checks']), 'generated_at'=>$r['generated_at']];
        } catch (Throwable $e) { $error = $e->getMessage(); }
        if (!add_option('aaihb_registry_lock', time(), '', false)) { return new WP_Error('busy', '登録処理中のため結果を保存できません。再試行してください。'); }
        $saved = false;
        try {
            $current = self::site($id);
            if ($current && $current['token'] === $entry['token']) {
                $update = $current; $update['error'] = $error; $update['attempt'] = gmdate('c');
                if ($report !== null) { $update['report'] = $report; }
                self::site_put($id, $update);
                $saved = true;
            }
        } finally { delete_option('aaihb_registry_lock'); }
        if (!$saved) { return new WP_Error('changed', '接続情報が変更されました。再診断してください。'); }
        if ($report !== null) { foreach ($report['checks'] as $check) { if (($check['status'] ?? '') === 'critical') { if (class_exists('AAIHB_RecoveryEvidence')) { AAIHB_RecoveryEvidence::event('diagnostic_alert', $id, '重要項目を検出'); } break; } } }
        do_action('aaihb_observation', $id, $entry['name'], $report, $error);
        if ($error) { return new WP_Error('scan', $error); }
        return $report;
    }
    private static function form($op) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_save"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        wp_nonce_field('aaihb_save');
    }
    public static function page() {
        self::allowed(); nocache_headers();
        $s = self::settings();
        $per_page = 200; $total_sites = self::sites_count(); $total_pages = max(1, (int)ceil($total_sites / $per_page));
        $site_page = isset($_GET['site_page']) ? max(1, min($total_pages, absint(wp_unslash($_GET['site_page'])))) : 1;
        $sites = self::sites_page(($site_page - 1) * $per_page, $per_page);
        $notice = get_transient('aaihb_notice_' . get_current_user_id()); delete_transient('aaihb_notice_' . get_current_user_id());
        $token = get_transient('aaihb_token_' . get_current_user_id()); delete_transient('aaihb_token_' . get_current_user_id());
        wp_enqueue_script('aaihb', plugins_url('admin.js', __FILE__), [], '0.15.0', true);
        wp_localize_script('aaihb', 'AAIHB', ['url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('aaihb_scan')]);
        echo '<div class="wrap aaihb-app aaihb-workspace" data-screen="connect">'; AAIHB_Workspace::hero('connect'); AAIHB_Dashboard::nav('aaihb');
        if ($s['logo']) { echo '<img style="max-width:180px;max-height:70px" src="' . esc_url($s['logo']) . '" alt="">'; }
        echo '<p>' . esc_html($s['company']) . ' · 診断専用ベータ · Powered by AutoRepair AI</p><p>修復は実行しません。まずHTTPSのテストサイト2つで接続を確認してください。日時はUTCです。</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        echo '<h2 id="connect-brand">1. 会社設定（管理拠点で設定）</h2>'; self::form('settings');
        foreach (['company'=>'会社名', 'service'=>'サービス名', 'logo'=>'ロゴURL（HTTPS）'] as $key=>$label) { echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" name="' . esc_attr($key) . '" value="' . esc_attr($s[$key]) . '"></label></p>'; }
        submit_button('会社設定を保存'); echo '</form><hr><h2 id="connect-agent">2. このサイトのAgent接続</h2><p>対象サイト側で有効化します。診断スコアと10項目の判定だけを接続先のHubへ送信します。外部AIには送信しません。</p>';
        echo '<p>状態：' . ($s['agent'] ? '有効' : '無効') . '</p><p>接続先：<code>' . esc_html(rest_url('aaihb/v1/scan')) . '</code></p>';
        if ($token) { echo '<p><strong>今回だけ表示するトークン（コピーしてHubへ登録）</strong><br><code>' . esc_html($token) . '</code></p>'; }
        self::form('rotate'); submit_button('接続を有効化・新トークン発行（旧トークン失効）', 'secondary'); echo '</form>';
        self::form('revoke'); submit_button('このサイトへの接続を無効化', 'secondary'); echo '</form><hr><h2 id="connect-register">3. 対象サイトを登録（Hubで設定）</h2>';
        self::form('add');
        foreach (['name'=>'表示名', 'endpoint'=>'対象サイトのAgent接続先URL', 'token'=>'対象サイトのトークン'] as $key=>$label) { echo '<p><label>' . esc_html($label) . '<br><input required class="large-text" autocomplete="off" type="' . ($key==='token'?'password':'text') . '" name="' . esc_attr($key) . '"></label></p>'; }
        submit_button('登録・接続情報を更新'); echo '</form><h2 id="connect-test">4. サイト一覧（全' . $total_sites . '件中 ' . ($total_sites ? (($site_page-1)*$per_page+1) . '〜' . min($total_sites,$site_page*$per_page) : '0') . '件目・上限100,000）</h2><p>同じ接続先URLで再登録するとトークンを更新できます。このボタンは<strong>表示中のページ</strong>だけを診断します。登録サイト全体をまとめて診断したい場合は「グループ・予約」画面のバックグラウンド予約を使ってください（ブラウザを閉じてもWP-Cronで進みます）。</p><button id="aaihb-all" class="button button-primary">表示中のサイトを順番に診断</button> <button id="aaihb-stop" class="button">次のサイトから停止</button><p id="aaihb-progress" role="status" aria-live="polite"></p><table class="widefat striped"><thead><tr><th>サイト</th><th>診断結果</th><th>操作</th></tr></thead><tbody>';
        foreach ($sites as $id=>$e) {
            echo '<tr><td>' . esc_html($e['name']) . '<br><small>' . esc_html($e['endpoint']) . '</small></td><td><div id="result-' . esc_attr($id) . '">';
            if ($e['error']) { echo '<strong>直近の診断失敗：</strong>' . esc_html($e['error']) . '<br>'; }
            if ($e['report']) {
                echo '前回成功：' . (int)$e['report']['score'] . '/100 · ' . esc_html($e['report']['generated_at']);
                echo '<details><summary>項目別の判定</summary>';
                foreach ($e['report']['checks'] as $c) { echo esc_html($c['id'] . ': ' . $c['status']) . '<br>'; }
                echo '</details>';
            } else { echo '未診断'; }
            echo '<br>最終試行：' . esc_html($e['attempt']) . '</div></td><td><button class="button aaihb-scan" data-id="' . esc_attr($id) . '">診断</button> ';
            echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=aaihb_report&site=' . rawurlencode($id)), 'aaihb_report')) . '">顧客向け報告書</a>';
            self::form('delete'); echo '<input type="hidden" name="id" value="' . esc_attr($id) . '">'; submit_button('台帳から削除', 'small secondary'); echo '</form></td></tr>';
        }
        echo '</tbody></table><p>失敗時は前回成功の結果を残します。対象サイトごとに60秒以上の診断間隔が必要です。一括診断中はこの画面を開いたままにしてください。</p>';
        if ($total_pages > 1) {
            echo '<p>';
            foreach ([$site_page-1=>'← 前の' . $per_page . '件', $site_page+1=>'次の' . $per_page . '件 →'] as $n=>$label) {
                if ($n>=1 && $n<=$total_pages) { echo '<a class="button" href="' . esc_url(add_query_arg(['page'=>'aaihb','site_page'=>$n], admin_url('admin.php')) . '#connect-test') . '">' . esc_html($label) . '</a> '; }
            }
            echo (int)$site_page . ' / ' . (int)$total_pages . ' ページ</p>';
        }
        echo '</div>';
    }
}
AAIHB_Beta::boot();
require_once __DIR__ . '/value.php';
AAIHB_Value::boot();
require_once __DIR__ . '/operations.php';
AAIHB_Operations::boot();
require_once __DIR__ . '/dashboard.php';
AAIHB_Dashboard::boot();
require_once __DIR__ . '/fleet.php';
AAIHB_Fleet::boot();
require_once __DIR__ . '/notify.php';
AAIHB_Notify::boot();
require_once __DIR__ . '/report.php';
AAIHB_Report::boot();
require_once __DIR__ . '/repair.php';
AAIHB_Repair::boot();
require_once __DIR__ . '/partner.php';
AAIHB_Partner::boot();
require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/service-checks.php';
AAIHB_ServiceChecks::boot();
require_once __DIR__ . '/selfserve.php';
AAIHB_Selfserve::boot();
require_once __DIR__ . '/control-center.php';
AAIHB_ControlCenter::boot();
require_once __DIR__ . '/verification.php';
AAIHB_Verification::boot();
require_once __DIR__ . '/onboarding.php';
AAIHB_Onboarding::boot();
add_action('init',function(){if(add_option('aaihb_usage_since',gmdate('c'),'',false)) AAIHB_Billing::meter();});
register_deactivation_hook(__FILE__, function() {
    $s = AAIHB_Beta::settings(); $s['agent'] = 0; $s['hash'] = '';
    update_option(AAIHB_Beta::SETTINGS, $s, false);
    delete_option('aaihb_scan_lock');
    delete_option('aaihb_registry_lock');
    wp_clear_scheduled_hook('aaihb_value_tick');
    wp_clear_scheduled_hook('aaihb_central_tick');
    wp_clear_scheduled_hook('aaihb_recovery_tick');
    wp_clear_scheduled_hook('aaihb_local_daily');
    update_option('aaihb_local_daily_enabled',false,false);
    update_option('aaihb_guard_enabled',false,false);
    update_option('aaihb_recovery_auto',false,false);
    wp_clear_scheduled_hook('aaihb_central_drain');
    update_option('aaihb_auto_scan', false, false);
    delete_option('aaihb_tick_lock');
    delete_option('aaihb_value_lock');
    delete_option('aaihb_ops_lock');
    AAIHB_Fleet::deactivate();
});

foreach(['vault-io.php','vault-db.php','vault-full.php','vault-test.php','recovery-evidence.php','access.php','compatibility.php','backup-jobs.php','rehearsal-jobs.php','backup-audit.php','change-evidence.php','vault-guard.php','vault-admin.php','vault-command.php'] as $module)require_once __DIR__.'/'.$module;
AAIHB_Access::boot();AAIHB_RecoveryEvidence::boot();AAIHB_BackupJobs::boot();AAIHB_RehearsalJobs::boot();AAIHB_ChangeEvidence::boot();AAIHB_PluginGuard::boot();AAIHB_VaultAdmin::boot();
