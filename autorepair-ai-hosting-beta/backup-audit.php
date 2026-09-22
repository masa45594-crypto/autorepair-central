<?php
if(!defined('ABSPATH'))exit;
/** On-demand integrity audit for signed backup points. */
final class AAIHB_BackupAudit {
 const LAST='aaihb_backup_audit';
 public static function run(){
  $root=AAIHB_VaultIO::root();$dirs=glob($root.'/[0-9]*',GLOB_ONLYDIR)?:[];rsort($dirs);$result=['at'=>gmdate('c'),'checked'=>0,'valid'=>0,'invalid'=>[]];
  foreach(array_slice($dirs,0,100) as $dir){$id=basename($dir);$result['checked']++;try{AAIHB_VaultIO::point($id);$result['valid']++;}catch(Throwable $e){$result['invalid'][]=$id;}}
  update_option(self::LAST,$result,false);if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('backup_audit','','確認 '.$result['valid'].'/'.$result['checked']);return $result;
 }
 public static function last(){ $r=get_option(self::LAST,[]);return is_array($r)?$r:[]; }
}
