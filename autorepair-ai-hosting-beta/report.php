<?php
if (!defined('ABSPATH')) { exit; }

/** Renders a standalone, printable (browser "Print to PDF") customer-facing report for one site. */
final class AAIHB_Report {
    public static function boot() {
        add_action('admin_post_aaihb_report', [__CLASS__, 'render']);
    }
    private static function site_cases($site_id) {
        $cases = get_posts(['post_type'=>AAIHB_Value::CASE_TYPE, 'post_status'=>'private', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'DESC']);
        $open = []; $done = [];
        foreach ($cases as $p) {
            $d = AAIHB_Value::case_data($p->ID);
            if (($d['site'] ?? '') !== $site_id) { continue; }
            $s = AAIHB_Operations::state($p->ID);
            $row = ['id'=>(int)$p->ID, 'check'=>AAIHB_Value::guide($d['check'])[0], 'severity'=>$d['severity']];
            if ($s['status'] === 'done') {
                $note = '';
                foreach (array_reverse($s['history']) as $event) { if ($event['to'][0] === 'done') { $note = $event['note']; break; } }
                $row['note'] = $note; $done[] = $row;
            } elseif ($d['signal_open']) { $open[] = $row; }
        }
        return [$open, $done];
    }
    public static function render() {
        AAIHB_Beta::allowed(); check_admin_referer('aaihb_report');
        // Report links are opened with a GET parameter. AAIHB_Beta::input()
        // intentionally accepts POST only, so read and sanitize this specific
        // read-only query parameter here.
        $id = isset($_GET['site']) && is_string($_GET['site'])
            ? trim(wp_unslash($_GET['site']))
            : '';
        $site = $id ? AAIHB_Beta::site($id) : null;
        if (!$id || !$site) { wp_die('登録が見つかりません。'); }
        $settings = AAIHB_Beta::settings();
        list($open, $done) = self::site_cases($id);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        $labels = ['healthy'=>'正常', 'warning'=>'注意', 'critical'=>'重大', 'info'=>'定期点検'];
        ?>
<!doctype html>
<html lang="ja"><head><meta charset="UTF-8"><title>診断報告書 - <?php echo esc_html($site['name']); ?></title>
<style>
 body{font-family:-apple-system,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;color:#222;max-width:800px;margin:24px auto;padding:0 16px;}
 header{display:flex;align-items:center;gap:16px;border-bottom:2px solid <?php echo esc_html($settings['accent']); ?>;padding-bottom:16px;margin-bottom:24px;}
 header img{max-height:60px}
 h1{font-size:20px;margin:0} h2{font-size:16px;border-left:4px solid <?php echo esc_html($settings['accent']); ?>;padding-left:8px;margin-top:32px}
 table{width:100%;border-collapse:collapse;margin:12px 0}
 th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;font-size:14px;vertical-align:top}
 .tone-critical{color:#b32d2e;font-weight:bold} .tone-warning{color:#8a6100;font-weight:bold} .tone-healthy{color:#2a7a2a}
 .print-hint{background:#f0f6fc;border:1px solid #c3d4e0;padding:10px;font-size:13px;border-radius:4px}
 footer{margin-top:32px;font-size:12px;color:#666}
 @media print { .print-hint{display:none} body{margin:0} }
</style></head><body>
<div class="print-hint">この画面をPDFとして保存するには、ブラウザの印刷（Ctrl+P / Cmd+P）を開き、出力先を「PDFに保存」にしてください。</div>
<header>
<?php if ($settings['logo']) { echo '<img src="' . esc_url($settings['logo']) . '" alt="">'; } ?>
<div><h1><?php echo esc_html($settings['company'] ?: $settings['service']); ?></h1><p>診断報告書</p></div>
</header>
<p><strong>対象サイト：</strong><?php echo esc_html($site['name']); ?><br><strong>作成日時（UTC）：</strong><?php echo esc_html(gmdate('c')); ?></p>
<h2>診断結果</h2>
<?php if ($site['report']) { ?>
<p>総合スコア：<strong><?php echo (int)$site['report']['score']; ?> / 100</strong>（判定日時（UTC）：<?php echo esc_html($site['report']['generated_at']); ?>）</p>
<table><thead><tr><th>診断項目</th><th>判定</th></tr></thead><tbody>
<?php foreach ($site['report']['checks'] as $c) { ?>
<tr><td><?php echo esc_html(AAIHB_Value::guide($c['id'])[0]); ?></td><td class="tone-<?php echo esc_attr($c['status']); ?>"><?php echo esc_html($labels[$c['status']] ?? $c['status']); ?></td></tr>
<?php } ?>
</tbody></table>
<?php } else { echo '<p>まだ診断結果がありません。</p>'; } ?>
<h2>行った対応（対応済み案件）</h2>
<?php if ($done) { ?>
<table><thead><tr><th>案件</th><th>対応内容</th></tr></thead><tbody>
<?php foreach ($done as $r) { ?>
<tr><td>#<?php echo (int)$r['id']; ?> <?php echo esc_html($r['check']); ?></td><td><?php echo nl2br(esc_html($r['note'])); ?></td></tr>
<?php } ?>
</tbody></table>
<?php } else { echo '<p>当期間に完了記録はありません。</p>'; } ?>
<h2>残っている課題</h2>
<?php if ($open) { ?>
<table><thead><tr><th>案件</th><th>重要度</th></tr></thead><tbody>
<?php foreach ($open as $r) { ?>
<tr><td>#<?php echo (int)$r['id']; ?> <?php echo esc_html($r['check']); ?></td><td class="tone-<?php echo esc_attr($r['severity']); ?>"><?php echo esc_html($labels[$r['severity']] ?? $r['severity']); ?></td></tr>
<?php } ?>
</tbody></table>
<?php } else { echo '<p>未対応の異常はありません。</p>'; } ?>
<footer><?php echo $settings['hide_branding'] ? '' : 'Powered by AutoRepair AI。'; ?>本報告書は診断時点の状態を示すものであり、将来の障害発生を保証するものではありません。診断は指定した項目についての判定であり、サイト全体の安全性を保証するものではありません。</footer>
</body></html>
        <?php
        exit;
    }
}
