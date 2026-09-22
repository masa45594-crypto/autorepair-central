<?php
// Runs ONLY inside the disposable PHP container, before WordPress boots.
// Production wp-config.php is never included.
if(PHP_SAPI!=='cli'||!getenv('TEST_NONCE')||!is_file('/input/manifest.json'))exit;
define('ABSPATH','/site/');define('ARRAY_N','N');define('ARRAY_A','A');
require '/input/vault-db.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
final class RehearsalDB {
 public $prefix;public $last_error='';private $db;
 public function __construct($prefix){$this->prefix=$prefix;$this->db=new mysqli('localhost','rehearsal',getenv('TEST_DB_PASSWORD'),'rehearsal',0,'/socket/mysqld.sock');$this->db->set_charset('utf8mb4');
  // Match WordPress wpdb compatibility before importing WordPress schemas.
  $modes=explode(',',$this->db->query('SELECT @@SESSION.sql_mode')->fetch_row()[0]);
  $modes=array_diff($modes,['NO_ZERO_DATE','ONLY_FULL_GROUP_BY','STRICT_TRANS_TABLES','STRICT_ALL_TABLES','TRADITIONAL','ANSI']);
  $this->db->query("SET SESSION sql_mode='".$this->db->real_escape_string(implode(',',$modes))."'");}
 public function query($sql){$this->db->query($sql);return $this->db->affected_rows;}
 public function get_var($sql){$r=$this->db->query($sql);return $r->fetch_row()[0];}
 public function prepare($format,$v){return str_replace('%s',"'".$this->db->real_escape_string($v)."'",$format);}
 public function get_results($sql,$mode){$r=$this->db->query($sql);return $r->fetch_all($mode===ARRAY_N?MYSQLI_NUM:MYSQLI_ASSOC);}
}
function ident($name){if(!is_string($name)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name))throw new RuntimeException('identifier');return '`'.$name.'`';}
function rowhash($row){return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
try{
 $m=json_decode(file_get_contents('/input/manifest.json'),true,512,JSON_THROW_ON_ERROR);ident($m['prefix']);$db=new RehearsalDB($m['prefix']);$adapter=new AAIHB_VaultDB($db);
 // Exercise the SAME importer as full-site restore, including session mode restoration.
 $mapping=$adapter->stage('/input/database.jsonl',bin2hex(random_bytes(5)),$m['tables']);
 $moves=[];foreach($mapping as $table=>$stage)$moves[]=ident($stage).' TO '.ident($table);$db->query('RENAME TABLE '.implode(', ',$moves));
 // Verify all imported table contents, not merely SELECT 1 or a row count.
 $file=fopen('/input/database.jsonl','rb');$table=null;$columns=[];$expected=[];$verified=[];
 while(($line=fgets($file,16777217))!==false){$v=json_decode($line,true,512,JSON_THROW_ON_ERROR);
  if(isset($v['table'])){$table=$v['table'];$columns=$v['columns'];$expected=[];}
  elseif(array_key_exists('row',$v))$expected[]=rowhash($v['row']);
  elseif(isset($v['end'])){
   if($v['end']!==count($expected)||$v['end']!==$m['counts'][$table])throw new RuntimeException('count mismatch');
   $actual=[];$offset=0;
   do{$rows=$db->get_results('SELECT '.implode(',',array_map('ident',$columns)).' FROM '.ident($table).' LIMIT 200 OFFSET '.$offset,ARRAY_N);foreach($rows as $row)$actual[]=rowhash(array_map(function($x){return $x===null?null:base64_encode((string)$x);},$row));$offset+=count($rows);}while(count($rows)===200);
   sort($expected);sort($actual);if($expected!==$actual)throw new RuntimeException('content mismatch');$verified[]=$table;
  }
 }fclose($file);if($verified!==$m['tables'])throw new RuntimeException('table mismatch');
 // Adapt ONLY the clone URL after exact DB comparison. No production endpoints are contacted.
 $options=ident($m['prefix'].'options');
 $db->query("UPDATE $options SET option_value='http://127.0.0.1:8080' WHERE option_name IN ('home','siteurl')");
 echo json_encode(['database'=>true]);
}catch(Throwable $e){fwrite(STDERR,"Restore rehearsal database verification failed.\n");exit(1);}
