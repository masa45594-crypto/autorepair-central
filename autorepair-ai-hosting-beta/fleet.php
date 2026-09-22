<?php
if (!defined('ABSPATH')) {exit;}

/** Groups, durable bounded work queue, and private diagnostic observations. */
final class AAIHB_Fleet {
    const CONFIG='aaihb_fleet_config';
    const QUEUE='aaihb_fleet_queue';
    const HISTORY='aaihb_scan_record';
    public static function boot() {
        add_action('init',[__CLASS__,'init']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_filter('cron_schedules',[__CLASS__,'schedules']);
        add_action('aaihb_fleet_tick',[__CLASS__,'worker']);
        add_action('aaihb_observation',[__CLASS__,'record'],20,4);
        add_action('admin_post_aaihb_fleet',[__CLASS__,'save']);
        add_action('admin_post_aaihb_history_csv',[__CLASS__,'csv']);
    }
    public static function init() {
        register_post_type(self::HISTORY,['public'=>false,'publicly_queryable'=>false,'show_ui'=>false,'show_in_rest'=>false,'exclude_from_search'=>true,'rewrite'=>false,'supports'=>[]]);
    }
    public static function menu() {
        add_submenu_page('aaihb-home','グループと診断キュー','グループ・診断予約',AAIHB_Operations::CAP,'aaihb-fleet',[__CLASS__,'page']);
        add_submenu_page('aaihb-home','診断履歴','診断履歴',AAIHB_Operations::CAP,'aaihb-history',[__CLASS__,'history_page']);
    }
    public static function schedules($s) {$s['aaihb_minute']=['interval'=>60,'display'=>'AutoRepair AI queue: 1 minute'];return $s;}
    public static function deactivate() {
        wp_clear_scheduled_hook('aaihb_fleet_tick');delete_option('aaihb_fleet_worker_lock');delete_option('aaihb_fleet_config_lock');
        $q=get_option(self::QUEUE,[]);if(!empty($q['jobs'])){foreach($q['jobs'] as &$j){if(in_array($j['state'],['queued','running'],true)){$j['state']='cancelled';$j['message']='プラグイン無効化により停止しました。';}}unset($j);update_option(self::QUEUE,$q,false);}
    }
    public static function info($id) {return array_merge(['group'=>'未分類','paused'=>false],get_option(self::CONFIG,[])[$id]??[]);}
    public static function paused($id) {return (bool)self::info($id)['paused'];}
    /** Atomic option insert/compare-delete; lease ownership checked again after HTTP. */
    public static function acquire($name) {
        $lease=['token'=>bin2hex(random_bytes(16)),'until'=>time()+300];
        if(add_option($name,$lease,'',false))return $lease;
        $old=get_option($name);
        if(is_array($old) && (int)$old['until']<time()) {
            self::release($name,$old);
            if(add_option($name,$lease,'',false))return $lease;
        }
        return false;
    }
    public static function release($name,$lease) {
        global $wpdb;
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",$name,maybe_serialize($lease)));
        if($deleted)wp_cache_delete($name,'options');
        return (bool)$deleted;
    }
    public static function assign($ids,$group,$pause) {
        AAIHB_Beta::allowed();
        if(!is_array($ids)||!$ids||count($ids)>100)throw new RuntimeException('1〜100サイトを選択してください。');
        $group=sanitize_text_field($group);
        if(strlen($group)>180 || !in_array($pause,['keep','pause','resume'],true))throw new RuntimeException('グループ名または停止設定が不正です。');
        $lease=self::acquire('aaihb_fleet_config_lock');if(!$lease)throw new RuntimeException('設定を保存中です。再試行してください。');
        try {
            $sites=AAIHB_Beta::sites();$config=get_option(self::CONFIG,[]);
            foreach($ids as $id){if(!is_string($id)||!isset($sites[$id]))throw new RuntimeException('登録済みのサイトを選択してください。');}
            foreach(array_unique($ids) as $id){$c=self::info($id);if($group!=='')$c['group']=$group;if($pause!=='keep')$c['paused']=$pause==='pause';$config[$id]=$c;}
            update_option(self::CONFIG,$config,false);
        }finally{self::release('aaihb_fleet_config_lock',$lease);}
    }
    public static function active() {
        $q=get_option(self::QUEUE,[]);
        foreach($q['jobs']??[] as $j){if(in_array($j['state'],['queued','running'],true))return true;}
        return false;
    }
    public static function enqueue($group) {
        AAIHB_Beta::allowed();$lease=self::acquire('aaihb_fleet_worker_lock');if(!$lease)throw new RuntimeException('診断処理中です。少し待ってください。');
        try {
            if(self::active())throw new RuntimeException('実行中の診断予約があります。完了または停止を待ってください。');
            $sites=AAIHB_Beta::sites();$jobs=[];
            foreach($sites as $id=>$s){$c=self::info($id);if(($group===''||$group===$c['group'])&&!$c['paused']){$jobs[$id]=['name'=>$s['name'],'fingerprint'=>hash('sha256',$s['token']),'state'=>'queued','attempts'=>0,'due'=>time(),'message'=>'','updated'=>gmdate('c')];}}
            if(!$jobs)throw new RuntimeException('診断できるサイトがありません。グループと診断停止の設定を確認してください。');
            if(!wp_next_scheduled('aaihb_fleet_tick')){$ok=wp_schedule_event(time()+60,'aaihb_minute','aaihb_fleet_tick',[],true);if(is_wp_error($ok)||!$ok)throw new RuntimeException('診断キューを予約できませんでした。');}
            $q=['id'=>bin2hex(random_bytes(12)),'created'=>gmdate('c'),'actor'=>get_current_user_id(),'group'=>$group?:'全グループ','jobs'=>$jobs];
            update_option(self::QUEUE,$q,false);delete_option('aaihb_fleet_cancel');return $q;
        }finally{self::release('aaihb_fleet_worker_lock',$lease);}
    }
    public static function cancel() {
        AAIHB_Beta::allowed();$q=get_option(self::QUEUE,[]);if(!empty($q['id']))update_option('aaihb_fleet_cancel',$q['id'],false);
        // Apply now when idle; an in-flight worker observes cancellation after its one HTTP call.
        self::worker();
    }
    private static function cancelled($q) {return get_option('aaihb_fleet_cancel')===($q['id']??null);}
    private static function mark_cancelled(&$q) {foreach($q['jobs'] as &$job){if($job['state']==='queued'){$job['state']='cancelled';$job['message']='予約を停止しました。';}}unset($job);}
    public static function worker() {
        if(is_multisite())return;
        $lease=self::acquire('aaihb_fleet_worker_lock');if(!$lease)return;
        try {
            // Bounded batch per invocation: WP-Cron alone gives 1 site/minute, which
            // cannot keep pace once the registry is large. Processing a few sites per
            // tick (time-boxed, so one slow host can't starve the rest) multiplies
            // effective throughput without needing a separate worker process.
            $deadline=time()+30; $max_per_tick=3; $processed=0;
            while($processed<$max_per_tick && time()<$deadline) {
                $q=get_option(self::QUEUE,[]);if(empty($q['jobs']))break;
                // A running job with no surviving lease was interrupted; reclaim with bounded attempts.
                foreach($q['jobs'] as &$j){if($j['state']==='running'){$j['state']=$j['attempts']>=3?'failed':'queued';$j['due']=time();$j['message']='中断された処理を回収しました。';}}unset($j);
                if(self::cancelled($q)){self::mark_cancelled($q);update_option(self::QUEUE,$q,false);break;}
                $sites=AAIHB_Beta::sites();$chosen=null;
                foreach($q['jobs'] as $id=>$j){if($j['state']==='queued' && $j['due']<=time()){$chosen=$id;break;}}
                if($chosen===null)break;
                $j=&$q['jobs'][$chosen];
                if(!isset($sites[$chosen])||hash('sha256',$sites[$chosen]['token'])!==$j['fingerprint']||self::paused($chosen)){$j['state']='skipped';$j['message']='登録削除・接続変更・診断停止のためスキップしました。';update_option(self::QUEUE,$q,false);$processed++;continue;}
                $j['state']='running';$j['attempts']++;$j['updated']=gmdate('c');update_option(self::QUEUE,$q,false);
                try{$result=AAIHB_Beta::scan_site($chosen);}catch(Throwable $e){$result=new WP_Error('worker_failed','診断処理が中断されました。');}
                // A worker whose lease was replaced must never overwrite its successor's state.
                if(get_option('aaihb_fleet_worker_lock')!==$lease)break;
                if(is_wp_error($result)){$j['state']=$j['attempts']>=3?'failed':'queued';$j['due']=time()+($j['attempts']===1?60:300);$j['message']=$result->get_error_message();}
                else{$j['state']='done';$j['message']='診断完了：'.$result['score'].' / 100';}
                $j['updated']=gmdate('c');unset($j);
                if(self::cancelled($q))self::mark_cancelled($q);
                update_option(self::QUEUE,$q,false);update_option('aaihb_fleet_worker_at',gmdate('c'),false);
                $processed++;
            }
        }finally{self::release('aaihb_fleet_worker_lock',$lease);}
    }
    public static function record($site,$name,$report,$error) {
        $data=['site'=>$site,'name'=>$name,'group'=>self::info($site)['group'],'at'=>gmdate('c'),'error'=>(string)$error,'report'=>$report];
        $id=wp_insert_post(wp_slash(['post_type'=>self::HISTORY,'post_status'=>'private','post_title'=>$name,'post_content'=>wp_json_encode($data),'post_author'=>get_current_user_id()]),true);
        if(is_wp_error($id)||!$id){update_option('aaihb_history_error','診断履歴を保存できませんでした。',false);return;}
        update_post_meta($id,'aaihb_history_site',$site);update_post_meta($id,'aaihb_history_group',$data['group']);update_post_meta($id,'aaihb_history_month',gmdate('Y-m'));delete_option('aaihb_history_error');
    }
    public static function history_query($group='',$site='',$month='',$page=1) {
        $meta=['relation'=>'AND'];foreach(['group'=>$group,'site'=>$site,'month'=>$month] as $k=>$v){if($v!=='')$meta[]=['key'=>'aaihb_history_'.$k,'value'=>$v];}
        return new WP_Query(['post_type'=>self::HISTORY,'post_status'=>'private','posts_per_page'=>25,'paged'=>max(1,(int)$page),'meta_query'=>$meta,'orderby'=>'ID','order'=>'DESC']);
    }
    private static function param($key){return isset($_GET[$key])&&is_string($_GET[$key])?sanitize_text_field(wp_unslash($_GET[$key])):'';}
    private static function form($op){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_fleet"><input type="hidden" name="op" value="'.esc_attr($op).'">';wp_nonce_field('aaihb_fleet');}
    public static function save() {
        AAIHB_Beta::allowed();check_admin_referer('aaihb_fleet');
        try{
            $op=AAIHB_Beta::input('op');
            if($op==='assign'){self::assign(isset($_POST['sites'])&&is_array($_POST['sites'])?wp_unslash($_POST['sites']):[],AAIHB_Beta::input('group'),AAIHB_Beta::input('pause'));}
            elseif($op==='queue'){self::enqueue(AAIHB_Beta::input('group'));}
            elseif($op==='cancel'){self::cancel();}
            elseif($op==='step'){self::worker();}
            else{throw new RuntimeException('操作が不正です。');}
            $notice='処理しました。下の状況をご確認ください。';
        }catch(Throwable $e){$notice=$e->getMessage();}
        set_transient('aaihb_fleet_notice_'.get_current_user_id(),$notice,60);wp_safe_redirect(admin_url('admin.php?page=aaihb-fleet'));exit;
    }
    public static function page() {
        AAIHB_Operations::allowed();nocache_headers();$sites=AAIHB_Beta::sites();$groups=[];
        foreach($sites as $id=>$s){$c=self::info($id);if(!isset($groups[$c['group']]))$groups[$c['group']]=['count'=>0,'paused'=>0,'critical'=>0,'warning'=>0,'healthy'=>0,'unknown'=>0];$groups[$c['group']]['count']++;$groups[$c['group']]['paused']+=(int)$c['paused'];$groups[$c['group']][$c['paused']?'unknown':AAIHB_Dashboard::classification($s)]++;}
        echo '<div class="wrap aaihb-app aaihb-workspace"><h1>グループで、まとめて運用。</h1>';AAIHB_Dashboard::nav('aaihb-fleet');
        $notice=get_transient('aaihb_fleet_notice_'.get_current_user_id());delete_transient('aaihb_fleet_notice_'.get_current_user_id());if($notice)echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        echo '<p>顧客・部署・用途でサイトを分け、まとめて診断を予約できます。予約後はこの画面を閉じても処理が進みます。WP-Cronの実行が必要です。</p><div class="aaihb-site-grid">';
        foreach($groups as $name=>$g){echo '<article class="aaihb-site tone-'.($g['critical']?'critical':($g['warning']?'warning':'unknown')).'"><h2>'.esc_html($name).'</h2><div class="aaihb-site-body"><strong class="aaihb-site-score">'.$g['count'].'</strong><span>サイト</span></div><p>重大 '.$g['critical'].' / 注意 '.$g['warning'].' / 正常 '.$g['healthy'].' / 不明 '.$g['unknown'].'</p><p>診断停止中 '.$g['paused'].'サイト</p><a class="aaihb-link" href="'.esc_url(add_query_arg(['page'=>'aaihb-history','group'=>$name],admin_url('admin.php'))).'">このグループの履歴 →</a></article>';}
        if(!$groups)echo '<p>接続設定でサイトを登録してください。</p>';echo '</div>';
        if(current_user_can('manage_options')){
            echo '<h2>① サイトをグループに分ける</h2>';self::form('assign');echo '<table class="widefat striped"><thead><tr><th>選択</th><th>サイト</th><th>現在のグループ</th><th>診断</th></tr></thead><tbody>';
            foreach($sites as $id=>$s){$c=self::info($id);echo '<tr><td><input aria-label="'.esc_attr($s['name']).'を選択" type="checkbox" name="sites[]" value="'.esc_attr($id).'"></td><td>'.esc_html($s['name']).'</td><td>'.esc_html($c['group']).'</td><td>'.($c['paused']?'停止中':'有効').'</td></tr>';}
            echo '</tbody></table><p><label>グループ名 <input name="group" placeholder="例：A社 / ECサイト"></label> 空欄ならグループを変更しません。</p><p><label>診断の扱い <select name="pause"><option value="keep">変更しない</option><option value="pause">選択サイトの診断を停止</option><option value="resume">選択サイトの診断を再開</option></select></label></p>';submit_button('選択サイトに適用');echo '</form><h2>② 診断をバックグラウンド予約</h2>';self::form('queue');echo '<label>対象グループ <select name="group"><option value="">全グループ</option>';foreach($groups as $name=>$g){echo '<option value="'.esc_attr($name).'">'.esc_html($name).'</option>';}echo '</select></label>';submit_button('診断を予約する');echo '</form>';
        }
        $q=get_option(self::QUEUE,[]);$counts=array_fill_keys(['queued','running','done','failed','skipped','cancelled'],0);foreach($q['jobs']??[] as $j)$counts[$j['state']]++;
        echo '<h2>診断キュー</h2><p>待機 '.$counts['queued'].' / 実行中 '.$counts['running'].' / 完了 '.$counts['done'].' / 失敗 '.$counts['failed'].' / スキップ '.$counts['skipped'].' / 停止 '.$counts['cancelled'].'</p><p>予約した直後に最大3サイトをすぐ処理し、その後は1分ごとに最大3サイトずつ進みます。失敗は最大3回まで試行し、再試行待ちは他のサイトを先に進めます。</p><p>最終実行（UTC）：'.esc_html(get_option('aaihb_fleet_worker_at','未実行')).'</p>';
        if(current_user_can('manage_options')){self::form('step');submit_button('今すぐ1件進める','secondary');echo '</form>';self::form('cancel');submit_button('残りの予約を停止する','secondary');echo '</form>';}
        echo '<table class="widefat striped"><thead><tr><th>サイト</th><th>進捗</th><th>試行数</th><th>結果</th></tr></thead><tbody>';$labels=['queued'=>'待機','running'=>'実行中','done'=>'完了','failed'=>'失敗','skipped'=>'スキップ','cancelled'=>'停止'];
        foreach($q['jobs']??[] as $j){echo '<tr><td>'.esc_html($j['name']).'</td><td>'.esc_html($labels[$j['state']]).'</td><td>'.(int)$j['attempts'].' / 3</td><td>'.esc_html($j['message']).'</td></tr>';}
        echo '</tbody></table><p>進捗は画面の再読み込みで更新します。「完了」は診断データを取得した意味です。異常を修復した意味ではありません。</p></div>';
    }
    public static function history_page() {
        AAIHB_Operations::allowed();nocache_headers();$group=self::param('group');$site=self::param('site');$month=self::param('month');$page=max(1,absint(self::param('paged')));$q=self::history_query($group,$site,$month,$page);
        echo '<div class="wrap aaihb-app aaihb-workspace"><h1>診断の変化を、履歴で確認。</h1>';AAIHB_Dashboard::nav('aaihb-history');echo '<p>更新後に実行した診断を記録します。日時・集計月はUTCです。診断に成功しても、修復が完了したことを意味しません。</p>';
        if(get_option('aaihb_history_error'))echo '<div class="notice notice-error"><p>'.esc_html(get_option('aaihb_history_error')).'</p></div>';
        echo '<form method="get"><input type="hidden" name="page" value="aaihb-history"><label>グループ <input name="group" value="'.esc_attr($group).'" placeholder="空欄で全グループ"></label> <label>月 <input type="month" name="month" value="'.esc_attr($month).'"></label><input type="hidden" name="site" value="'.esc_attr($site).'"><button class="button">絞り込む</button></form><p>'.(int)$q->found_posts.'件 / '.$page.'ページ。グループは診断時点の所属です。</p><table class="widefat striped"><thead><tr><th>日時</th><th>サイト・グループ</th><th>結果</th><th>診断項目</th></tr></thead><tbody>';
        foreach($q->posts as $p){$d=json_decode($p->post_content,true);echo '<tr><td>'.esc_html($d['at']).'</td><td>'.esc_html($d['name']).'<br>'.esc_html($d['group']).'</td><td>'.($d['error']?'診断失敗：'.esc_html($d['error']):(int)$d['report']['score'].' / 100').'</td><td><details><summary>詳細</summary>';foreach($d['report']['checks']??[] as $c){echo esc_html(AAIHB_Value::guide($c['id'])[0].'：'.(['healthy'=>'正常','warning'=>'注意','critical'=>'重大'][$c['status']]??$c['status'])).'<br>';}echo '</details></td></tr>';}
        if(!$q->posts)echo '<tr><td colspan="4">該当する履歴はありません。診断を実行すると追加されます。</td></tr>';echo '</tbody></table><p>';
        foreach([$page-1=>'前へ',$page+1=>'次へ'] as $n=>$label){if($n>=1&&$n<=$q->max_num_pages)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'aaihb-history','group'=>$group,'site'=>$site,'month'=>$month,'paged'=>$n],admin_url('admin.php'))).'">'.esc_html($label).'</a> ';}echo '</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_history_csv">';wp_nonce_field('aaihb_history_csv');foreach(['group'=>$group,'site'=>$site,'month'=>$month,'paged'=>$page] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';submit_button('表示中の履歴をCSV出力（最大25件）','secondary');echo '</form></div>';
    }
    public static function csv() {
        AAIHB_Operations::allowed();check_admin_referer('aaihb_history_csv');$q=self::history_query(AAIHB_Beta::input('group'),AAIHB_Beta::input('site'),AAIHB_Beta::input('month'),max(1,absint(AAIHB_Beta::input('paged'))));
        nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="autorepair-diagnostic-history.csv"');$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['日時UTC','サイト','診断時グループ','スコア','診断エラー','項目別判定']);foreach($q->posts as $p){$d=json_decode($p->post_content,true);fputcsv($f,array_map(['AAIHB_Value','csv_cell'],[$d['at'],$d['name'],$d['group'],$d['report']['score']??'',$d['error'],wp_json_encode($d['report']['checks']??[])]));}fclose($f);exit;
    }
}
