<?php
// Runs in clone. Exercises the actual Contact Form 7 REST submission and mail interception.
if(PHP_SAPI!=='cli'||!getenv('TEST_NONCE'))exit(1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','rehearsal',getenv('TEST_DB_PASSWORD'),'rehearsal',0,'/socket/mysqld.sock');
$row=$db->query("SELECT ID FROM wp_posts WHERE post_type='wpcf7_contact_form' AND post_status='publish' LIMIT 1")->fetch_row();
if(!$row){echo json_encode(['contact_form_7_http_and_mail'=>false,'reason'=>'no_fixture_form']);exit(0);}
$form_id=(int)$row[0];
$marker='aaihb-cf7-'.bin2hex(random_bytes(12));
$fields=['_wpcf7'=>$form_id,'_wpcf7_version'=>'5.9','_wpcf7_locale'=>'en_US','_wpcf7_unit_tag'=>'wpcf7-f'.$form_id.'-p0-o1','_wpcf7_container_post'=>0,'your-name'=>'Rehearsal','your-email'=>'fixture@example.invalid','your-subject'=>$marker,'your-message'=>$marker];
// The REST feedback endpoint only accepts multipart/form-data (the same as the real browser widget).
$boundary='AaihbBoundary'.bin2hex(random_bytes(12));
$body='';
foreach($fields as $name=>$value){$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";}
$body.="--$boundary--\r\n";
$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=$boundary\r\nReferer: http://127.0.0.1:8080/\r\n",'content'=>$body,'timeout'=>30,'follow_location'=>0,'ignore_errors'=>true]]);
$response=file_get_contents('http://127.0.0.1:8080/?rest_route=/contact-form-7/v1/contact-forms/'.$form_id.'/feedback',false,$ctx);
$headers=$http_response_header??[];
$status=0;$match=[];
if(isset($headers[0])&&preg_match('~^HTTP/\S+ ([0-9][0-9][0-9])(?: |$)~',$headers[0],$match)===1)$status=(int)$match[1];
$payload=json_decode((string)$response,true);
$cf7_status=is_array($payload)?($payload['status']??null):null;
$stmt=$db->prepare("SELECT option_value FROM wp_options WHERE option_name='aaihb_cf7_mail_marker'");$stmt->execute();$row2=$stmt->get_result()->fetch_row();
$mail_marker=$row2?$row2[0]:null;
$passed=$status===200&&$cf7_status==='mail_sent'&&$mail_marker===$marker;
$out=['contact_form_7_http_and_mail'=>$passed,'http_status'=>$status,'cf7_status'=>$cf7_status,'mail_marker_matched'=>$mail_marker===$marker];
if(!$passed)$out['debug_response_snippet']=substr((string)$response,0,400);
echo json_encode($out);
exit(0);
