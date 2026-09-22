<?php
// Standalone filesystem integration with a fake SQL adapter, NOT a MySQL integration test.
if(!defined('AAIHB_TESTING'))exit;
define('ABSPATH','/fixture-public/site/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('AAIHB_VAULT_DIR','/fixture-vault');define('AAIHB_PUBLIC_ROOT','/fixture-public');define('DB_NAME','fixture');define('ARRAY_N','N');define('ARRAY_A','A');
function is_multisite(){return false;}function wp_cache_flush(){return true;}
mkdir(WP_PLUGIN_DIR,0755,true);mkdir(AAIHB_VAULT_DIR,0700);chmod(AAIHB_VAULT_DIR,0700);file_put_contents(ABSPATH.'wp-config.php','<?php // fixture');file_put_contents(ABSPATH.'index.php','version-one');
foreach(['vault-io','vault-db','vault-full'] as $f)require '/wordpress/wp-content/plugins/autorepair-ai-hosting-beta/'.$f.'.php';
class FixtureDB {
 public $prefix='wp_',$last_error='',$tables=['wp_options'=>[['0',"\0\xff",null]]],$queries=[],$mode='STRICT_TRANS_TABLES',$fail=false;
 public function get_results($q,$format){
  if($q==='SHOW FULL TABLES')return array_map(function($t){return [$t,'BASE TABLE'];},array_keys($this->tables));
  if($q==='SHOW TRIGGERS')return [];
  if(strpos($q,'SHOW FULL COLUMNS')===0)return [['Field'=>'id','Extra'=>''],['Field'=>'data','Extra'=>''],['Field'=>'empty','Extra'=>'']];
  if(preg_match('/FROM `([^`]+)` LIMIT 200 OFFSET (\d+)/',$q,$m))return array_slice($this->tables[$m[1]],(int)$m[2],200);
  throw new RuntimeException('Unexpected read '.$q);
 }
 public function get_row($q,$fmt){preg_match('/`([^`]+)`/',$q,$m);return [$m[1],'CREATE TABLE `'.$m[1].'` (`id` int, `data` blob, `empty` text) ENGINE=InnoDB'];}
 public function get_var($q){return $q==='SELECT VERSION()'?'8.0.36':$this->mode;}
 public function prepare($q,$value){return str_replace('%s',"'".base64_encode($value)."'",$q);}
 public function query($q){
  $this->queries[]=$q;
  if(strpos($q,'SET SESSION')===0){preg_match("/ = '(.*)'/",$q,$m);$this->mode=base64_decode($m[1]);return 0;}
  if(preg_match('/^CREATE TABLE `([^`]+)`/',$q,$m)){$this->tables[$m[1]]=[];return 0;}
  if(preg_match('/^INSERT INTO `([^`]+)` .* VALUES \((.*)\)$/',$q,$m)){$values=array_map(function($v){return $v==='NULL'?null:hex2bin(substr($v,7,-2));},explode(',',$m[2]));$this->tables[$m[1]][]=$values;return 1;}
  if(strpos($q,'RENAME TABLE ')===0){if($this->fail)return false;preg_match_all('/`([^`]+)` TO `([^`]+)`/',$q,$m,PREG_SET_ORDER);foreach($m as $r){$this->tables[$r[2]]=$this->tables[$r[1]];unset($this->tables[$r[1]]);}return 0;}
  if(strpos($q,'LOCK TABLES')===0||$q==='UNLOCK TABLES')return 0;
  throw new RuntimeException('Unexpected query '.$q);
 }
}
$n=0;function fcheck($v,$s){global $n;if(!$v)throw new RuntimeException('FAIL '.$s);$n++;echo "PASS $s\n";}
$wpdb=new FixtureDB();$id=AAIHB_FullSite::backup();[$dir,$m]=AAIHB_VaultIO::point($id);fcheck($m['kind']==='full','full manifest includes files and DB');fcheck(!file_exists(ABSPATH.'.maintenance'),'maintenance released after backup');fcheck(!isset($m['files']['.maintenance']),'temporary marker excluded');
file_put_contents(ABSPATH.'index.php','version-two');$wpdb->tables['wp_options']=[['2','new','text']];$r=AAIHB_FullSite::restore($id);
fcheck(file_get_contents(ABSPATH.'index.php')==='version-one','site directory restored');fcheck($wpdb->tables['wp_options']===[['0',"\0\xff",null]],'fake SQL adapter preserves binary null zero values');fcheck($wpdb->mode==='STRICT_TRANS_TABLES','session SQL mode restored');fcheck(!file_exists(ABSPATH.'.maintenance'),'maintenance released after restore');[$safety,$sm]=AAIHB_VaultIO::point($r['safety']);fcheck($sm['kind']==='full','pre-restore full snapshot retained');fcheck(file_get_contents(AAIHB_VAULT_DIR.'/'.$r['journal'].'/previous-site/index.php')==='version-two','previous site tree retained');fcheck(count(array_filter(array_keys($wpdb->tables),function($v){return strpos($v,'ahb_hold_')===0;}))===1,'previous DB tables retained');
file_put_contents(ABSPATH.'index.php','version-three');$wpdb->fail=true;try{AAIHB_FullSite::restore($id);fcheck(false,'SQL failure should throw');}catch(RuntimeException $e){fcheck(file_get_contents(ABSPATH.'index.php')==='version-three','SQL publish failure restores current filesystem');}fcheck(!file_exists(ABSPATH.'.maintenance'),'failure releases owned marker');
echo "TOTAL $n fixture checks passed; real MySQL not exercised\n";
