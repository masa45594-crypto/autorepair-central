<?php
/** Filesystem primitives shared by the admin plugin and the offline WP-CLI recovery command. */
if(!defined('ABSPATH'))exit;
final class AAIHB_VaultIO {
 private static $held=false;
 public static function root(){
  if(!defined('AAIHB_VAULT_DIR'))throw new RuntimeException('wp-config.phpで公開領域外のAAIHB_VAULT_DIRを設定してください。');
  $v=rtrim(AAIHB_VAULT_DIR,'/');$real=realpath($v);$site=realpath(ABSPATH);$doc=defined('AAIHB_PUBLIC_ROOT')?realpath(AAIHB_PUBLIC_ROOT):false;if(!$doc)throw new RuntimeException('AAIHB_PUBLIC_ROOTにサーバーの公開ルートを指定してください。');
  if(!$real||$real!==$v||!is_dir($v)||!is_writable($v))throw new RuntimeException('保管先は既存の書き込み可能な実ディレクトリで指定してください。');
  foreach(array_filter([$site,$doc]) as $p)if($v===$p||strpos($v,$p.'/')===0)throw new RuntimeException('保管先は公開ディレクトリの外にしてください。');
  if((fileperms($v)&0077)!==0)throw new RuntimeException('保管ディレクトリの権限を0700にしてください。');
  if(!class_exists('ZipArchive'))throw new RuntimeException('PHP ZipArchive拡張が必要です。');
  return $v;
 }
 public static function key(){
  $p=self::root().'/vault.key';if(is_link($p))throw new RuntimeException('不正な鍵ファイルです。');
  if(!file_exists($p)){$f=@fopen($p,'x');if(!$f)throw new RuntimeException('鍵を作成できません。');chmod($p,0600);try{if(fwrite($f,random_bytes(32))!==32)throw new RuntimeException('鍵の保存に失敗しました。');}finally{fclose($f);}}
  $s=file_get_contents($p);if(strlen($s)!==32)throw new RuntimeException('保管鍵が不正です。');return $s;
 }
 public static function lock(){ if(self::$held)throw new RuntimeException('同じ処理内で保管操作が重複しています。');$p=self::root().'/operation.lock';$f=fopen($p,'c');if($f)chmod($p,0600);if(!$f||!flock($f,LOCK_EX|LOCK_NB)){if($f)fclose($f);throw new RuntimeException('別の保管・復元処理が実行中です。');}self::$held=true;return $f; }
 public static function unlock($f){self::$held=false;flock($f,LOCK_UN);fclose($f);}
 public static function fresh(){ $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));$p=self::root().'/'.$id;if(!mkdir($p,0700))throw new RuntimeException('保存先を作成できません。');return [$id,$p]; }
 public static function write($path,$data){$temp=$path.'.tmp';$f=fopen($temp,'wb');if(!$f)throw new RuntimeException('保存できません。');chmod($temp,0600);try{if(fwrite($f,$data)!==strlen($data))throw new RuntimeException('保存容量を確認してください。');fflush($f);}finally{fclose($f);}if(!rename($temp,$path))throw new RuntimeException('保存の確定に失敗しました。');}
 public static function manifest($dir,$data){$json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);self::write($dir.'/manifest.json',$json);self::write($dir.'/manifest.mac',hash_hmac('sha256',$json,self::key()));}
 public static function point($id){
  if(!is_string($id)||!preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D',$id))throw new RuntimeException('保存IDが不正です。');$p=self::root().'/'.$id;
  if(is_link($p)||!is_dir($p))throw new RuntimeException('保存ポイントがありません。');
  $j=@file_get_contents($p.'/manifest.json');$mac=@file_get_contents($p.'/manifest.mac');
  if(!is_string($j)||strlen($j)>16777216||!is_string($mac)||!hash_equals(hash_hmac('sha256',$j,self::key()),$mac))throw new RuntimeException('保存ポイントの認証に失敗しました。');
  $d=json_decode($j,true,512,JSON_THROW_ON_ERROR);if(($d['state']??'')!=='ready')throw new RuntimeException('未完成のバックアップです。');
  foreach($d['payloads'] as $f=>$hash)if(!in_array($f,['files.zip','database.jsonl'],true)||!is_file($p.'/'.$f)||is_link($p.'/'.$f)||!hash_equals($hash,hash_file('sha256',$p.'/'.$f)))throw new RuntimeException('バックアップが破損しています。');
  return [$p,$d];
 }
 public static function safe($name){if(!is_string($name)||$name===''||strpos($name,"\0")!==false||strpos($name,'\\')!==false||strpos($name,':')!==false||$name[0]==='/')return false;foreach(explode('/',$name) as $s)if($s===''||$s==='.'||$s==='..')return false;return true;}
 public static function inventory($root,$omit=[]){
  if(is_link($root)||!is_dir($root))throw new RuntimeException('通常のディレクトリだけが対象です。');$out=[];$bytes=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach($it as $f){$name=substr($f->getPathname(),strlen($root)+1);if(in_array($name,$omit,true))continue;
   if(!self::safe($name)||$f->isLink())throw new RuntimeException('シンボリックリンク・特殊パスは対象外です：'.$name);
   if(!$f->isDir()&&!$f->isFile())throw new RuntimeException('特殊ファイルは対象外です：'.$name);
   $info=['dir'=>$f->isDir(),'mode'=>$f->getPerms()&0777];if(!$f->isDir()){if(!$f->isReadable())throw new RuntimeException('読み込めないファイルがあります：'.$name);$info['size']=$f->getSize();$info['sha256']=hash_file('sha256',$f->getPathname());$bytes+=$info['size'];}
   $out[$name]=$info;if(count($out)>50000||$bytes>2147483648)throw new RuntimeException('試作の上限（5万項目・2GiB）を超えています。現在 '.count($out).'項目・'.$bytes.'バイト：'.$name);
  }ksort($out);return $out;
 }
 public static function pack($root,$dest,$omit=[]){
  $files=self::inventory($root,$omit);$z=new ZipArchive();if($z->open($dest,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('ZIPを作成できません。');
  try{foreach($files as $n=>$i){$ok=$i['dir']?$z->addEmptyDir($n):$z->addFile($root.'/'.$n,$n);if(!$ok)throw new RuntimeException('ZIPへ保存できません：'.$n);}}finally{if(!$z->close())throw new RuntimeException('ZIPの確定に失敗しました。');}chmod($dest,0600);
  if(self::inventory($root,$omit)!==$files)throw new RuntimeException('保存中にファイルが変わりました。未完成として停止します。');
  self::verify_zip($dest,$files);return $files;
 }
 public static function verify_zip($path,$files,$extract=null){
  $z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('ZIPを読めません。');$seen=[];
  try{for($i=0;$i<$z->numFiles;$i++){$raw=$z->getNameIndex($i);$name=rtrim($raw,'/');if(!self::safe($name)||isset($seen[$name])||!isset($files[$name]))throw new RuntimeException('ZIPのパスまたは一覧が不正です。');$seen[$name]=true;$item=$files[$name];
    if($item['dir']){if($extract&&!is_dir($extract.'/'.$name)&&!mkdir($extract.'/'.$name,0700,true))throw new RuntimeException('展開先を作れません。');continue;}
    $stream=$z->getStream($raw);if(!$stream)throw new RuntimeException('ZIPエントリを読めません。');$hash=hash_init('sha256');$size=0;$dest=null;
    if($extract){$parent=dirname($extract.'/'.$name);if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('展開先を作れません。');$dest=fopen($extract.'/'.$name,'x');if(!$dest)throw new RuntimeException('展開先ファイルが存在します。');}
    try{while(!feof($stream)){$b=fread($stream,65536);if($b===false)throw new RuntimeException('読込失敗');$size+=strlen($b);if($size>$item['size'])throw new RuntimeException('ZIPサイズ不一致');hash_update($hash,$b);if($dest&&fwrite($dest,$b)!==strlen($b))throw new RuntimeException('展開容量不足');}}finally{fclose($stream);if($dest)fclose($dest);}
    if($size!==$item['size']||!hash_equals($item['sha256'],hash_final($hash)))throw new RuntimeException('ファイル照合に失敗しました。');
   }if(count($seen)!==count($files))throw new RuntimeException('欠落したファイルがあります。');
   if($extract){foreach($files as $n=>$v)chmod($extract.'/'.$n,$v['mode']);}
  }finally{$z->close();}
 }
 public static function replace_tree($target,$stage,$hold){
  if(is_link($target)||is_link($stage)||file_exists($hold))throw new RuntimeException('退避先・対象パスを確認してください。');
  if(!rename($target,$hold))throw new RuntimeException('現状の退避に失敗しました。');
  if(!rename($stage,$target)){if(!rename($hold,$target))throw new RuntimeException('退避後の復帰に失敗しました。保管領域から手動復旧が必要です。');throw new RuntimeException('差し替えに失敗しました。元の状態へ戻しました。');}
 }
}
