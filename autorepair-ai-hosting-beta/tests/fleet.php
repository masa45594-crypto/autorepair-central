<?php
if(!defined('AAIHB_TESTING') || !AAIHB_TESTING || !defined('ABSPATH'))exit;
wp_set_current_user(1); $_GET=[];
AAIHB_Beta::site_put('a',$site);AAIHB_Beta::site_put('b',$site);
AAIHB_Fleet::assign(['a'],'A社','pause');
verify(AAIHB_Fleet::paused('a') && AAIHB_Fleet::info('a')['group']==='A社','group and pause saved');
verify(is_wp_error(AAIHB_Beta::scan_site('a')),'paused site rejects manual scan');
reject(function(){AAIHB_Fleet::assign(['b','missing'],'Bad','keep');},'invalid selection rejected atomically');
verify(AAIHB_Fleet::info('b')['group']==='未分類','invalid selection leaves valid site unchanged');
wp_set_current_user($operator);
reject(function(){AAIHB_Fleet::enqueue('');},'operator cannot enqueue');
reject(function(){AAIHB_Fleet::assign(['a'],'B','resume');},'operator cannot change group');
wp_set_current_user($subscriber);reject(function(){AAIHB_Fleet::history_page();},'subscriber cannot read history');
wp_set_current_user(1);
$q=AAIHB_Fleet::enqueue('');verify(array_keys($q['jobs'])===['b'],'queue excludes paused sites');
reject(function(){AAIHB_Fleet::enqueue('');},'duplicate active queue blocked');
$before=$calls;AAIHB_Value::tick();verify($calls===$before,'daily scan yields to active queue');
AAIHB_Fleet::worker();$q=get_option(AAIHB_Fleet::QUEUE);
verify($q['jobs']['b']['state']==='done' && $calls===$before+1,'worker completes one real mocked diagnostic');
$history=AAIHB_Fleet::history_query('未分類','b');
verify($history->found_posts===1,'diagnostic observation stored with group');
verify(strpos($history->posts[0]->post_content,$site['token'])===false && !get_post_type_object(AAIHB_Fleet::HISTORY)->show_in_rest,'history excludes token and public REST');
AAIHB_Fleet::assign(['b'],'New group','keep');verify(AAIHB_Fleet::history_query('未分類','b')->found_posts===1,'history retains group at observation time');
$lease=AAIHB_Fleet::acquire('test_fleet_lock');verify($lease && !AAIHB_Fleet::acquire('test_fleet_lock'),'live lease blocks competing worker');
verify(!AAIHB_Fleet::release('test_fleet_lock',['token'=>'wrong','until'=>0]),'non-owner cannot release lease');
AAIHB_Fleet::release('test_fleet_lock',$lease);add_option('test_fleet_lock',['token'=>'expired','until'=>time()-10],'',false);
$lease=AAIHB_Fleet::acquire('test_fleet_lock');verify((bool)$lease,'expired lease recovered');AAIHB_Fleet::release('test_fleet_lock',$lease);
AAIHB_Fleet::enqueue('');$registry=AAIHB_Beta::site('b');$registry['token']=AAIHB_Beta::crypt(str_repeat('b',64));AAIHB_Beta::site_put('b',$registry);
AAIHB_Fleet::worker();verify(get_option(AAIHB_Fleet::QUEUE)['jobs']['b']['state']==='skipped','changed credential skipped');
$failure=function(){return new WP_Error('timeout','Test timeout');};add_filter('pre_http_request',$failure,99);
AAIHB_Fleet::enqueue('');
for($i=1;$i<=3;$i++){AAIHB_Fleet::worker();$q=get_option(AAIHB_Fleet::QUEUE);verify($q['jobs']['b']['attempts']===$i && $q['jobs']['b']['state']===($i===3?'failed':'queued'),'bounded retry attempt '.$i);$q['jobs']['b']['due']=time()-1;update_option(AAIHB_Fleet::QUEUE,$q);}
remove_filter('pre_http_request',$failure,99);
AAIHB_Fleet::assign(['a'],'A社','resume');AAIHB_Fleet::enqueue('');AAIHB_Fleet::cancel();
verify(!AAIHB_Fleet::active() && get_option(AAIHB_Fleet::QUEUE)['jobs']['a']['state']==='cancelled','cancel stops pending jobs');
AAIHB_Fleet::enqueue('');$q=get_option(AAIHB_Fleet::QUEUE);$q['jobs']['a']['state']='running';$q['jobs']['a']['attempts']=1;update_option(AAIHB_Fleet::QUEUE,$q);
AAIHB_Fleet::worker();verify(get_option(AAIHB_Fleet::QUEUE)['jobs']['a']['state']==='done','interrupted running job reclaimed');
AAIHB_Fleet::deactivate();verify(!wp_next_scheduled('aaihb_fleet_tick') && !AAIHB_Fleet::active(),'deactivation unschedules and cancels');
// Render real PHP output for browser checks, using disposable fixtures only.
foreach(['ops'=>['AAIHB_Operations','page'],'value'=>['AAIHB_Value','page'],'connect'=>['AAIHB_Beta','page'],'groups'=>['AAIHB_Fleet','page'],'history'=>['AAIHB_Fleet','history_page']] as $name=>$fn){$_GET=[];ob_start();call_user_func($fn);file_put_contents('/qa/fleet-'.$name.'.html',ob_get_clean());}
echo $n." total integration checks passed\n";
