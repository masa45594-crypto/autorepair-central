<?php
if (!defined('ABSPATH')) { exit; }

/** Team operations. One WordPress installation is one trusted company workspace. */
final class AAIHB_Operations {
    const CAP = 'aaihb_operate';
    const META = 'aaihb_ops';
    public static function boot() {
        add_action('init', [__CLASS__, 'roles']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aaihb_ops', [__CLASS__, 'save']);
        add_action('admin_post_aaihb_ops_csv', [__CLASS__, 'csv']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('added_post_meta', [__CLASS__, 'meta_changed'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'meta_changed'], 10, 4);
    }
    public static function roles() {
        if (is_multisite() || get_option('aaihb_ops_roles') === '1') { return; }
        add_role('aaihb_operator', 'AutoRepair AI 運用担当者', ['read'=>true, self::CAP=>true]);
        $admin = get_role('administrator'); if ($admin) { $admin->add_cap(self::CAP); }
        update_option('aaihb_ops_roles', '1', false);
    }
    public static function allowed() {
        if (is_multisite() || !(current_user_can(self::CAP) || current_user_can('manage_options'))) { wp_die('運用担当者の権限が必要です。', '', ['response'=>403]); }
    }
    public static function menu() {
        add_menu_page('AutoRepair AI チーム運用', 'AIチーム運用', self::CAP, 'aaihb-ops', [__CLASS__, 'page'], 'dashicons-clipboard', 31);
    }
    public static function statuses() { return ['open'=>'未着手','working'=>'対応中','waiting'=>'保留','done'=>'対応済み']; }
    public static function routes() {
        register_rest_route('aaihb/v1','/operations/cases',['methods'=>'GET','permission_callback'=>[__CLASS__,'api_allowed'],'callback'=>[__CLASS__,'api_list']]);
    }
    public static function api_allowed() {
        return !is_multisite() && is_ssl() && (current_user_can(self::CAP) || current_user_can('manage_options'));
    }
    public static function api_list($request) {
        $filter=[];
        foreach(['search','status','severity','owner','overdue'] as $key) {
            $value=$request->get_param($key);
            if($value !== null && !is_scalar($value)) {return new WP_Error('invalid_filter','検索条件が不正です。',['status'=>400]);}
            $filter[$key]=$value===null?'':sanitize_text_field((string)$value);
        }
        $page=$request->get_param('page');
        if($page!==null && (!is_scalar($page) || !ctype_digit((string)$page) || (int)$page<1 || (int)$page>100000)) {return new WP_Error('invalid_page','ページ番号が不正です。',['status'=>400]);}
        $q=self::query($filter,$page?(int)$page:1);$items=[];
        foreach($q->posts as $p) {$d=AAIHB_Value::case_data($p->ID);$s=self::state($p->ID);$items[]=['id'=>(int)$p->ID,'title'=>$p->post_title,'check'=>$d['check'],'severity'=>$d['severity'],'signal_open'=>$d['signal_open'],'observed_at'=>$d['last'],'status'=>$s['status'],'owner'=>$s['owner'],'due'=>$s['due'],'version'=>$s['version']];}
        $response=new WP_REST_Response(['items'=>$items,'total'=>(int)$q->found_posts,'pages'=>(int)$q->max_num_pages,'per_page'=>25]);$response->header('Cache-Control','no-store');return $response;
    }
    public static function state($id) {
        $s = get_post_meta($id, self::META, true);
        return is_array($s) ? $s : ['version'=>0, 'status'=>'open', 'owner'=>0, 'due'=>'', 'history'=>[]];
    }
    private static function lock($fn) {
        if (!add_option('aaihb_ops_lock', time(), '', false)) { throw new RuntimeException('別の担当者が更新中です。少し待って再試行してください。'); }
        try { return $fn(); } finally { delete_option('aaihb_ops_lock'); }
    }
    private static function index($id, $s, $d) {
        foreach (['status'=>$s['status'], 'owner'=>$s['owner'], 'due'=>$s['due'], 'severity'=>$d['severity']] as $key=>$v) {
            update_post_meta($id, 'aaihb_ops_' . $key, $v);
        }
    }
    public static function meta_changed($mid, $id, $key, $value) {
        if ($key !== 'aaihb_data' || get_post_type($id) !== AAIHB_Value::CASE_TYPE) { return; }
        // Scan callbacks only change severity after initialization, avoiding stale human indexes.
        update_post_meta($id,'aaihb_ops_severity',$value['severity']);
        if (!metadata_exists('post',$id,'aaihb_ops_status')) {
            try {self::lock(function() use ($id,$value) {self::index($id,self::state($id),$value);});}
            catch (Throwable $e) { /* The next migration batch picks up missing indexes. */ }
        }
    }
    public static function migrate() {
        self::allowed();
        return self::lock(function() {
            $q = new WP_Query(['post_type'=>AAIHB_Value::CASE_TYPE, 'post_status'=>'private', 'posts_per_page'=>100, 'fields'=>'ids', 'meta_query'=>[['key'=>'aaihb_ops_status','compare'=>'NOT EXISTS']], 'orderby'=>'ID','order'=>'ASC']);
            foreach ($q->posts as $id) { self::index($id, self::state($id), AAIHB_Value::case_data($id)); }
            return max(0, $q->found_posts - count($q->posts));
        });
    }
    public static function change($id, $version, $status, $owner, $due, $note) {
        self::allowed();
        return self::lock(function() use ($id,$version,$status,$owner,$due,$note) {
            $d = AAIHB_Value::case_data($id); $s = self::state($id);
            if ($s['version'] !== $version) { throw new RuntimeException('別の担当者が先に更新しました。画面を開き直して最新の状態を確認してください。'); }
            if (!array_key_exists($status,self::statuses())) { throw new RuntimeException('対応状況が不正です。'); }
            if ($owner && !(user_can($owner,self::CAP) || user_can($owner,'manage_options'))) { throw new RuntimeException('担当者には運用担当者か管理者を指定してください。'); }
            if ($due !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due, new DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d') !== $due) { throw new RuntimeException('期限の日付が不正です。'); }
            }
            $note = sanitize_textarea_field($note);
            if (!$note || strlen($note)>3000) { throw new RuntimeException('対応内容・変更理由を3000バイト以内で記入してください。'); }
            if ($status === 'done' && $d['check'] !== 'routine') {
                $site=AAIHB_Beta::site($d['site']);$healthy=false;
                if($site && !$site['error'] && $site['report'] && strtotime($site['report']['generated_at'])>=time()-172800) {
                    $healthy=$d['check']==='connection';
                    foreach($site['report']['checks'] as $check){if($check['id']===$d['check'] && $check['status']==='healthy'){$healthy=true;}}
                }
                if ($d['signal_open'] || !$healthy) {
                    throw new RuntimeException('対応済みにするには、登録サイトを再診断して正常判定を確認してください（48時間以内）。誤検知の疑いは理由を記載して保留にしてください。');
                }
            }
            $next = ['version'=>$s['version']+1, 'status'=>$status, 'owner'=>$owner, 'due'=>$due, 'history'=>$s['history']];
            $next['history'][] = ['at'=>gmdate('c'),'actor'=>get_current_user_id(),'from'=>[$s['status'],$s['owner'],$s['due']], 'to'=>[$status,$owner,$due], 'note'=>$note];
            // State and its history are one metadata value, committed together.
            if (!update_post_meta($id,self::META,$next)) { throw new RuntimeException('対応履歴を保存できませんでした。'); }
            self::index($id,$next,$d);
            return $next;
        });
    }
    public static function query($filter, $page = 1) {
        $meta = ['relation'=>'AND'];
        if (!empty($filter['status']) && isset(self::statuses()[$filter['status']])) { $meta[]=['key'=>'aaihb_ops_status','value'=>$filter['status']]; }
        if (!empty($filter['severity']) && in_array($filter['severity'],['critical','warning','info'],true)) { $meta[]=['key'=>'aaihb_ops_severity','value'=>$filter['severity']]; }
        if (isset($filter['owner']) && $filter['owner'] !== '') { $meta[]=['key'=>'aaihb_ops_owner','value'=>(int)$filter['owner'],'type'=>'NUMERIC']; }
        if (!empty($filter['overdue'])) {
            $meta[]=['key'=>'aaihb_ops_due','value'=>['2000-01-01',gmdate('Y-m-d',time()-86400)],'compare'=>'BETWEEN','type'=>'DATE'];
            $meta[]=['key'=>'aaihb_ops_status','value'=>'done','compare'=>'!='];
        }
        return new WP_Query(['post_type'=>AAIHB_Value::CASE_TYPE,'post_status'=>'private','posts_per_page'=>25,'paged'=>max(1,(int)$page),'s'=>sanitize_text_field($filter['search']??''),'meta_query'=>$meta,'orderby'=>'ID','order'=>'DESC']);
    }
    private static function param($key) { return isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : ''; }
    private static function form($op) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aaihb_ops"><input type="hidden" name="op" value="' . esc_attr($op) . '">'; wp_nonce_field('aaihb_ops');
    }
    public static function save() {
        self::allowed(); check_admin_referer('aaihb_ops'); $id=absint(AAIHB_Beta::input('case'));
        try {
            if (AAIHB_Beta::input('op') === 'migrate') { self::migrate(); }
            else { self::change($id,absint(AAIHB_Beta::input('version')),AAIHB_Beta::input('status'),absint(AAIHB_Beta::input('owner')),AAIHB_Beta::input('due'),AAIHB_Beta::input('note')); }
            $message='保存しました。';
        } catch (Throwable $e) {$message=$e->getMessage();}
        set_transient('aaihb_ops_notice_'.get_current_user_id(),$message,60);
        wp_safe_redirect(admin_url('admin.php?page=aaihb-ops' . ($id ? '&case='.$id : ''))); exit;
    }
    public static function page() {
        self::allowed(); nocache_headers();
        echo '<div class="wrap aaihb-app aaihb-workspace" data-screen="ops">'; AAIHB_Workspace::hero('ops'); AAIHB_Dashboard::nav('aaihb-ops'); echo '<p>「対応を開く」を押して、担当・期限・次の対応を決めましょう。日時・期限はUTCです。</p>';
        $notice=get_transient('aaihb_ops_notice_'.get_current_user_id()); delete_transient('aaihb_ops_notice_'.get_current_user_id());
        if ($notice) {echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';}
        try {$remaining=self::migrate();} catch(Throwable $e) {$remaining=-1; echo '<p>'.esc_html($e->getMessage()).'</p>';}
        if ($remaining) {echo '<div class="notice notice-warning"><p>旧案件の検索登録が未完了です。絞り込み結果は一部です。残り：'.(int)$remaining.'件</p>';self::form('migrate');submit_button('次の100件を検索登録','secondary');echo '</form></div>';}
        AAIHB_Workspace::metrics(['まだ着手していない案件'=>[self::query(['status'=>'open'])->found_posts.' 件','担当者を決めて、対応を始めましょう。',admin_url('admin.php?page=aaihb-ops&status=open')],'自分が担当する案件'=>[self::query(['owner'=>(string)get_current_user_id()])->found_posts.' 件','自分の担当一覧を開きます。',admin_url('admin.php?page=aaihb-ops&owner='.get_current_user_id())],'期限を過ぎた案件'=>[self::query(['overdue'=>'1'])->found_posts.' 件','優先して状況を確認しましょう。',admin_url('admin.php?page=aaihb-ops&overdue=1')]]);
        $sites=AAIHB_Beta::sites(); $failed=0;$stale=0;
        foreach($sites as $s) {if($s['error'])$failed++;if(!$s['report'] || strtotime($s['report']['generated_at'])<time()-172800)$stale++;}
        echo '<p><strong>登録 '.count($sites).'サイト ／ 直近診断失敗 '.$failed.' ／ 未診断・48時間以上古い結果 '.$stale.'</strong></p>';
        echo '<p>定期診断：'.(get_option('aaihb_auto_scan')?'有効':'無効').' ／ 最終実行：'.esc_html(get_option('aaihb_tick_at','未実行')).'</p>';
        if(current_user_can('manage_options')) {echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb')).'">接続設定・手動診断</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=aaihb-value')).'">作業時間・月次集計</a></p>';}
        $id=absint(self::param('case')); if($id) {self::detail($id);echo '</div>';return;}
        $f=[];foreach(['search','status','severity','owner','overdue'] as $key){$f[$key]=self::param($key);}
        echo '<details class="aaihb-filter"><summary>条件を指定して案件を探す</summary><form method="get"><input type="hidden" name="page" value="aaihb-ops"><label>サイト名・案件名 <input name="search" value="'.esc_attr($f['search']).'"></label> ';
        self::select('status',[''=>'全状況']+self::statuses(),$f['status']);
        self::select('severity',[''=>'全重要度','critical'=>'重大','warning'=>'注意','info'=>'定期点検'],$f['severity']);
        self::owners($f['owner'],true);echo '<label><input type="checkbox" name="overdue" value="1" '.checked($f['overdue'],'1',false).'>期限超過</label> <button class="button button-primary">絞り込む</button></form></details>';
        $page=max(1,absint(self::param('paged')));$q=self::query($f,$page);
        echo '<p>'.(int)$q->found_posts.'件 ／ '.$page.'ページ</p><div class="aaihb-case-grid">';
        foreach($q->posts as $p) {$d=AAIHB_Value::case_data($p->ID);$s=self::state($p->ID);$u=$s['owner']?get_userdata($s['owner']):null;
            echo '<article class="aaihb-case tone-'.esc_attr($d['severity']==='critical'?'critical':($d['severity']==='warning'?'warning':'unknown')).'"><span class="aaihb-status">'.esc_html(self::statuses()[$s['status']]).'</span><span class="aaihb-status">'.esc_html(['critical'=>'重大','warning'=>'注意','info'=>'定期点検'][$d['severity']]??$d['severity']).'</span><h3>'.esc_html($p->post_title).'</h3><div class="aaihb-case-meta"><span>'.esc_html($u?$u->display_name:($s['owner']?'削除済み担当者':'担当未定')).'</span><span>'.esc_html($s['due']?:'期限未設定').'</span></div><p>最終観測：'.($d['check']==='routine'?'手動作成':($d['signal_open']?'異常':'正常')).'<br>'.esc_html($d['last']).'</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=aaihb-ops&case='.$p->ID)).'">対応を開く →</a></article>';
        }
        if(!$q->posts) {echo '<p>該当する案件はありません。管理者は接続設定画面で診断してください。</p>';}
        echo '</div><p>';
        foreach ([$page-1=>'前へ',$page+1=>'次へ'] as $n=>$label) {if($n>=1 && $n<=$q->max_num_pages){echo '<a class="button" href="'.esc_url(add_query_arg(array_merge($f,['page'=>'aaihb-ops','paged'=>$n]),admin_url('admin.php'))).'">'.esc_html($label).'</a> ';}}
        echo '</p><details><summary>チームで使い始めるには</summary><p>管理者がWordPressのユーザー画面で担当者の権限グループを「AutoRepair AI 運用担当者」に設定します。この権限は全案件の閲覧・担当変更が可能です。接続トークン・料金設定・プラグイン管理はできません。顧客をこの権限で招待しないでください。</p></details></div>';
    }
    private static function select($name,$choices,$value) {
        echo '<select aria-label="'.esc_attr($name).'" name="'.esc_attr($name).'">';foreach($choices as $k=>$v){echo '<option value="'.esc_attr($k).'" '.selected((string)$value,(string)$k,false).'>'.esc_html($v).'</option>';}echo '</select> ';
    }
    private static function owners($selected,$all=false) {
        $choices=$all?[''=>'全担当者',0=>'未割当']:[0=>'未割当'];
        $users=get_users(['role__in'=>['administrator','aaihb_operator'],'number'=>200,'orderby'=>'display_name','order'=>'ASC']);
        foreach($users as $u){$choices[$u->ID]=$u->display_name . ' (#'.$u->ID.')';}
        if($selected && !isset($choices[$selected])) {$u=get_userdata($selected);$choices[$selected]=$u?$u->display_name:'削除済み担当者';}
        self::select('owner',$choices,$selected);
    }
    private static function detail($id) {
        try {$d=AAIHB_Value::case_data($id);$s=self::state($id);}catch(Throwable $e){echo '<p>案件が見つかりません。</p>';return;}
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=aaihb-ops')).'">← 案件一覧</a></p><h2>#'.$id.' '.esc_html($d['name']).' / '.esc_html(AAIHB_Value::guide($d['check'])[0]).'</h2>'.AAIHB_Value::guide_html($d['check']).'<p>観測日時：'.esc_html($d['last']).' ／ '.($d['signal_open']?'異常が継続':'正常判定または手動作成').'</p>';
        if (current_user_can('manage_options')) { echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'aaihb-value','timer_case'=>$id],admin_url('admin.php'))).'#value-work">この案件の作業時間を計測・記録する →</a></p>'; }
        self::form('change');echo '<input type="hidden" name="case" value="'.$id.'"><input type="hidden" name="version" value="'.(int)$s['version'].'"><p>対応状況 ';self::select('status',self::statuses(),$s['status']);echo '</p><p>担当者 ';self::owners($s['owner']);echo '</p><p><label>対応期限（UTC） <input type="date" name="due" value="'.esc_attr($s['due']).'"></label></p><p><label>対応内容・変更理由（必須。秘密情報を含めない）<br><textarea required name="note" rows="4" class="large-text"></textarea></label></p>';submit_button('担当・状況・期限を保存');echo '</form><p>対応済みは技術的な対応状況です。作業時間の比較・月次効果は別に記録します。異常の正常化だけで短縮成果を加点しません。</p><h2>引き継ぎ履歴（最新20件）</h2>';
        foreach(array_reverse(array_slice($s['history'],-20)) as $event) {echo '<div style="background:#fff;padding:12px;margin:8px 0;border-left:4px solid #2271b1"><strong>'.esc_html($event['at']).' / user #'.(int)$event['actor'].'</strong><p>'.esc_html(self::statuses()[$event['from'][0]].' → '.self::statuses()[$event['to'][0]].' / 担当 '.$event['to'][1].' / 期限 '.$event['to'][2]).'</p><p>'.nl2br(esc_html($event['note'])).'</p></div>';}
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aaihb_ops_csv"><input type="hidden" name="case" value="'.$id.'">';wp_nonce_field('aaihb_ops_csv');submit_button('この案件の全引き継ぎ履歴をCSV出力','secondary');echo '</form>';
        AAIHB_Repair::panel($id);
    }
    public static function csv() {
        self::allowed();check_admin_referer('aaihb_ops_csv');$id=absint(AAIHB_Beta::input('case'));
        try{AAIHB_Value::case_data($id);}catch(Throwable $e){wp_die('案件が見つかりません。');}
        $s=self::state($id);nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="autorepair-case-'.$id.'.csv"');$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['case','at','actor','previous_status','previous_owner','previous_due','status','owner','due','note']);
        foreach($s['history'] as $e){fputcsv($f,array_map(['AAIHB_Value','csv_cell'],array_merge([$id,$e['at'],$e['actor']],$e['from'],$e['to'],[$e['note']])));}fclose($f);exit;
    }
}
