<?php
// Runs in clone. Exercises the actual WooCommerce add-to-cart flow and session storage.
if(PHP_SAPI!=='cli'||!getenv('TEST_NONCE'))exit(1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','rehearsal',getenv('TEST_DB_PASSWORD'),'rehearsal',0,'/socket/mysqld.sock');
$row=$db->query("SELECT ID FROM wp_posts WHERE post_type='product' AND post_status='publish' LIMIT 1")->fetch_row();
if(!$row){echo json_encode(['woocommerce_add_to_cart'=>false,'reason'=>'no_fixture_product']);exit(0);}
$product_id=(int)$row[0];
$ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'follow_location'=>0,'ignore_errors'=>true]]);
$response=file_get_contents('http://127.0.0.1:8080/?add-to-cart='.$product_id,false,$ctx);
$headers=$http_response_header??[];
$status=0;$match=[];
if(isset($headers[0])&&preg_match('~^HTTP/\S+ ([0-9][0-9][0-9])(?: |$)~',$headers[0],$match)===1)$status=(int)$match[1];
$row2=$db->query("SELECT session_value FROM wp_woocommerce_sessions ORDER BY session_expiry DESC LIMIT 1")->fetch_row();
$session=$row2?$row2[0]:null;
$in_cart=is_string($session)&&strpos($session,(string)$product_id)!==false;
$passed=in_array($status,[200,302],true)&&$in_cart;
$out=['woocommerce_add_to_cart'=>$passed,'http_status'=>$status,'in_cart'=>$in_cart];
if(!$passed)$out['debug_session_snippet']=substr((string)$session,0,300);
echo json_encode($out);
exit(0);
