<?php
// Runs in clone. Exercises the actual WordPress comment form handler and DB write.
if(PHP_SAPI!=='cli'||!getenv('TEST_NONCE'))exit(1);
$marker='aaihb-form-'.bin2hex(random_bytes(12));
$body=http_build_query(['author'=>'Rehearsal','email'=>'fixture@example.invalid','comment'=>$marker,'comment_post_ID'=>1,'comment_parent'=>0]);
$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>30,'follow_location'=>0,'ignore_errors'=>true]]);
$response=file_get_contents('http://127.0.0.1:8080/wp-comments-post.php',false,$ctx);
$headers=$http_response_header??[];
$status=0;$match=[];
if(isset($headers[0])&&preg_match('~^HTTP/\S+ ([0-9][0-9][0-9])(?: |$)~',$headers[0],$match)===1)$status=(int)$match[1];
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','rehearsal',getenv('TEST_DB_PASSWORD'),'rehearsal',0,'/socket/mysqld.sock');
$stmt=$db->prepare('SELECT COUNT(*) FROM wp_comments WHERE comment_content=? AND comment_post_ID=1');$stmt->bind_param('s',$marker);$stmt->execute();$count=$stmt->get_result()->fetch_row()[0];
$passed=in_array($status,[200,302],true)&&(int)$count===1;
echo json_encode(['comment_form_http_and_database'=>$passed,'http_status'=>$status,'database_count'=>(int)$count]);
exit(0);
