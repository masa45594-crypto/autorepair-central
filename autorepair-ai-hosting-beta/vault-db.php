<?php
if(!defined('ABSPATH'))exit;
/** MySQL/MariaDB adapter: prefix-owned BASE TABLES, no views/triggers/FKs. */
final class AAIHB_VaultDB {
 private $db;
 public function __construct($db){$this->db=$db;}
 public static function supported_server($version){
  if(!is_string($version)||$version==='')return false;
  if(stripos($version,'mariadb')!==false)return (bool)preg_match('/(?:^|[ -])10\.(?:[5-9]|[1-9][0-9])\./i',$version);
  return (bool)preg_match('/^8\./',$version);
 }
 private function ident($s){if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$s))throw new RuntimeException('非対応のテーブル・列名です。');return '`'.$s.'`';}
 private function query($sql){$r=$this->db->query($sql);if($r===false)throw new RuntimeException('DB操作が失敗しました。DB権限・形式を確認してください。');return $r;}
 public function tables(){
  $rows=$this->db->get_results('SHOW FULL TABLES',ARRAY_N);if(!is_array($rows)||!$rows)throw new RuntimeException('MySQL/MariaDBのテーブル一覧を取得できません。');$out=[];$prefix=$this->db->prefix;if($prefix===''||strpos('ahb_stage_',$prefix)===0||strpos('ahb_hold_',$prefix)===0)throw new RuntimeException('作業テーブルと衝突する接頭辞は対象外です。');
  foreach($rows as $r)if(strpos($r[0],$prefix)===0){$this->ident($r[0]);if(strtoupper($r[1])!=='BASE TABLE')throw new RuntimeException('ビューを含む構成は対象外です。');$out[]=$r[0];}
  if(!$out)throw new RuntimeException('対象テーブルがありません。');sort($out);return $out;
 }
 public function dump($path,$during){
  $version=$this->db->get_var('SELECT VERSION()');if(!self::supported_server($version))throw new RuntimeException('全体バックアップはMySQL 8.xまたはMariaDB 10.5以上に限定しています。');$tables=$this->tables();$triggers=$this->db->get_results('SHOW TRIGGERS',ARRAY_A);if(!is_array($triggers))throw new RuntimeException('トリガーを確認できません。');foreach($triggers as $t)if(in_array($t['Table']??'',$tables,true))throw new RuntimeException('トリガー付きのDBは対象外です。');
  $schemas=[];foreach($tables as $t){$r=$this->db->get_row('SHOW CREATE TABLE '.$this->ident($t),ARRAY_N);if(!$r||empty($r[1])||!preg_match('/ENGINE=InnoDB\b/i',$r[1])||preg_match('/(?:DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE)/i',$r[1])||stripos($r[1],'FOREIGN KEY')!==false)throw new RuntimeException('外部キーを含む構成・不明なスキーマは対象外です。');$schemas[$t]=$r[1];}
  $f=fopen($path,'x');if(!$f)throw new RuntimeException('DB保存先を作成できません。');chmod($path,0600);$total=0;$counts=[];
  $emit=function($v)use($f,&$total){$j=json_encode($v,JSON_THROW_ON_ERROR)."\n";$total+=strlen($j);if(strlen($j)>16777216||$total>2147483648||fwrite($f,$j)!==strlen($j))throw new RuntimeException('DB保存のサイズ・空き容量を確認してください。');};
  try{$this->query('LOCK TABLES '.implode(', ',array_map(function($t){return $this->ident($t).' READ';},$tables)));}catch(Throwable $e){fclose($f);throw $e;}
  try{foreach($tables as $t){
    $cols=$this->db->get_results('SHOW FULL COLUMNS FROM '.$this->ident($t),ARRAY_A);if(!is_array($cols))throw new RuntimeException('列定義取得に失敗');$names=[];foreach($cols as $c)if(!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$c['Extra']??''))$names[]=$c['Field'];foreach($names as $name)$this->ident($name);
    $emit(['table'=>$t,'schema'=>$schemas[$t],'columns'=>$names]);$offset=0;
    do{$rows=$this->db->get_results('SELECT '.implode(',',array_map([$this,'ident'],$names)).' FROM '.$this->ident($t).' LIMIT 200 OFFSET '.$offset,ARRAY_N);if(!is_array($rows)||$this->db->last_error)throw new RuntimeException('DB行取得に失敗');foreach($rows as $row)$emit(['row'=>array_map(function($v){return $v===null?null:base64_encode((string)$v);},$row)]);$offset+=count($rows);}while(count($rows)===200);
    $counts[$t]=$offset;$emit(['end'=>$offset]);
   }
   // Same table read locks remain held while copying the filesystem.
   $result=$during();
  }finally{try{$this->query('UNLOCK TABLES');}finally{fclose($f);}}
  return ['tables'=>$tables,'counts'=>$counts,'files'=>$result];
 }
 public function stage($file,$token,$expected){
  $f=fopen($file,'rb');if(!$f)throw new RuntimeException('DB保存を開けません。');$old_mode=$this->db->get_var('SELECT @@SESSION.sql_mode');if(!is_string($old_mode)){fclose($f);throw new RuntimeException('SQLモードを確認できません。');}$this->query($this->db->prepare('SET SESSION sql_mode = %s',trim($old_mode.',NO_AUTO_VALUE_ON_ZERO',',')));$mapping=[];$current=null;$columns=[];$count=0;$index=0;
  try{while(($line=fgets($f,16777217))!==false){if(substr($line,-1)!=="\n")throw new RuntimeException('DB行が上限を超えるか欠落しています。');$v=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    if(isset($v['table'])){
     if($current!==null||!in_array($v['table'],$expected,true)||isset($mapping[$v['table']]))throw new RuntimeException('DBテーブル一覧が不正です。');$current=$v['table'];$this->ident($current);$stage='ahb_stage_'.$token.'_'.($index++);$this->ident($stage);
     if(!is_string($v['schema'])||strpos($v['schema'],'CREATE TABLE '.$this->ident($current).' ')!==0||stripos($v['schema'],'FOREIGN KEY')!==false)throw new RuntimeException('保存スキーマが非対応です。');
     $sql='CREATE TABLE '.$this->ident($stage).substr($v['schema'],strlen('CREATE TABLE '.$this->ident($current)));$this->query($sql);$mapping[$current]=$stage;$columns=$v['columns'];foreach($columns as $c)$this->ident($c);$count=0;
    }elseif(array_key_exists('row',$v)){
     if(!$current||!is_array($v['row'])||count($v['row'])!==count($columns))throw new RuntimeException('DB行形式が不正です。');$values=[];foreach($v['row'] as $value){if($value===null)$values[]='NULL';else{$decoded=base64_decode($value,true);if($decoded===false)throw new RuntimeException('DB値が破損しています。');$values[]="UNHEX('".bin2hex($decoded)."')";}}
     $this->query('INSERT INTO '.$this->ident($mapping[$current]).' ('.implode(',',array_map([$this,'ident'],$columns)).') VALUES ('.implode(',',$values).')');$count++;
    }elseif(isset($v['end'])){if(!$current||$v['end']!==$count)throw new RuntimeException('DB行数が一致しません。');$current=null;}
    else throw new RuntimeException('不正なDB保存形式です。');
   }if($current!==null||array_keys($mapping)!==$expected)throw new RuntimeException('DB保存が不完全です。');
  }finally{fclose($f);$this->query($this->db->prepare('SET SESSION sql_mode = %s',$old_mode));}return $mapping;
 }
 public function publish($mapping,$token){
  $existing=$this->tables();$renames=[];$hold=[];$i=0;
  foreach($existing as $t){$h='ahb_hold_'.$token.'_'.($i++);$renames[]=$this->ident($t).' TO '.$this->ident($h);$hold[$t]=$h;}
  foreach($mapping as $t=>$stage)$renames[]=$this->ident($stage).' TO '.$this->ident($t);
  $this->query('RENAME TABLE '.implode(', ',$renames));return $hold;
 }
}
