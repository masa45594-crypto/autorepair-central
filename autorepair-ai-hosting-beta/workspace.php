<?php
if(!defined('ABSPATH')){exit;}
final class AAIHB_Workspace {
    public static function hero($screen) {
        $map=[
            'ops'=>['TAKE ACTION','次の対応を、迷わず。','担当・期限・状況をそろえて、対応漏れを減らしましょう。','01','案件を選ぶ → 担当を決める → 対応内容を残す'],
            'value'=>['MEASURE YOUR IMPACT','減らせた時間を、見える価値に。','使う前と使った後の作業時間を比べます。まずは1件の記録から。','02','作業時間を記録 → 従来の時間と比較 → 月次結果を確認'],
            'connect'=>['CONNECT YOUR SITES','つなぐ準備は、ここから。','この画面は「まとめて管理する側」と「診断される側」で使います。','03','対象サイトで接続情報を発行 → 管理側に登録 → 診断'],
        ];$d=$map[$screen];
        echo '<header class="aaihb-work-hero work-'.$screen.'"><div><p class="aaihb-eyebrow">'.$d[0].'</p><h1>'.esc_html($d[1]).'</h1><p>'.esc_html($d[2]).'</p><div class="aaihb-actions">';
        if($screen==='ops'){foreach(['自分の担当を見る'=>'&owner='.get_current_user_id(),'期限超過を見る'=>'&overdue=1'] as $label=>$query){echo '<a class="aaihb-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-ops'.$query)).'">'.esc_html($label).' →</a>';}}
        elseif($screen==='value'){echo '<a class="aaihb-primary" data-open="value-work" href="#value-work">＋ 作業時間を記録する</a><a class="aaihb-ghost" data-open="value-report" href="#value-report">今月の結果を見る</a>';}
        else {echo '<a class="aaihb-primary" data-open="connect-register" href="#connect-register">管理する側：サイトを追加</a><a class="aaihb-ghost" data-open="connect-agent" href="#connect-agent">診断される側：接続情報を発行</a>';}
        echo '</div><p class="aaihb-work-path">'.esc_html($d[4]).'</p></div><div class="aaihb-work-number" aria-hidden="true">'.$d[3].'</div></header>';
    }
    public static function metrics($metrics) {
        echo '<section class="aaihb-metric-grid" aria-label="状況のまとめ">';
        foreach($metrics as $label=>$v){$tag=isset($v[2])?'a':'div';echo '<'.$tag.' class="aaihb-metric"'.(isset($v[2])?' href="'.esc_url($v[2]).'"':'').'><span>'.esc_html($label).'</span><strong>'.esc_html($v[0]).'</strong><small>'.esc_html($v[1]).'</small></'.$tag.'>';}
        echo '</section>';
    }
}
