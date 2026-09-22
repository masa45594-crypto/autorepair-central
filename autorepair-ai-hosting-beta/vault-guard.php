<?php
if(!defined('ABSPATH'))exit;
/** Directory plugin snapshots. Single-file plugins and self-updates deliberately fail closed. */
final class AAIHB_PluginGuard {
 public static function boot(){
  add_filter('upgrader_pre_install',[__CLASS__,'before'],10,2);
  add_action('upgrader_process_complete',[__CLASS__,'after'],10,2);
 }
 public static function target($plugin,$allow_missing=false){
  if(!AAIHB_VaultIO::safe($plugin)||substr_count($plugin,'/')<1)throw new RuntimeException('ディレクトリ形式のプラグインが対象です。');
  $dir=explode('/',$plugin)[0];if($dir===basename(__DIR__))throw new RuntimeException('Hosting Beta自身はこの方法では復元しません。');
  $root=rtrim(WP_PLUGIN_DIR,'/').'/'.$dir;
  if(is_link($root)||realpath(dirname($root))!==dirname($root)||(file_exists($root)&&realpath($root)!==$root)||(!$allow_missing&&(!is_dir($root)||!is_file(WP_PLUGIN_DIR.'/'.$plugin)))||is_link(WP_PLUGIN_DIR.'/'.$plugin))throw new RuntimeException('対象プラグインの実パスを確認してください。');
  return $root;
 }
 public static function snapshot($plugin){
  $root=self::target($plugin);if(stat(dirname($root))['dev']!==stat(AAIHB_VaultIO::root())['dev'])throw new RuntimeException('保管先とプラグインは同じファイルシステムに配置してください。');$lock=AAIHB_VaultIO::lock();
  try{[$id,$dir]=AAIHB_VaultIO::fresh();$files=AAIHB_VaultIO::pack($root,$dir.'/files.zip');
   AAIHB_VaultIO::manifest($dir,['state'=>'ready','kind'=>'plugin','id'=>$id,'at'=>gmdate('c'),'plugin'=>$plugin,'root'=>$root,'root_mode'=>fileperms($root)&0777,'files'=>$files,'payloads'=>['files.zip'=>hash_file('sha256',$dir.'/files.zip')]]);return $id;
  }finally{AAIHB_VaultIO::unlock($lock);}
 }
 public static function restore($id,$expected=null){
  [$dir,$d]=AAIHB_VaultIO::point($id);if($d['kind']!=='plugin')throw new RuntimeException('プラグイン用の保存ポイントを選んでください。');$root=self::target($d['plugin'],true);if($root!==$d['root'])throw new RuntimeException('別のサイトには復元できません。');
  $lock=AAIHB_VaultIO::lock();try{
   $now=is_dir($root)?AAIHB_VaultIO::inventory($root):null;if($expected!==null&&$now!==$expected)throw new RuntimeException('更新後にファイルが変化しました。自動復元を中止します。');
   [$work,$path]=AAIHB_VaultIO::fresh();$stage=$path.'/stage';mkdir($stage,0700);AAIHB_VaultIO::verify_zip($dir.'/files.zip',$d['files'],$stage);chmod($stage,$d['root_mode']);
   if((is_dir($root)?AAIHB_VaultIO::inventory($root):null)!==$now)throw new RuntimeException('復元準備中に対象が変わりました。');
   $journal=['state'=>'prepared','source'=>$id,'root'=>$root,'stage'=>$stage,'hold'=>$path.'/previous'];AAIHB_VaultIO::write($path.'/operation.json',wp_json_encode($journal));
   if($now===null){if(!rename($stage,$root))throw new RuntimeException('削除済みプラグインを配置できません。');}else AAIHB_VaultIO::replace_tree($root,$stage,$path.'/previous');$journal['state']='complete';AAIHB_VaultIO::write($path.'/operation.json',wp_json_encode($journal));
   if(function_exists('opcache_invalidate'))foreach($d['files'] as $name=>$v)if(!$v['dir']&&substr($name,-4)==='.php')opcache_invalidate($root.'/'.$name,true);
   wp_clean_plugins_cache(false);return $work;
  }finally{AAIHB_VaultIO::unlock($lock);}
 }
 public static function probe(){
  $r=wp_remote_get(home_url('/'),['timeout'=>15,'redirection'=>0,'headers'=>['Cache-Control'=>'no-cache']]);return is_wp_error($r)?0:(int)wp_remote_retrieve_response_code($r);
 }
 public static function before($response,$extra){
  if(is_wp_error($response)||!get_option('aaihb_guard_enabled')||empty($extra['plugin']))return $response;
  if(get_option('aaihb_recovery_gate_enabled')){
   $gate=AAIHB_RecoveryEvidence::gate();$override=get_transient('aaihb_recovery_gate_override');
   if($gate['state']!=='ready'&&!$override){AAIHB_RecoveryEvidence::event('update_gate_blocked',(string)$extra['plugin'],$gate['message']);return new WP_Error('aaihb_recovery_gate','更新前セーフティゲート：'.$gate['message'].' バックアップの復元テスト後に再試行するか、管理者画面で10分間だけ承認してください。');}
  }
  try{$id=self::snapshot($extra['plugin']);$pending=get_option('aaihb_guard_pending',[]);$pending[$extra['plugin']]=['id'=>$id,'at'=>time(),'baseline'=>self::probe()];update_option('aaihb_guard_pending',$pending,false);return $response;}
  catch(Throwable $e){return new WP_Error('aaihb_backup_failed','更新前バックアップを作れないため更新を停止しました：'.$e->getMessage());}
 }
 public static function after($upgrader,$extra){
  if(($extra['type']??'')!=='plugin'||($extra['action']??'')!=='update')return;
  $plugins=$extra['plugins']??(isset($extra['plugin'])?[$extra['plugin']]:[]);$pending=get_option('aaihb_guard_pending',[]);
  foreach($plugins as $plugin){$item=$pending[$plugin]??null;unset($pending[$plugin]);if(!$item)continue;
   $message='更新前のファイルを保存しました。';
   try{if(get_option('aaihb_guard_enabled')&&count($plugins)===1&&time()-$item['at']<3600&&$item['baseline']>=200&&$item['baseline']<300){
     $inventory=AAIHB_VaultIO::inventory(self::target($plugin));$first=self::probe();$second=$first>=500&&$first<=599?self::probe():0;
     if($first>=500&&$first<=599&&$second>=500&&$second<=599){self::restore($item['id'],$inventory);$message='連続するHTTP 5xxを検出し、更新前のファイルへ戻しました。復旧状態は別途確認してください。';}
     else $message='ファイル保存済み。自動ロールバック条件（連続5xx）には該当しませんでした。';
    }}catch(Throwable $e){$message='自動復元は完了していません：'.$e->getMessage();}
   update_option('aaihb_guard_last',['at'=>gmdate('c'),'plugin'=>$plugin,'id'=>$item['id'],'message'=>$message],false);
  }update_option('aaihb_guard_pending',$pending,false);
 }
}
