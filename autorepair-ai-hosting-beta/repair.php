<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Structured repair approval trail: plan → approve → record execution.
 * This plugin never executes remote changes itself — the actual fix is still
 * performed by a person on the target site. This only makes the review and
 * sign-off auditable instead of happening in chat or over the phone.
 */
final class AAIHB_Repair {
    const TYPE = 'aaihb_repair';
    public static function boot() {
        add_action('init', [__CLASS__, 'init']);
        add_action('admin_post_aaihb_repair', [__CLASS__, 'save']);
    }
    public static function init() {
        register_post_type(self::TYPE, ['public'=>false, 'publicly_queryable'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'exclude_from_search'=>true, 'rewrite'=>false, 'supports'=>[]]);
    }
    private static function write($case, $kind, $data) {
        $data = array_merge(['case'=>$case, 'kind'=>$kind], $data, ['at'=>gmdate('c'), 'actor'=>get_current_user_id()]);
        $id = wp_insert_post(wp_slash(['post_type'=>self::TYPE, 'post_status'=>'private', 'post_title'=>$kind . ' #' . $case, 'post_content'=>wp_json_encode($data), 'post_author'=>get_current_user_id()]), true);
        if (is_wp_error($id) || !$id) { throw new RuntimeException('修復記録を保存できませんでした。'); }
        return $id;
    }
    public static function entries($case) {
        $posts = get_posts(['post_type'=>self::TYPE, 'post_status'=>'private', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'ASC']);
        $out = [];
        foreach ($posts as $p) { $d = json_decode($p->post_content, true); if (is_array($d) && (int)($d['case'] ?? 0) === (int)$case) { $d['id'] = (int)$p->ID; $out[] = $d; } }
        return $out;
    }
    /** Returns the most recent plan cycle with any later approve/executed markers attached. */
    public static function latest_plan($case) {
        $plan = null;
        foreach (self::entries($case) as $e) {
            if ($e['kind'] === 'plan') { $plan = $e; }
            elseif ($e['kind'] === 'approve' && $plan) { $plan['approved'] = $e; }
            elseif ($e['kind'] === 'executed' && $plan) { $plan['executed'] = $e; }
        }
        return $plan;
    }
    private static function field($name) { return AAIHB_Beta::input($name); }
    public static function save() {
        AAIHB_Operations::allowed(); check_admin_referer('aaihb_repair');
        $case = absint(self::field('case'));
        try {
            AAIHB_Value::case_data($case);
            $op = self::field('op');
            if ($op === 'plan') {
                $content = sanitize_textarea_field(self::field('content'));
                $target = sanitize_textarea_field(self::field('target'));
                $restore = sanitize_textarea_field(self::field('restore'));
                foreach (['content'=>$content, 'target'=>$target, 'restore'=>$restore] as $v) { if (!$v || strlen($v) > 3000) { throw new RuntimeException('修復内容・変更対象・復元方法をすべて3000バイト以内で入力してください。'); } }
                self::write($case, 'plan', ['content'=>$content, 'target'=>$target, 'restore'=>$restore]);
            } elseif ($op === 'approve') {
                $plan = self::latest_plan($case);
                if (!$plan || isset($plan['approved'])) { throw new RuntimeException('承認できる、未承認の修復計画がありません。'); }
                if (self::field('confirm') !== '1') { throw new RuntimeException('内容を確認したうえでチェックを入れてください。'); }
                self::write($case, 'approve', ['plan'=>$plan['id']]);
            } elseif ($op === 'executed') {
                $plan = self::latest_plan($case);
                if (!$plan || !isset($plan['approved']) || isset($plan['executed'])) { throw new RuntimeException('承認済みで未実行の修復計画がありません。'); }
                $note = sanitize_textarea_field(self::field('note'));
                if (!$note || strlen($note) > 3000) { throw new RuntimeException('実行結果・確認内容を入力してください。'); }
                self::write($case, 'executed', ['plan'=>$plan['id'], 'note'=>$note]);
            } else { throw new RuntimeException('操作が不正です。'); }
            set_transient('aaihb_repair_notice_' . get_current_user_id(), '保存しました。', 60);
        } catch (Throwable $e) { set_transient('aaihb_repair_notice_' . get_current_user_id(), $e->getMessage(), 60); }
        wp_safe_redirect(admin_url('admin.php?page=aaihb-ops&case=' . $case)); exit;
    }
    private static function form($op, $case) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_repair"><input type="hidden" name="op" value="' . esc_attr($op) . '"><input type="hidden" name="case" value="' . (int)$case . '">'; wp_nonce_field('aaihb_repair');
    }
    public static function panel($case) {
        $notice = get_transient('aaihb_repair_notice_' . get_current_user_id()); delete_transient('aaihb_repair_notice_' . get_current_user_id());
        $plan = self::latest_plan($case);
        echo '<h2>修復の計画・承認</h2><p>ここは計画・承認・実行結果の記録用です。実際の限定修復は対象サイトの「自動修復・復元」で操作します。この承認ボタンから遠隔修復は実行しません。</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        if ($plan) {
            echo '<div class="aaihb-repair-plan" style="background:#fff;padding:12px;border-left:4px solid #2271b1;margin:12px 0"><p><strong>修復内容：</strong><br>' . nl2br(esc_html($plan['content'])) . '</p><p><strong>変更対象：</strong><br>' . nl2br(esc_html($plan['target'])) . '</p><p><strong>復元方法：</strong><br>' . nl2br(esc_html($plan['restore'])) . '</p><p>計画作成：' . esc_html($plan['at']) . ' / user #' . (int)$plan['actor'] . '</p>';
            if (isset($plan['approved'])) {
                echo '<p><strong>承認済み：</strong>' . esc_html($plan['approved']['at']) . ' / user #' . (int)$plan['approved']['actor'] . '</p>';
                if (isset($plan['executed'])) {
                    echo '<p><strong>実行記録済み：</strong>' . esc_html($plan['executed']['at']) . ' / user #' . (int)$plan['executed']['actor'] . '</p><p>' . nl2br(esc_html($plan['executed']['note'])) . '</p>';
                } else {
                    self::form('executed', $case); echo '<p><label>実行結果・確認内容（必須。対象サイト側で作業した内容と、正常性の確認方法）<br><textarea required name="note" rows="3" class="large-text"></textarea></label></p>'; submit_button('実行結果を記録', 'secondary'); echo '</form>';
                }
            } else {
                self::form('approve', $case); echo '<p><label><input type="checkbox" required name="confirm" value="1"> 上記の修復内容・変更対象・復元方法を確認し、承認します。</label></p>'; submit_button('承認する'); echo '</form>';
            }
            echo '</div>';
        }
        if (!$plan || (isset($plan['approved']) && isset($plan['executed']))) {
            echo '<h3>' . ($plan ? '新しい修復計画を作成' : '修復計画を作成') . '</h3>';
            self::form('plan', $case);
            foreach (['content'=>'修復内容（何を、どう直すか）', 'target'=>'変更対象（ファイル・設定・プラグイン等）', 'restore'=>'復元方法（失敗した場合にどう戻すか）'] as $key=>$label) { echo '<p><label>' . esc_html($label) . '<br><textarea required name="' . esc_attr($key) . '" rows="3" class="large-text"></textarea></label></p>'; }
            submit_button('修復計画を記録', 'secondary');
            echo '</form>';
        }
    }
}
