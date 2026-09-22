<?php
if (!defined('ABSPATH')) { exit; }
final class AAIHB_Dashboard {
    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu'], 9);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }
    public static function menu() {
        add_menu_page('AutoRepair AI 運用', 'AI運用ホーム', AAIHB_Operations::CAP, 'aaihb-home', [__CLASS__, 'page'], 'dashicons-shield-alt', 30);
    }
    public static function assets() {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (!in_array($page, ['aaihb-home','aaihb-ops','aaihb','aaihb-value','aaihb-fleet','aaihb-history','aaihb-notify','aaihb-partner','aaihb-start','aaihb-buy','aaihb-help','aaihb-license','aaihb-recovery','aaihb-vault','aaihb-protect','aaihb-respond','aaihb-grow','aaihb-emergency','aaihb-verification','aaihb-get-started'],true)) {return;}
        wp_enqueue_style('aaihb-dashboard', plugins_url('dashboard.css',__FILE__), [], '0.14.2');
        wp_enqueue_style('aaihb-workspace',plugins_url('workspace.css',__FILE__),['aaihb-dashboard'],'0.14.2');
        wp_enqueue_script('aaihb-workspace',plugins_url('workspace.js',__FILE__),[],'0.14.2',true);
        if(in_array($page,['aaihb-start','aaihb-buy','aaihb-help','aaihb-license','aaihb-recovery','aaihb-protect','aaihb-respond','aaihb-grow','aaihb-emergency','aaihb-verification','aaihb-get-started'],true)){wp_enqueue_style('aaihb-selfserve',plugins_url('selfserve.css',__FILE__),['aaihb-dashboard'],'0.20.4');wp_enqueue_script('aaihb-selfserve',plugins_url('selfserve.js',__FILE__),[],'0.20.4',true);}
        if ($page === 'aaihb-home') {
            wp_enqueue_script('aaihb-dashboard',plugins_url('dashboard.js',__FILE__),[],'0.14.2',true);
            wp_localize_script('aaihb-dashboard','AAIHB_HOME',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('aaihb_scan'),'canScan'=>current_user_can('manage_options')]);
        }
        if ($page === 'aaihb-value') {
            wp_enqueue_script('aaihb-timer',plugins_url('timer.js',__FILE__),[],'0.14.2',true);
        }
        if ($page === 'aaihb') {
            wp_enqueue_script('aaihb-connect',plugins_url('connect.js',__FILE__),['aaihb-workspace'],'0.14.2',true);
        }
    }
    public static function nav($active) {
        $links=['aaihb-home'=>'全体を見る','aaihb-protect'=>'① 守る','aaihb-respond'=>'② 対応する','aaihb-grow'=>'③ 広げる'];
        if(current_user_can('manage_options')) {$links+=['aaihb-get-started'=>'はじめる','aaihb-emergency'=>'緊急モード','aaihb-verification'=>'最新検証結果','aaihb-recovery'=>'自動修復・復元','aaihb-vault'=>'バックアップ・復元','aaihb-license'=>'利用数と料金','aaihb-start'=>'かんたん接続','aaihb-buy'=>'購入・契約管理','aaihb-help'=>'困ったとき','aaihb-value'=>'効果を確認','aaihb'=>'接続設定','aaihb-notify'=>'通知設定','aaihb-partner'=>'パートナーAPI','aaihb-ops'=>'対応案件','aaihb-fleet'=>'グループ・予約','aaihb-history'=>'診断履歴'];}
        echo '<nav class="aaihb-nav" aria-label="AutoRepair AIの画面">';
        foreach($links as $slug=>$label){echo '<a '.($slug===$active?'aria-current="page"':'').' href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($label).'</a>';}
        echo '</nav>';
    }
    public static function classification($s) {
        if(!empty($s['fleet_paused']) || !empty($s['error']) || empty($s['report']) || strtotime($s['report']['generated_at']) < time()-172800) {return 'unknown';}
        $status='healthy';
        foreach($s['report']['checks'] as $c){if($c['status']==='critical')return 'critical';if($c['status']==='warning')$status='warning';}
        return $status;
    }
    public static function labels() {return ['critical'=>'重大な異常','warning'=>'確認が必要','healthy'=>'診断項目は正常','unknown'=>'状態を確認できず'];}
    public static function page() {
        AAIHB_Operations::allowed();nocache_headers();
        $sites=AAIHB_Beta::sites();$counts=array_fill_keys(array_keys(self::labels()),0);
        foreach($sites as $id=>&$entry){$entry['fleet_paused']=AAIHB_Fleet::paused($id);}unset($entry);
        foreach($sites as $s){$counts[self::classification($s)]++;}
        $admin=current_user_can('manage_options');$settings=AAIHB_Beta::settings();
        echo '<div class="wrap aaihb-app aaihb-home" style="--blue:'.esc_attr($settings['accent']).'"><header class="aaihb-brand">'.($settings['hide_branding']?'':'<div><span class="aaihb-mark" aria-hidden="true">✦</span><strong>AutoRepair AI</strong><span class="aaihb-edition">HOSTING / 0.20.5</span></div>').'<span>'.esc_html($settings['company']?:'サイト運用ワークスペース').'</span></header>';
        self::nav('aaihb-home');
        if($admin){$on=AAIHB_Onboarding::steps();$remaining=0;foreach($on as $step)if(!$step['ok'])$remaining++;if($remaining)echo '<section class="aaihb-first-run"><div><small>GET STARTED · 残り '.(int)$remaining.'項目</small><h2>AutoRepair AI を始めましょう</h2><p>最初は5つの設定だけです。次にすることを1つずつ案内します。</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-get-started')).'">導入を開始する</a></section>';}
        echo '<section class="aaihb-route" aria-label="運用の入口"><a href="'.esc_url(admin_url('admin.php?page=aaihb-protect')).'"><b>①</b><span><strong>守る</strong><small>バックアップ・復元テスト</small></span></a><a href="'.esc_url(admin_url('admin.php?page=aaihb-respond')).'"><b>②</b><span><strong>対応する</strong><small>障害・更新・復旧履歴</small></span></a><a href="'.esc_url(admin_url('admin.php?page=aaihb-grow')).'"><b>③</b><span><strong>広げる</strong><small>接続サイト・チーム・料金</small></span></a></section>';
        if($admin){$v=AAIHB_Verification::remote();$vs=AAIHB_Verification::summary($v);echo '<section class="aaihb-verify-home"><div><small>PRODUCT VERIFICATION</small><h2>最新検証結果</h2><p>✓ '.(int)$vs['passed'].'件確認済み　△ '.(int)$vs['pending'].'件未検証　○ '.(int)$vs['preparing'].'件準備中</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-verification')).'">検証結果を見る</a></section>';}
        echo '<section class="aaihb-hero"><div><p class="aaihb-eyebrow">YOUR SITES. ONE VIEW.</p><h1>サイトの状態を、<br>ひと目で。</h1><p>まず診断。気になるサイトから確認しましょう。</p><div class="aaihb-actions">';
        if($admin && $sites){echo '<button type="button" class="aaihb-primary" id="aaihb-home-scan">▶ 全サイトを診断する</button><button type="button" class="aaihb-ghost" id="aaihb-home-stop" disabled>停止する</button>';}
        elseif($admin){echo '<a class="aaihb-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-start')).'">＋ 最初のサイトを接続する</a>';}
        else {echo '<a class="aaihb-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-ops&owner='.get_current_user_id())).'">自分の担当案件を見る →</a>';}
        echo '</div><p class="aaihb-hero-note">'.($admin?'診断は状態の確認です。修復や更新は実行しません。':'診断・接続の設定は管理者が行います。').'</p></div><div class="aaihb-orbit"><div class="aaihb-orbit-inner"><span>管理しているサイト</span><strong>'.count($sites).'</strong><span>SITES CONNECTED</span></div></div></section>';
        echo '<div class="aaihb-progress-panel"><div id="aaihb-home-progress" role="status" aria-live="polite">'.($sites?'診断を開始すると、ここに進み具合を表示します。':'接続設定でサイトを登録すると、この画面に表示されます。').'</div><progress id="aaihb-home-meter" value="0" max="'.max(1,count($sites)).'" aria-label="診断の進み具合"></progress></div>';
        echo '<section class="aaihb-stats" aria-label="サイトの状態別件数">';
        $symbols=['critical'=>'!','warning'=>'△','healthy'=>'✓','unknown'=>'?'];
        foreach(self::labels() as $key=>$label){echo '<button type="button" class="aaihb-stat tone-'.$key.'" data-filter="'.$key.'" aria-pressed="false"><span class="aaihb-stat-top"><span class="aaihb-symbol" aria-hidden="true">'.$symbols[$key].'</span>'.esc_html($label).'</span><strong data-count="'.$key.'">'.$counts[$key].'<small>サイト</small></strong><span>クリックして絞り込む →</span></button>';}
        echo '</section><section class="aaihb-route" aria-label="使い方"><div><b>01</b><span><strong>診断する</strong><small>登録サイトを順番に確認</small></span></div><div><b>02</b><span><strong>異常を見る</strong><small>色付きカードから対象を選ぶ</small></span></div><div><b>03</b><span><strong>対応を進める</strong><small>担当・期限・状況を記録</small></span></div></section>';
        echo '<section class="aaihb-sites"><div class="aaihb-section-head"><div><h2>サイト一覧</h2><p>正常は診断対象の項目についての判定です。サイト全体の安全を保証する表示ではありません。</p></div><a class="aaihb-link" href="'.esc_url(admin_url('admin.php?page=aaihb-ops')).'">対応案件を開く →</a></div><div class="aaihb-toolbar"><label>サイトを探す <input type="search" id="aaihb-home-search" placeholder="サイト名を入力"></label><button class="button" type="button" data-filter="all" aria-pressed="true">すべて表示</button><span id="aaihb-home-visible" aria-live="polite"></span></div><div class="aaihb-site-grid">';
        uasort($sites,function($a,$b){$rank=['critical'=>0,'warning'=>1,'unknown'=>2,'healthy'=>3];return $rank[self::classification($a)]<=>$rank[self::classification($b)];});
        foreach($sites as $id=>$s){$type=self::classification($s);$r=$s['report'];
            echo '<article class="aaihb-site tone-'.$type.'" data-site="'.esc_attr($id).'" data-name="'.esc_attr($s['name']).'" data-state="'.$type.'"><div class="aaihb-site-heading"><span class="aaihb-site-icon" aria-hidden="true">▣</span><h3>'.esc_html($s['name']).'</h3></div><span class="aaihb-status">'.esc_html(self::labels()[$type]).'</span><div class="aaihb-site-body"><strong class="aaihb-site-score">'.($type==='unknown'?'—':(int)$r['score']).'</strong><span class="aaihb-score-caption">'.($type==='unknown'?'新しい診断が必要です':'/ 100　診断スコア').'</span></div><p class="aaihb-site-message">'.esc_html(!empty($s['fleet_paused'])?'診断を停止中です。グループ・予約で再開できます。':($s['error']?:($type==='unknown'?'未診断、または前回の結果が48時間以上前です。':'判定日時（UTC）：'.$r['generated_at']))).'</p><div class="aaihb-site-actions">';
            if($admin){echo '<button class="button aaihb-home-one" type="button" data-id="'.esc_attr($id).'">このサイトを診断</button>';}
            echo '<a class="aaihb-link" href="'.esc_url(add_query_arg(['page'=>'aaihb-ops','search'=>$s['name']],admin_url('admin.php'))).'">対応を見る →</a></div><details><summary>診断項目を見る</summary><div class="aaihb-checks">';
            if($r){foreach($r['checks'] as $c){echo '<p>'.esc_html(AAIHB_Value::guide($c['id'])[0]).'<span>'.esc_html(['healthy'=>'正常','warning'=>'注意','critical'=>'重大'][$c['status']]).'</span></p>';}}else{echo '<p>まだ診断結果がありません。</p>';}
            echo '</div></details></article>';
        }
        echo '</div><p id="aaihb-home-empty" '.($sites?'hidden':'').'>表示するサイトがありません。'.($admin?'「接続設定」で対象サイトを登録してください。':'管理者に対象サイトの登録を依頼してください。').'</p></section><footer class="aaihb-footer"><span>定期診断：'.(get_option('aaihb_auto_scan')?'有効':'無効').' ／ 最終実行（UTC）：'.esc_html(get_option('aaihb_tick_at','未実行')).'</span><span>Powered by AutoRepair AI</span></footer></div>';
    }
}
