<?php
if(!defined('ABSPATH'))exit;
final class AAIHB_RestoreTest {
 const CHECKS=['files','database','homepage','wordpress','cleanup'];
 public static function record($dir,$data){$json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);AAIHB_VaultIO::write($dir.'/restore-test.json',$json);AAIHB_VaultIO::write($dir.'/restore-test.mac',hash_hmac('sha256',$json,AAIHB_VaultIO::key()));}
 public static function result($dir,$manifest){
  if(!is_file($dir.'/restore-test.json'))return ['status'=>'untested'];
  try{$json=file_get_contents($dir.'/restore-test.json');$mac=@file_get_contents($dir.'/restore-test.mac');if(!is_string($mac)||!hash_equals(hash_hmac('sha256',$json,AAIHB_VaultIO::key()),$mac))return ['status'=>'invalid'];$r=json_decode($json,true,512,JSON_THROW_ON_ERROR);
   if(($r['source']??'')!==hash_file('sha256',$dir.'/manifest.json')||($r['id']??'')!==$manifest['id'])return ['status'=>'invalid'];
   if(($r['status']??'')==='passed')foreach(self::CHECKS as $check)if(($r['checks'][$check]??false)!==true)return ['status'=>'invalid'];
   return $r;
  }catch(Throwable $e){return ['status'=>'invalid'];}
 }
 public static function accept($raw,$nonce){
  $r=json_decode($raw,true);if(!is_array($r)||($r['nonce']??'')!==$nonce||!in_array($r['status']??'', ['passed','failed','unavailable'],true))throw new RuntimeException('テスト結果を認証できません。');
  if($r['status']==='passed')foreach(self::CHECKS as $c)if(($r['checks'][$c]??false)!==true)throw new RuntimeException('必須確認が不足しているため合格にできません。');
  // Never persist subprocess output, credentials, page bodies or arbitrary errors.
  return ['status'=>$r['status'],'checks'=>array_intersect_key($r['checks']??[],array_flip(self::CHECKS)),'stage'=>preg_replace('/[^a-z_]/','',substr($r['stage']??'unknown',0,60)),'failure_class'=>preg_replace('/[^a-z_]/','',substr($r['failure_class']??'',0,40))];
 }
 public static function run($id){
  [$dir,$m]=AAIHB_VaultIO::point($id);if($m['kind']!=='full')throw new RuntimeException('サイト全体の保存ポイントを指定してください。');
 $lock=AAIHB_VaultIO::lock();$r=['id'=>$id,'source'=>hash_file('sha256',$dir.'/manifest.json'),'at'=>gmdate('c'),'status'=>'running','checks'=>[],'resources'=>'aaihb-test-'.bin2hex(random_bytes(8))];
  try{self::record($dir,$r);if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('recovery_test_started',$id);if(!function_exists('proc_open'))throw new RuntimeException('proc_openが利用できません。');
   $nonce=bin2hex(random_bytes(24));$command=['python3',__DIR__.'/restore-test/runner.py'];
   $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$dir.'/runner-stderr.log','a']],$pipes);
   if(!is_resource($process))throw new RuntimeException('テスト実行環境を起動できません。');
   $input=json_encode(['dir'=>$dir,'manifest'=>$m,'nonce'=>$nonce,'resources'=>$r['resources']],JSON_THROW_ON_ERROR);$written=fwrite($pipes[0],$input);fclose($pipes[0]);$raw=stream_get_contents($pipes[1],1048576);fclose($pipes[1]);$exit=proc_close($process);
   if($written!==strlen($input))throw new RuntimeException('テスト入力の転送が完了しませんでした。');$result=self::accept($raw,$nonce);if($exit!==0&&$result['status']==='passed')throw new RuntimeException('実行結果が不整合です。');$r=array_merge($r,$result);$r['finished_at']=gmdate('c');self::record($dir,$r);if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event($r['status']==='passed'?'recovery_test_passed':'recovery_test_failed',$id);return $r;
  }catch(Throwable $e){$r['status']='failed';$r['stage']='runner';$r['finished_at']=gmdate('c');self::record($dir,$r);if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('recovery_test_failed',$id);throw $e;}
  finally{AAIHB_VaultIO::unlock($lock);}
 }
 public static function label($r){return ['untested'=>'保存成功／復元テスト未実行','running'=>'保存成功／復元テスト実行中・中断時は未完了','passed'=>'復元テスト合格（隔離環境）','failed'=>'保存成功／復元テスト不合格','unavailable'=>'保存成功／テスト環境未準備','invalid'=>'保存成功／テスト記録を確認できません'][$r['status']??'invalid']??'判定不明';}
 public static function stage_label($stage){return ['preflight'=>'実行前の確認','files'=>'バックアップのファイル照合','isolation'=>'隔離環境の準備','database_start'=>'検証用DBの起動','database'=>'DBの復元・照合','homepage'=>'トップページとWordPress起動の確認','cleanup'=>'検証環境の後片付け','complete'=>'すべての確認完了','runner'=>'テスト実行環境の起動','input'=>'テスト入力の確認'][''.$stage]??'確認段階を特定できません';}
 public static function failure_label($failure){return ['php_parse'=>'復元後のPHP構文エラーの可能性','php_syntax'=>'復元後のPHP構文エラーの可能性','php_fatal'=>'復元後のPHP致命的エラーの可能性','memory'=>'PHPメモリ不足の可能性','connection'=>'検証環境の接続失敗','timeout'=>'検証処理の時間切れ','other'=>'詳細を公開せず管理者が追加調査する必要があります。'][''.$failure]??'詳細を公開せず管理者が追加調査する必要があります。';}
 public static function next_action($failure,$stage){$f=''.$failure;$s=''.$stage;if(in_array($f,['php_parse','php_syntax','php_fatal'],true))return '次にすること：更新直後のプラグイン・テーマを確認し、緊急モードから保存ポイントを選んでください。';if($f==='memory')return '次にすること：PHPメモリ上限とバックアップ容量を確認してください。';if($f==='connection'||in_array($s,['database_start','database'],true))return '次にすること：検証用DBの起動状態と接続設定を確認してください。';if($f==='timeout')return '次にすること：サーバーの実行時間・空き容量を確認して、時間帯を変えて再実行してください。';return '次にすること：保管先の権限・容量・検証環境を確認してください。';}
 public static function checks_html($r){$labels=['files'=>'バックアップのファイル照合','database'=>'DBの復元・照合','homepage'=>'トップページ HTTP 200 と HTML','wordpress'=>'WordPress起動の証明','cleanup'=>'検証環境の後片付け'];$html='<ul class="aaihb-restore-checks">';foreach($labels as $id=>$label){$ok=($r['checks'][$id]??false)===true;$html.='<li class="'.($ok?'is-ok':'is-pending').'">'.($ok?'確認済み':'未確認').'：'.esc_html($label).'</li>';}$html.='</ul>';return $html;}
}
