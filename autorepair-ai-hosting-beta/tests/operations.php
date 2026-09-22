<?php
// Disposable WordPress only. Run after tests/wordpress.php on the same fresh instance.
if (!defined('AAIHB_TESTING') || !AAIHB_TESTING || !defined('ABSPATH')) {exit;}
wp_set_current_user(1); AAIHB_Operations::roles();
$operator=wp_insert_user(['user_login'=>'ops_fixture','user_pass'=>wp_generate_password(32),'role'=>'aaihb_operator']);
$subscriber=wp_insert_user(['user_login'=>'reader_fixture','user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
verify(!is_wp_error($operator) && !is_wp_error($subscriber),'test users created');
verify(user_can($operator,'aaihb_operate') && !user_can($operator,'manage_options'),'operator capability excludes connection management');
mutation('routine',['title'=>'Migration fixture']);$list=all_cases();$id=end($list)->ID;
delete_post_meta($id,'aaihb_ops_status');
$old=AAIHB_Value::case_data($id); AAIHB_Operations::migrate();
verify(get_post_meta($id,'aaihb_ops_status',true)==='open','old cases indexed on upgrade');
verify(AAIHB_Value::case_data($id)===$old,'migration preserves diagnostic data');
$before=count(AAIHB_Value::entries());
wp_set_current_user($operator);
$s=AAIHB_Operations::change($id,0,'working',$operator,'2026-01-01','Investigating fixture');
verify($s['version']===1 && $s['owner']===$operator,'operator can assign case and set status');
verify(count($s['history'])===1 && $s['history'][0]['actor']===$operator,'status and actor history stored together');
reject(function()use($id,$operator){AAIHB_Operations::change($id,0,'done',$operator,'','stale screen');},'stale concurrent edit rejected');
reject(function()use($id,$subscriber){AAIHB_Operations::change($id,1,'working',$subscriber,'','invalid owner');},'subscriber cannot be assigned as operator');
reject(function()use($id,$operator){AAIHB_Operations::change($id,1,'working',$operator,'2026-02-30','invalid date');},'impossible deadline rejected');
reject(function()use($id,$operator){AAIHB_Operations::change($id,1,'working',$operator,'','');},'empty handover reason rejected');
verify(AAIHB_Operations::state($id)['version']===1,'failed updates leave version unchanged');
$q=AAIHB_Operations::query(['owner'=>(string)$operator,'status'=>'working','overdue'=>'1']);
verify($q->found_posts===1,'assigned overdue filter finds case');
AAIHB_Operations::change($id,1,'done',$operator,'2026-01-01','Routine complete');
verify(AAIHB_Operations::query(['owner'=>(string)$operator,'overdue'=>'1'])->found_posts===0,'completed case excluded from overdue filter');
verify(count(AAIHB_Value::entries())===$before,'operational completion does not fabricate time savings');
$all=all_cases(); $abnormal=0;foreach($all as $p){$d=AAIHB_Value::case_data($p->ID);if($d['signal_open']){$abnormal=$p->ID;break;}}
reject(function()use($abnormal,$operator){AAIHB_Operations::change($abnormal,0,'done',$operator,'','still failing');},'ongoing failure cannot be marked done');
AAIHB_Operations::change($abnormal,0,'waiting',$operator,'','Possible false positive, investigate');
verify(AAIHB_Operations::state($abnormal)['status']==='waiting','suspected false positive can be held with reason');
$_GET=['case'=>(string)$id];ob_start();AAIHB_Operations::page();$html=ob_get_clean();
verify(strpos($html,'引き継ぎ履歴')!==false,'operator detail renders history');
verify(strpos($html,'接続設定・手動診断')===false,'operator UI does not offer connection settings');
wp_set_current_user($subscriber);reject(function(){AAIHB_Operations::page();},'subscriber denied team dashboard');
wp_set_current_user(0);verify(!AAIHB_Operations::api_allowed(),'anonymous API access rejected');
wp_set_current_user($operator);$_SERVER['HTTPS']='off';verify(!AAIHB_Operations::api_allowed(),'HTTP API access rejected');
$_SERVER['HTTPS']='on';verify(AAIHB_Operations::api_allowed(),'HTTPS authenticated operator API allowed');
$request=new WP_REST_Request('GET','/aaihb/v1/operations/cases');$request->set_param('owner',(string)$operator);
$api=AAIHB_Operations::api_list($request);$json=wp_json_encode($api->get_data());
verify(strpos($json,'token')===false && strpos($json,'Investigating fixture')===false,'API omits tokens and private handover notes');
$request->set_param('page',[]);verify(is_wp_error(AAIHB_Operations::api_list($request)),'invalid API page rejected');
wp_set_current_user(1);
for($i=0;$i<31;$i++){mutation('routine',['title'=>'Pagination fixture '.$i]);}
$q=AAIHB_Operations::query(['search'=>'Pagination fixture'],1);$q2=AAIHB_Operations::query(['search'=>'Pagination fixture'],2);
verify($q->found_posts===31 && count($q->posts)===25 && count($q2->posts)===6,'case search paginates 25 rows');
verify(!array_intersect(wp_list_pluck($q->posts,'ID'),wp_list_pluck($q2->posts,'ID')),'pagination has no duplicate rows');
echo $n." total integration checks passed\n";
