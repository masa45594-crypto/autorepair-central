<?php
if (!defined('ABSPATH')) { exit; }

/** Host operations pilot: private cases, immutable work ledger, explicit assumptions. */
final class AAIHB_Value {
    const CASE_TYPE = 'aaihb_case';
    const ENTRY_TYPE = 'aaihb_entry';
    public static function boot() {
        add_action('init', [__CLASS__, 'init']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_value', [__CLASS__, 'save']);
        add_action('admin_post_aaihb_csv', [__CLASS__, 'csv']);
        add_action('aaihb_observation', [__CLASS__, 'observe'], 10, 4);
        add_action('aaihb_registry_saved', [__CLASS__, 'meter']);
        add_action('aaihb_value_tick', [__CLASS__, 'tick']);
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
    }
    public static function init() {
        foreach ([self::CASE_TYPE, self::ENTRY_TYPE] as $type) {
            register_post_type($type, ['public'=>false, 'publicly_queryable'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'exclude_from_search'=>true, 'rewrite'=>false, 'supports'=>[]]);
        }
        if (!get_option('aaihb_month_' . gmdate('Y-m'))) { self::meter(AAIHB_Beta::sites_count()); }
    }
    public static function menu() {
        add_submenu_page('aaihb', '対応と価値', '対応と価値', 'manage_options', 'aaihb-value', [__CLASS__, 'page']);
    }
    public static function schedules($s) {
        $s['aaihb_five_minutes'] = ['interval'=>300, 'display'=>'AutoRepair AI: 5 minutes']; return $s;
    }
    public static function month($value) {
        if (!is_string($value) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $value)) { throw new RuntimeException('年月はYYYY-MM形式で入力してください。'); }
        return $value;
    }
    public static function number($value, $max = 100000000) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > $max) { throw new RuntimeException('数値は0以上の範囲内で入力してください。'); }
        return round((float)$value, 2);
    }
    public static function config($month) {
        return array_merge(['peak'=>0, 'started'=>'', 'hourly'=>3000, 'other'=>0, 'confirmed'=>false], get_option('aaihb_month_' . self::month($month), []));
    }
    private static function locked($fn) {
        // All ledger mutations are short and serialized; do not hold this during HTTP requests.
        if (!add_option('aaihb_value_lock', time(), '', false)) { throw new RuntimeException('集計を保存中です。少し待って再試行してください。'); }
        try { return $fn(); } finally { delete_option('aaihb_value_lock'); }
    }
    /** Accepts either a site count or a full sites array (kept for the aaihb_registry_saved hook's older payload shape). */
    public static function meter($sites_or_count) {
        $count = is_array($sites_or_count) ? count($sites_or_count) : (int)$sites_or_count;
        try {
            self::locked(function() use ($count) {
                $m = gmdate('Y-m'); $c = self::config($m);
                if ($count > $c['peak']) { $c['confirmed'] = false; }
                $c['peak'] = max((int)$c['peak'], $count);
                if (!$c['started']) { $c['started'] = gmdate('c'); }
                update_option('aaihb_month_' . $m, $c, false);
            });
        } catch (Throwable $e) { update_option('aaihb_value_error', $e->getMessage(), false); }
    }
    /**
     * Returns [title, description, problem, first_check, steps[]]. Index 0/1 stay
     * plain strings for older call sites; 2-4 back the "次にやることナビ" guide.
     */
    public static function guide($check) {
        $map = [
            'home'=>['公開ページ', 'トップページやこのサイトが正しく表示・応答していない可能性があります。', 'ブラウザでサイトのトップページを直接開き、表示と応答速度を確認する。', ['ステータスコードと再現条件を確認する', '対象サイト側の障害ログを調査する', '復元点を確認してから対応する']],
            'rest_api'=>['REST API', 'WordPressのREST API（wp-json）が正常に応答していない可能性があります。', '対象サイトの /wp-json/ にブラウザでアクセスし、応答を確認する。', ['認証設定を確認する', 'WAF・セキュリティプラグインの除外設定を確認する', '例外設定は対象のルートだけに限定する']],
            'database'=>['データベース', 'データベースへの接続に問題がある可能性があります。', '対象サイトの管理画面にログインできるか確認する。', ['DB接続情報とホスト側の稼働状況を確認する', 'データ変更前に必ずバックアップを確認する']],
            'https'=>['HTTPS', 'SSL証明書またはHTTPS配信に問題がある可能性があります。', 'ブラウザでサイトを開き、鍵マークや証明書の警告を確認する。', ['証明書の有効期限を確認する', 'サイトURL設定（http/https）を確認する', '混在コンテンツの有無を確認する']],
            'disk'=>['ディスク', 'サーバーのディスク容量が逼迫している可能性があります。', 'ホスティング管理画面でディスク使用量を確認する。', ['残容量と増加元を確認する', 'バックアップを確保する', '不要と確認したファイルだけ整理する']],
            'filesystem'=>['書き込み権限', 'ファイル・フォルダの書き込み権限に問題がある可能性があります。', '更新やアップロードが失敗していないか確認する。', ['所有者と必要最小限の権限を確認する', '全体に777を設定しない']],
            'cron'=>['定期処理', 'WordPressの定期処理（WP-Cron）が動作していない可能性があります。', '対象サイトのAutoRepair AI定期診断の最終実行日時を確認する。', ['WP-Cronの実行状況を確認する', 'サーバー側のcron設定を確認する']],
            'maintenance'=>['メンテナンス', 'メンテナンスモードのまま停止している可能性があります。', 'サイトをブラウザで開き、メンテナンス画面が表示されていないか確認する。', ['更新処理が動作中か確認する', '停止した処理について復元点を確認して対応する']],
            'updates'=>['更新', 'コア・プラグイン・テーマの更新が必要、または更新に失敗した可能性があります。', '対象サイトの管理画面「更新」ページを確認する。', ['更新対象と互換性を確認する', 'テスト環境で検証する', '復元点を確保してから更新する']],
            'debug_log'=>['エラーログ', '対象サイトのエラーログにエラーが記録されている可能性があります。', '対象サイト側でdebug.logを確認する。', ['ログを調査し原因を絞る', '個人情報や認証情報を台帳に貼らない']],
            'connection'=>['診断接続', 'Hubから対象サイトへの診断接続が失敗しました。', '接続先URLとトークンが現在も一致しているか確認する。', ['接続失敗だけではサイト停止と断定しない', 'HTTPS・WAF・対象側Hosting Betaの有効化を確認する']],
            'routine'=>['定期点検の比較', '手動作成の比較用案件です（異常検知ではありません）。', '対象サイト群と点検範囲を確認する。', ['同じサイト群・同じ点検範囲で、従来の所要時間と導入後の実作業時間を比較する', '案件別対応と重複計上しない']],
        ];
        $g = $map[$check] ?? ['確認', '対象サイト側で状態を確認してください。', '対象サイト側で状態を確認してください。', []];
        return [$g[0], $g[1], $g[2], $g[3]];
    }
    public static function guide_html($check) {
        $g = self::guide($check);
        $out = '<div class="aaihb-guide"><p class="aaihb-guide-problem"><strong>何が問題か：</strong>' . esc_html($g[1]) . '</p><p class="aaihb-guide-first"><strong>最初に確認する場所：</strong>' . esc_html($g[2]) . '</p>';
        if ($g[3]) { $out .= '<p><strong>対応手順：</strong></p><ol class="aaihb-guide-steps">'; foreach ($g[3] as $step) { $out .= '<li>' . esc_html($step) . '</li>'; } $out .= '</ol>'; }
        return $out . '</div>';
    }
    private static function write_post($type, $title, $data) {
        $id = wp_insert_post(wp_slash(['post_type'=>$type, 'post_status'=>'private', 'post_title'=>$title, 'post_content'=>wp_json_encode($data), 'post_author'=>get_current_user_id()]), true);
        if (is_wp_error($id) || !$id) { throw new RuntimeException('台帳を書き込めませんでした。'); }
        return $id;
    }
    public static function case_data($id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== self::CASE_TYPE) { throw new RuntimeException('対応案件が見つかりません。'); }
        return get_post_meta($id, 'aaihb_data', true);
    }
    private static function entry($data) {
        $data = array_merge(['case'=>0, 'kind'=>'work', 'minutes'=>0, 'basis'=>'none', 'note'=>'', 'reverse'=>0, 'month'=>gmdate('Y-m')], $data);
        $data['at'] = gmdate('c'); $data['actor'] = get_current_user_id();
        $id = self::write_post(self::ENTRY_TYPE, $data['kind'], $data);
        if (in_array($data['kind'], ['work','close','void'], true)) {
            $c = self::config($data['month']); $c['confirmed'] = false;
            update_option('aaihb_month_' . $data['month'], $c, false);
        }
        // Month queries use post content's explicit UTC accounting month, not WP local dates.
        return $id;
    }
    public static function entries($month = null) {
        $out = [];
        $posts = get_posts(['post_type'=>self::ENTRY_TYPE, 'post_status'=>'private', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'ASC']);
        foreach ($posts as $p) {
            $d = json_decode($p->post_content, true);
            if (is_array($d) && ($month === null || $d['month'] === $month)) { $d['id'] = (int)$p->ID; $out[] = $d; }
        }
        return $out;
    }
    public static function effective($rows) {
        $void = [];
        foreach ($rows as $r) { if ($r['kind'] === 'void') { $void[(int)$r['reverse']] = true; } }
        return array_values(array_filter($rows, function($r) use ($void) { return $r['kind'] !== 'void' && !isset($void[(int)$r['id']]); }));
    }
    public static function observe($site, $name, $report, $error) {
        try {
            self::locked(function() use ($site, $name, $report, $error) {
                $checks = $error ? [['id'=>'connection', 'status'=>'warning']] : array_merge([['id'=>'connection', 'status'=>'healthy']], $report['checks']);
                foreach ($checks as $check) {
                    $key = $site . ':' . $check['id'];
                    $found = get_posts(['post_type'=>self::CASE_TYPE, 'post_status'=>'private', 'numberposts'=>1, 'meta_key'=>'aaihb_key', 'meta_value'=>$key, 'orderby'=>'ID', 'order'=>'DESC']);
                    $id = $found ? $found[0]->ID : 0; $d = $id ? self::case_data($id) : null;
                    if ($check['status'] === 'healthy') {
                        if ($d) { $d['signal_open'] = false; $d['last'] = gmdate('c'); update_post_meta($id, 'aaihb_data', $d); }
                        continue;
                    }
                    if (!$d || !$d['signal_open']) {
                        $d = ['site'=>$site, 'name'=>$name, 'check'=>$check['id'], 'severity'=>$check['status'], 'signal_open'=>true, 'first'=>gmdate('c'), 'last'=>gmdate('c')];
                        $id = self::write_post(self::CASE_TYPE, $name . ' / ' . self::guide($check['id'])[0], []);
                        update_post_meta($id, 'aaihb_key', $key);
                    }
                    $d['last'] = gmdate('c'); $d['severity'] = $check['status']; update_post_meta($id, 'aaihb_data', $d);
                }
                delete_option('aaihb_value_error');
            });
        } catch (Throwable $e) { update_option('aaihb_value_error', $e->getMessage(), false); }
        self::meter(AAIHB_Beta::sites_count());
    }
    public static function tick() {
        if (AAIHB_Fleet::active()) { return; }
        if (is_multisite() || !get_option('aaihb_auto_scan', false)) { return; }
        // One remote call per cron run. Every registered site is due once per day.
        if (!add_option('aaihb_tick_lock', time(), '', false)) { return; }
        try {
            $sites = AAIHB_Beta::sites(); self::meter($sites);
            uasort($sites, function($a, $b) { return strcmp($a['attempt'], $b['attempt']); });
            foreach ($sites as $id=>$s) {
                if (AAIHB_Fleet::paused($id)) { continue; }
                if (!$s['attempt'] || strtotime($s['attempt']) < time()-86400) { AAIHB_Beta::scan_site($id); break; }
            }
            update_option('aaihb_tick_at', gmdate('c'), false);
        } finally { delete_option('aaihb_tick_lock'); }
    }
    /** Value is labor capacity, never a claim of cash profit. Negative values remain negative. */
    public static function calculate($rows, $c) {
        $actual = 0; $measured = 0; $estimated = 0; $unknown = 0; $closed = 0;
        foreach (self::effective($rows) as $r) {
            if ($r['kind'] === 'work') { $actual += $r['minutes']; }
            if ($r['kind'] === 'close') {
                $closed++;
                if ($r['basis'] === 'measured') { $measured += $r['minutes']; }
                elseif ($r['basis'] === 'estimate') { $estimated += $r['minutes']; }
                elseif ($r['basis'] !== 'zero') { $unknown++; }
            }
        }
        $fee = $c['peak'] * 100; $cost = $fee + $c['other'];
        $strict = ($measured - $actual) * $c['hourly'] / 60 - $cost;
        $modeled = ($measured + $estimated - $actual) * $c['hourly'] / 60 - $cost;
        return ['actual'=>$actual, 'measured'=>$measured, 'estimated'=>$estimated, 'unknown'=>$unknown, 'closed'=>$closed, 'fee'=>$fee, 'cost'=>$cost, 'strict'=>round($strict,2), 'modeled'=>round($modeled,2)];
    }
    private static function field($name) { return AAIHB_Beta::input($name); }
    public static function save() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_value');
        $month = gmdate('Y-m');
        try {
            $month = self::month(self::field('month'));
            if ($month > gmdate('Y-m')) { throw new RuntimeException('将来月には記録できません。'); }
            self::locked(function() use ($month) {
                self::mutate($month);
            });
            set_transient('aaihb_value_notice_' . get_current_user_id(), '保存しました。', 60);
        } catch (Throwable $e) { set_transient('aaihb_value_notice_' . get_current_user_id(), $e->getMessage(), 60); }
        wp_safe_redirect(admin_url('admin.php?page=aaihb-value&month=' . rawurlencode($month))); exit;
    }
    // Internal operation dispatcher; the HTTP handler above owns authorization and serialization.
    public static function mutate($month) {
                $op = self::field('op');
                if ($op === 'config') {
                    $c = self::config($month); $peak = self::number(self::field('peak'), 10000000);
                    if (floor($peak) !== $peak || $peak < $c['peak']) { throw new RuntimeException('サイト数は自動計測値以上の整数にしてください。'); }
                    $c['peak'] = (int)$peak; $c['hourly'] = self::number(self::field('hourly'), 1000000); $c['other'] = self::number(self::field('other'));
                    $c['confirmed'] = self::field('confirmed') === '1';
                    self::entry(['kind'=>'config', 'month'=>$month, 'note'=>wp_json_encode($c)]);
                    update_option('aaihb_month_' . $month, $c, false);
                } elseif ($op === 'auto') {
                    $on = self::field('enabled') === '1';
                    if ($on && !wp_next_scheduled('aaihb_value_tick')) {
                        $scheduled = wp_schedule_event(time()+60, 'aaihb_five_minutes', 'aaihb_value_tick', [], true);
                        if (is_wp_error($scheduled) || !$scheduled) { throw new RuntimeException('定期診断の予約に失敗しました。'); }
                    }
                    if (!$on) { wp_clear_scheduled_hook('aaihb_value_tick'); }
                    update_option('aaihb_auto_scan', $on, false);
                } elseif ($op === 'routine') {
                    $title = sanitize_text_field(self::field('title'));
                    if (!$title || strlen($title)>300) { throw new RuntimeException('点検の対象と期間を300バイト以内で入力してください。'); }
                    $id = self::write_post(self::CASE_TYPE, $title, []);
                    update_post_meta($id, 'aaihb_data', ['site'=>'shared', 'name'=>$title, 'check'=>'routine', 'severity'=>'info', 'signal_open'=>false, 'first'=>gmdate('c'), 'last'=>gmdate('c')]);
                } elseif ($op === 'work' || $op === 'close') {
                    $case = (int)self::field('case'); if ($case) { self::case_data($case); }
                    if ($op === 'close' && !$case) { throw new RuntimeException('完了する案件を選んでください。'); }
                    $note = sanitize_textarea_field(self::field('note'));
                    if (!$note || strlen($note) > 3000) { throw new RuntimeException('対応内容・比較根拠を3000バイト以内で入力してください。'); }
                    $basis = $op === 'work' ? 'none' : self::field('basis');
                    if (!in_array($basis, ['none','measured','estimate','zero'], true)) { throw new RuntimeException('比較根拠が不正です。'); }
                    $minutes = self::number(self::field('minutes'), 44640);
                    if ($op === 'close') {
                        $has_work = false;
                        foreach (self::effective(self::entries()) as $r) {
                            if ((int)$r['case'] !== $case) { continue; }
                            if ($r['kind'] === 'close') { throw new RuntimeException('完了済みです。訂正する場合は先に完了記録を取り消してください。'); }
                            if ($r['kind'] === 'work') { $has_work = true; }
                        }
                        if (!$has_work) { throw new RuntimeException('先にこの案件の実作業時間を記録してください。0分の場合も理由を記録してください。'); }
                        if ($basis === 'none' || $basis === 'zero') { $minutes = 0; }
                    }
                    self::entry(['kind'=>$op, 'month'=>$month, 'case'=>$case, 'minutes'=>$minutes, 'basis'=>$basis, 'note'=>$note]);
                } elseif ($op === 'void') {
                    $target = (int)self::field('target'); $reason = sanitize_textarea_field(self::field('reason')); $found = null;
                    foreach (self::effective(self::entries($month)) as $r) { if ($r['id'] === $target) { $found = $r; } }
                    if (!$found || !in_array($found['kind'], ['work','close'], true) || !$reason || strlen($reason)>3000) { throw new RuntimeException('有効な記録IDと取消理由を入力してください。'); }
                    if ($found['kind'] === 'work' && $found['case']) {
                        foreach (self::effective(self::entries()) as $r) {
                            if ($r['kind'] === 'close' && (int)$r['case'] === (int)$found['case']) { throw new RuntimeException('この案件は完了済みです。先に完了記録を取り消してから作業時間を訂正してください。'); }
                        }
                    }
                    self::entry(['kind'=>'void', 'month'=>$month, 'case'=>$found['case'], 'reverse'=>$target, 'note'=>$reason]);
                } else { throw new RuntimeException('操作が不正です。'); }
    }
    private static function form($op, $month) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_value"><input type="hidden" name="op" value="' . esc_attr($op) . '"><input type="hidden" name="month" value="' . esc_attr($month) . '">'; wp_nonce_field('aaihb_value');
    }
    private static function input($name, $label, $value, $step = '0.01', $id = '') {
        echo '<label style="display:inline-block;margin:8px 18px 8px 0">' . esc_html($label) . '<br><input required min="0" type="number" step="' . esc_attr($step) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($id ? ' id="' . esc_attr($id) . '"' : '') . '></label>';
    }
    public static function page() {
        AAIHB_Beta::allowed(); nocache_headers();
        self::meter(AAIHB_Beta::sites_count());
        try { $month = self::month(isset($_GET['month']) && is_string($_GET['month']) ? wp_unslash($_GET['month']) : gmdate('Y-m')); } catch (Throwable $e) { $month = gmdate('Y-m'); }
        $c = self::config($month); $rows = self::entries(); $month_rows = array_values(array_filter($rows, function($r) use ($month) {return $r['month'] === $month;})); $v = self::calculate($month_rows, $c);
        $closed = []; foreach (self::effective($rows) as $r) { if ($r['kind'] === 'close') { $closed[(int)$r['case']] = true; } }
        $cases = get_posts(['post_type'=>self::CASE_TYPE, 'post_status'=>'private', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'DESC']);
        $open = 0; foreach ($cases as $p) { if (!isset($closed[$p->ID])) { $open++; } }
        $notice = get_transient('aaihb_value_notice_' . get_current_user_id()); delete_transient('aaihb_value_notice_' . get_current_user_id());
        echo '<div class="wrap aaihb-app aaihb-workspace" data-screen="value">'; AAIHB_Workspace::hero('value'); AAIHB_Dashboard::nav('aaihb-value'); AAIHB_Workspace::metrics(['記録した作業時間'=>[$v['actual'].' 分','実際の作業時間を合計'],'利用料の試算'=>[number_format($v['fee']).' 円','100円 × 対象サイト数'],'費用を引いた時間価値'=>[number_format($v['strict']).' 円','実測根拠分。現金利益ではありません。']]); echo '<p>作業時間を記録して、月100円／サイトに見合う時間短縮ができたかを確認します。</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        if (get_option('aaihb_value_error')) { echo '<div class="notice notice-error"><p>案件への反映に失敗：' . esc_html(get_option('aaihb_value_error')) . ' 再診断して確認してください。</p></div>'; }
        echo '<form method="get"><input type="hidden" name="page" value="aaihb-value"><label>集計月（UTC） <input type="month" name="month" value="' . esc_attr($month) . '"></label> <button class="button">表示</button></form>';
        echo '<h2 id="value-report">月次価値レポート</h2><p><strong>' . ((!$c['confirmed'] || !$v['closed'] || $v['unknown'] || $open) ? '判定保留：費用・未完了案件・比較根拠を確認してください。' : ($v['strict'] > 0 ? '記録上は利用料・追加費用を上回る時間価値があります。' : '実測比較による時間価値は費用を上回っていません。')) . '</strong></p>';
        echo '<table class="widefat striped" style="max-width:850px"><tbody>';
        foreach (['計測サイト数（当月最大同時登録数）'=>$c['peak'].' サイト', '利用料の試算（100円 × サイト数）'=>number_format($v['fee']).' 円', 'その他の月次運用費'=>number_format($c['other']).' 円', '記録した実作業時間'=>$v['actual'].' 分', '比較元の作業時間：実測根拠あり'=>$v['measured'].' 分', '比較元の作業時間：見積りのみ'=>$v['estimated'].' 分', '費用控除後の時間価値：実測根拠分'=>number_format($v['strict'],2).' 円', '費用控除後の時間価値：見積りを含む'=>number_format($v['modeled'],2).' 円', '当月完了／比較不明／全期間未完了'=>$v['closed'].' / '.$v['unknown'].' / '.$open.' 件'] as $k=>$val) { echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html($val) . '</td></tr>'; }
        echo '</tbody></table><p>時間価値＝（比較元の時間 − 全記録作業時間）× 時間単価 ÷ 60 − 利用料 − その他費用。現金利益・障害防止額ではありません。実測根拠ありも担当者の申告です。未記録の作業は自動では把握できません。</p><p>実作業は作業月、比較元は案件完了月に計上します。月をまたぐ案件は複数月で評価してください。当月途中も利用料は1か月分。請求・決済は発生しません。計測開始：' . esc_html($c['started'] ?: '記録なし') . '</p>';
        if ($c['hourly'] > 0) { echo '<p>利用料100円だけの損益分岐：1サイト月 ' . esc_html(round(6000/$c['hourly'],2)) . ' 分の短縮。追加運用費と実作業の増加も含めて判断してください。</p>'; }
        if ($c['peak'] > 0) { echo '<p>費用控除後の時間価値（実測根拠分）の1サイト平均：' . esc_html(number_format($v['strict']/$c['peak'],2)) . ' 円。全体の平均であり、個々のサイトの効果を保証しません。</p>'; }
        echo '<details><summary>費用・前提を設定</summary>'; self::form('config', $month);
        self::input('peak', '対象サイト数（減額不可・整数）', $c['peak'], '1'); self::input('hourly', '人件費相当の時間単価（円／時）', $c['hourly']); self::input('other', '追加費用（円／月）', $c['other']);
        echo '<p>追加費用にはAPI・サーバー・導入費の月割り等を入力。実作業欄に入れた人件費との二重計上を避けてください。</p><p><label><input type="checkbox" name="confirmed" value="1" ' . checked($c['confirmed'],true,false) . '>サイト数・時間単価・追加費用・当月の作業記録を確認した</label></p>'; submit_button('月次前提を保存'); echo '</form></details>';
        echo '<h2>定期診断</h2>'; self::form('auto', $month);
        echo '<label><input type="checkbox" name="enabled" value="1" ' . checked(get_option('aaihb_auto_scan'),true,false) . '>登録済みサイトを1日1回程度、順番に診断する</label><p>5分ごとに最大1サイト。WP-Cronの実行状況により遅れます。最終実行：' . esc_html(get_option('aaihb_tick_at','未実行')) . '</p>'; submit_button('定期診断を設定','secondary'); echo '</form>';
        usort($cases, function($a,$b) use ($closed) {
            $da = self::case_data($a->ID); $db = self::case_data($b->ID);
            $pa = (isset($closed[$a->ID]) ? 10 : 0) + ($da['severity']==='critical' ? 0 : 1); $pb = (isset($closed[$b->ID]) ? 10 : 0) + ($db['severity']==='critical' ? 0 : 1);
            return $pa === $pb ? $b->ID <=> $a->ID : $pa <=> $pb;
        });
        $case_filter = (isset($_GET['case_filter']) && wp_unslash($_GET['case_filter']) === 'all') ? 'all' : 'open';
        $all_cases = $cases;
        $shown_cases = $case_filter === 'all' ? $cases : array_values(array_filter($cases, function($p) use ($closed) { return !isset($closed[$p->ID]); }));
        $per_page = 200; $total_cases = count($shown_cases); $total_pages = max(1, (int)ceil($total_cases / $per_page));
        $case_page = isset($_GET['case_page']) ? max(1, min($total_pages, absint(wp_unslash($_GET['case_page'])))) : 1;
        $page_cases = array_slice($shown_cases, ($case_page - 1) * $per_page, $per_page);
        echo '<h2 id="value-cases">対応案件（重大な未完了を優先・' . ($case_filter==='all' ? '全' . count($all_cases) . '件中' : '未完了' . $total_cases . '件中（全' . count($all_cases) . '件中 対応記録済' . (count($all_cases)-$total_cases) . '件を非表示）') . ' ' . ($total_cases ? (($case_page-1)*$per_page+1) . '〜' . min($total_cases,$case_page*$per_page) : '0') . '件目）</h2><p>同じ異常は継続中の1案件にまとめます。正常判定になっても人の対応完了・短縮成果は自動計上しません。接続失敗時は過去の診断を正常扱いしません。</p>';
        echo '<p>';
        if ($case_filter === 'open') { echo '<strong>未完了のみ表示中</strong> ｜ <a href="' . esc_url(add_query_arg(['page'=>'aaihb-value','month'=>$month,'case_filter'=>'all'], admin_url('admin.php')) . '#value-cases') . '">対応記録済も含めてすべて表示 →</a>'; }
        else { echo '<a href="' . esc_url(add_query_arg(['page'=>'aaihb-value','month'=>$month,'case_filter'=>'open'], admin_url('admin.php')) . '#value-cases') . '">← 未完了のみ表示に戻す</a> ｜ <strong>すべて表示中（対応記録済を含む）</strong>'; }
        echo '</p>';
        echo '<table class="widefat striped aaihb-value-cases"><thead><tr><th>ID・サイト</th><th>状態</th><th>対応の確認点</th></tr></thead><tbody>';
        foreach ($page_cases as $p) {
            $d = self::case_data($p->ID); $g = self::guide($d['check']);
            echo '<tr><td>#' . (int)$p->ID . ' ' . esc_html($d['name']) . '<br>' . esc_html($g[0]) . '</td><td>' . (isset($closed[$p->ID]) ? '対応記録済' : '<strong>未完了</strong>') . ' / ' . esc_html($d['severity']) . '<br>' . ($d['check']==='routine' ? '手動作成の比較案件' : ($d['signal_open'] ? '最終観測：異常' : '最終観測：正常')) . '<br>' . esc_html($d['last']) . '</td><td>' . self::guide_html($d['check']) . '</td></tr>';
        }
        if (!$page_cases) { echo '<tr><td colspan="3">' . ($all_cases ? '該当する案件はありません。' . ($case_filter==='open' ? '「対応記録済も含めてすべて表示」を開いてください。' : '')  : 'まだ案件はありません。Hubのサイト一覧で診断してください。') . '</td></tr>'; }
        echo '</tbody></table>';
        if ($total_pages > 1) {
            echo '<p>';
            foreach ([$case_page-1=>'← 前の' . $per_page . '件', $case_page+1=>'次の' . $per_page . '件 →'] as $n=>$label) {
                if ($n>=1 && $n<=$total_pages) { echo '<a class="button" href="' . esc_url(add_query_arg(['page'=>'aaihb-value','month'=>$month,'case_filter'=>$case_filter,'case_page'=>$n], admin_url('admin.php')) . '#value-cases') . '">' . esc_html($label) . '</a> '; }
            }
            echo (int)$case_page . ' / ' . (int)$total_pages . ' ページ</p>';
        }
        echo '</tbody></table><details><summary>異常がない場合：定期点検の比較案件を作る</summary><p>対象サイト群と点検期間を記載し、従来と導入後の同じ点検を比較します。作成後に表示される案件IDで時間を記録してください。</p>'; self::form('routine', $month); echo '<input required class="large-text" name="title" placeholder="例：対象10サイト・9月第1週の公開ページと更新確認">'; submit_button('比較案件を作成','secondary'); echo '</form></details><h2 id="value-work">作業記録</h2><p>調査・修復・誤検知対応をすべて記録。比較にひも付かない共通作業は案件IDを0にします。案件のない月も共通作業を記録できます。</p>';
        echo '<div class="aaihb-timer" id="aaihb-timer"><button type="button" class="button button-primary" id="aaihb-timer-start">対応を開始</button> <button type="button" class="button" id="aaihb-timer-stop" disabled>終了して時間を反映</button> <span id="aaihb-timer-display" role="status" aria-live="polite">未計測</span><p class="description">「対応を開始」を押してから作業し、「終了して時間を反映」を押すと下の「実際にかかった時間（分）」に自動入力します。保存前に必ず内容を確認してください。ブラウザを閉じると計測は失われます。</p></div>';
        self::form('work', $month); self::input('case', '案件ID（共通作業は0）', 0, '1', 'aaihb-timer-case'); self::input('minutes', '実際にかかった時間（分）', 0, '0.01', 'aaihb-timer-minutes');
        echo '<p><label>対応内容・計測方法（秘密情報を含めない）<br><textarea required name="note" rows="3" class="large-text"></textarea></label></p>'; submit_button('実作業を追加'); echo '</form>';
        echo '<h2>案件を完了して比較する</h2><p>先に実作業を記録してください。通常なら必要だった対応と同じ範囲の時間を比較します。診断項目ごとの時間を重複して計上しないでください。異常が継続する限り、完了しても新しい案件にはなりません。</p>';
        self::form('close', $month); self::input('case', '案件ID', '', '1'); self::input('minutes', 'ツールなしの比較元時間（分）', 0);
        echo '<p><label>比較根拠 <select name="basis"><option value="none">不明（短縮を計上しない）</option><option value="measured">同等作業の実測記録あり</option><option value="estimate">担当者の見積り</option><option value="zero">従来は作業不要・誤検知（比較元0分）</option></select></label></p><p><label>比較記録のID・日時・同等と判断した根拠／完了内容<br><textarea required name="note" rows="3" class="large-text"></textarea></label></p>'; submit_button('完了と比較根拠を記録'); echo '</form>';
        echo '<h2>当月の記録（最新30件）</h2><table class="widefat striped"><thead><tr><th>ID／種類／案件</th><th>分・根拠</th><th>記録内容</th></tr></thead><tbody>';
        foreach (array_slice(array_reverse($month_rows),0,30) as $r) { echo '<tr><td>#' . (int)$r['id'] . ' / ' . esc_html($r['kind']) . ' / #' . (int)$r['case'] . '</td><td>' . esc_html($r['minutes'].' / '.$r['basis']) . '</td><td>' . esc_html($r['note']) . '<br>' . esc_html($r['at']) . ' / user #' . (int)$r['actor'] . '</td></tr>'; }
        echo '</tbody></table><p>記録の上書きはしません。誤記は取消記録を追加してから正しい値を再登録します。取消元は履歴に残ります。</p>'; self::form('void',$month); self::input('target','取り消す当月記録ID','','1'); echo '<label>取消理由 <input required name="reason" class="regular-text"></label>'; submit_button('記録を取り消す','secondary'); echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_csv"><input type="hidden" name="month" value="' . esc_attr($month) . '">'; wp_nonce_field('aaihb_csv'); submit_button('当月の価値・全履歴をCSV出力','secondary'); echo '</form></div>';
    }
    public static function csv_cell($value) {
        $v = (string)$value; return preg_match('/^[\s]*[=+@\-]/u', $v) ? "'" . $v : $v;
    }
    public static function csv() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_csv');
        try { $month = self::month(self::field('month')); } catch (Throwable $e) { wp_die('年月が不正です。'); }
        $rows = self::entries($month); $c = self::config($month); $v = self::calculate($rows,$c);
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="autorepair-value-' . $month . '.csv"');
        $f = fopen('php://output','w'); fwrite($f, "\xEF\xBB\xBF");
        foreach (array_merge(['month'=>$month, 'notice'=>'時間価値の試算。現金利益ではありません。未記録作業は含みません。'], $c, $v) as $key=>$value) { fputcsv($f, [$key, self::csv_cell($value)]); }
        fputcsv($f, ['id','month','case','kind','minutes','basis','note','reverse','at','actor']);
        foreach ($rows as $r) { $line=[]; foreach (['id','month','case','kind','minutes','basis','note','reverse','at','actor'] as $key) { $line[]=self::csv_cell($r[$key]); } fputcsv($f,$line); }
        fclose($f); exit;
    }
}
