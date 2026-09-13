<?php
// Runs in clone. Exercises the plugin's own read-only partner REST API over a
// simulated reverse-proxy HTTPS connection, the way a hosting partner's system
// would reach it in production.
if(PHP_SAPI!=='cli'||!getenv('TEST_NONCE'))exit(1);
$ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"X-AAIHB-Partner-Key: aaihb-ci-fixture-partner-key\r\nX-Forwarded-Proto: https\r\n",'timeout'=>30,'follow_location'=>0,'ignore_errors'=>true]]);
$response=file_get_contents('http://127.0.0.1:8080/?rest_route=/aaihb/v1/partner/sites',false,$ctx);
$headers=$http_response_header??[];
$status=0;$match=[];
if(isset($headers[0])&&preg_match('~^HTTP/\S+ ([0-9][0-9][0-9])(?: |$)~',$headers[0],$match)===1)$status=(int)$match[1];
$json_only=(string)$response;$marker_pos=strpos($json_only,'<!--');if($marker_pos!==false)$json_only=substr($json_only,0,$marker_pos);
$payload=json_decode($json_only,true);
$passed=$status===200&&is_array($payload)&&array_key_exists('items',$payload)&&array_key_exists('total',$payload);
$out=['external_api_reachable'=>$passed,'http_status'=>$status];
if(!$passed)$out['debug_response_snippet']=substr((string)$response,0,300);
echo json_encode($out);
exit(0);
