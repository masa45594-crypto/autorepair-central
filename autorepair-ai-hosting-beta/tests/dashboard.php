<?php
if(!defined('AAIHB_TESTING') || !AAIHB_TESTING || !defined('ABSPATH')){exit;}
wp_set_current_user(1);
$healthy=['score'=>100,'generated_at'=>gmdate('c'),'checks'=>array_map(function($id){return ['id'=>$id,'status'=>'healthy'];},['home','rest_api','database','https','disk','filesystem','cron','maintenance','updates','debug_log'])];
$clean=['name'=>'Corporate Site','endpoint'=>'https://wordpress.org/wp-json/aaihb/v1/scan','token'=>'PRIVATE_TEST_TOKEN','error'=>'','attempt'=>gmdate('c'),'report'=>$healthy];
verify(AAIHB_Dashboard::classification($clean)==='healthy','fresh healthy checks classified healthy');
$stale=$clean;$stale['report']['generated_at']='2020-01-01T00:00:00Z';
verify(AAIHB_Dashboard::classification($stale)==='unknown','old success is not displayed as current healthy');
$failed=$clean;$failed['error']='Unable to connect';verify(AAIHB_Dashboard::classification($failed)==='unknown','connection failure overrides old green report');
$warning=$clean;$warning['report']['score']=85;$warning['report']['checks'][8]['status']='warning';
verify(AAIHB_Dashboard::classification($warning)==='warning','warning card classification');
$critical=$warning;$critical['report']['score']=55;$critical['report']['checks'][0]['status']='critical';
verify(AAIHB_Dashboard::classification($critical)==='critical','critical takes priority over warning');
$critical['name']='Corporate Portal';$warning['name']='Online Store';$stale['name']='Recruit Site';
foreach(['critical'=>$critical,'warning'=>$warning,'healthy'=>$clean,'stale'=>$stale] as $id=>$s){AAIHB_Beta::site_put($id,$s);}
ob_start();AAIHB_Dashboard::page();$html=ob_get_clean();
verify(strpos($html,'全サイトを診断する')!==false && strpos($html,'data-count="critical">1')!==false,'dashboard renders action and real counts');
verify(strpos($html,'PRIVATE_TEST_TOKEN')===false,'dashboard never renders connection token');
file_put_contents('/qa/dashboard-admin.html',$html);
wp_set_current_user($operator);ob_start();AAIHB_Dashboard::page();$operator_html=ob_get_clean();
verify(strpos($operator_html,'id="aaihb-home-scan"')===false && strpos($operator_html,'自分の担当案件を見る')!==false,'operator sees permitted action only');
wp_set_current_user($subscriber);reject(function(){AAIHB_Dashboard::page();},'subscriber cannot open dashboard');
echo $n." total integration checks passed\n";
