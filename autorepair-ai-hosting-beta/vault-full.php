<?php
if(!defined('ABSPATH'))exit;
final class AAIHB_FullSite {
 public static function preflight(){
  if(is_multisite())throw new RuntimeException('単一サイトのみ対応します。');$root=rtrim(ABSPATH,'/');
  if(realpath($root)!==$root||!is_file($root.'/wp-config.php')||strpos(realpath(WP_CONTENT_DIR)?:'',$root.'/')!==0||strpos(realpath(WP_PLUGIN_DIR)?:'',$root.'/')!==0)throw new RuntimeException('標準配置のWordPressが対象です。外部wp-config・外部wp-content・リンク構成は非対応です。');
  if(!is_writable(dirname($root)))throw new RuntimeException('サイトの親ディレクトリへの書き込み権限が必要です。');
  if(stat(dirname($root))['dev']!==stat(AAIHB_VaultIO::root())['dev'])throw new RuntimeException('保管先とサイトは同じファイルシステムに配置してください。');
  return $root;
 }
 private static function maintenance($root){$p=$root.'/.maintenance';$f=@fopen($p,'x');if(!$f)throw new RuntimeException('既存のメンテナンス状態を先に確認してください。');$text='<?php $upgrading = '.time().';';try{if(fwrite($f,$text)!==strlen($text))throw new RuntimeException('メンテナンス設定失敗');}finally{fclose($f);}return $text;}
 private static function release($root,$text){$p=$root.'/.maintenance';if(is_file($p)&&file_get_contents($p)===$text)unlink($p);}
 public static function backup($progress=null){
  $step=function($stage)use($progress){if(is_callable($progress))$progress($stage);};
  global $wpdb;$step('preflight');$root=self::preflight();$step('lock');$lock=AAIHB_VaultIO::lock();$marker=null;
  try{[$id,$dir]=AAIHB_VaultIO::fresh();AAIHB_VaultIO::key();$marker=self::maintenance($root);$db=new AAIHB_VaultDB($wpdb);
   $step('database');$info=$db->dump($dir.'/database.jsonl',function()use($root,$dir,$step){$step('files');return AAIHB_VaultIO::pack($root,$dir.'/files.zip',['.maintenance']);});
   $theme=wp_get_theme();$m=['state'=>'ready','kind'=>'full','id'=>$id,'at'=>gmdate('c'),'root'=>$root,'root_mode'=>fileperms($root)&0777,'prefix'=>$wpdb->prefix,'database'=>DB_NAME,'database_server'=>$wpdb->get_var('SELECT VERSION()'),'files'=>$info['files'],'tables'=>$info['tables'],'counts'=>$info['counts'],'versions'=>['wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'theme'=>$theme&&$theme->exists()?$theme->get('Name').' '.$theme->get('Version'):'' ,'active_plugins'=>count((array)get_option('active_plugins',[]))],'payloads'=>['files.zip'=>hash_file('sha256',$dir.'/files.zip'),'database.jsonl'=>hash_file('sha256',$dir.'/database.jsonl')]];
   $step('finalize');AAIHB_VaultIO::manifest($dir,$m);if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('backup_created',$id,'サイト全体');return $id;
  }finally{if($marker)self::release($root,$marker);AAIHB_VaultIO::unlock($lock);}
 }
 public static function restore($id){
  global $wpdb;$root=self::preflight();[$dir,$m]=AAIHB_VaultIO::point($id);
  if($m['kind']!=='full'||$m['root']!==$root||$m['database']!==DB_NAME||$m['prefix']!==$wpdb->prefix)throw new RuntimeException('同じパス・DB・接頭辞への復元に限定しています。');
  // Recovery point of the current files AND DB must succeed before overwriting anything.
  $safety=self::backup();$lock=AAIHB_VaultIO::lock();$marker=null;$swapped=false;$db_published=false;
  try{
   [$job,$work]=AAIHB_VaultIO::fresh();$stage=$work.'/staged-site';mkdir($stage,0700);AAIHB_VaultIO::verify_zip($dir.'/files.zip',$m['files'],$stage);chmod($stage,$m['root_mode']);
   $token=bin2hex(random_bytes(5));$db=new AAIHB_VaultDB($wpdb);$mapping=$db->stage($dir.'/database.jsonl',$token,$m['tables']);
   $marker=self::maintenance($root);AAIHB_VaultIO::write($stage.'/.maintenance',$marker);$journal=['state'=>'prepared','source'=>$id,'safety'=>$safety,'root'=>$root,'stage'=>$stage,'hold'=>$work.'/previous-site','mapping'=>$mapping,'db_token'=>$token];AAIHB_VaultIO::write($work.'/restore-journal.json',json_encode($journal));
   AAIHB_VaultIO::replace_tree($root,$stage,$work.'/previous-site');$swapped=true;$journal['state']='files_published';AAIHB_VaultIO::write($work.'/restore-journal.json',json_encode($journal));
   $hold=$db->publish($mapping,$token);$db_published=true;$journal['state']='complete';$journal['previous_tables']=$hold;AAIHB_VaultIO::write($work.'/restore-journal.json',json_encode($journal));
   wp_cache_flush();if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('restore_completed',$id,'サイト全体');return ['restored'=>$id,'safety'=>$safety,'journal'=>$job];
  }catch(Throwable $e){
   if($swapped&&!$db_published){if(rename($root,$work.'/failed-site')&&rename($work.'/previous-site',$root))$swapped=false;else throw new RuntimeException('ファイル差し戻しに失敗しました。保管先のrestore-journal.jsonを使って復旧してください。');}
   throw $e;
  }finally{
   // A hard crash may leave a journal/maintenance marker: do not auto-delete unknown state.
   if($marker&&(!$swapped||$db_published))self::release($root,$marker);
   AAIHB_VaultIO::unlock($lock);
  }
 }
}
