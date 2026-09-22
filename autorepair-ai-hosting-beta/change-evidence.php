<?php
if(!defined('ABSPATH'))exit;
/** Privacy-safe update evidence. Records versions, never file contents or credentials. */
final class AAIHB_ChangeEvidence {
 const PENDING='aaihb_change_pending';const HISTORY='aaihb_change_history';
 public static function boot(){add_filter('upgrader_pre_install',[__CLASS__,'before'],8,2);add_action('upgrader_process_complete',[__CLASS__,'after'],8,2);}
 private static function target($extra){
  $type=sanitize_key($extra['type']??'');if(!in_array($type,['plugin','theme','core'],true))return null;
  $target=$type==='plugin'?sanitize_text_field($extra['plugin']??''):($type==='theme'?sanitize_text_field($extra['theme']??''):'wordpress');
  if($target==='')return null;return [$type,$target];
 }
 private static function version($type,$target){
  if($type==='core')return get_bloginfo('version');
  if($type==='theme'){ $theme=wp_get_theme($target);return $theme&&$theme->exists()?$theme->get('Version'):''; }
  require_once ABSPATH.'wp-admin/includes/plugin.php';$all=get_plugins();return isset($all[$target])?(string)$all[$target]['Version']:'';
 }
 public static function before($response,$extra){
  if(is_wp_error($response)||($extra['action']??'')!=='update'||!($item=self::target($extra)))return $response;
  [$type,$target]=$item;$all=get_option(self::PENDING,[]);if(!is_array($all))$all=[];$key=$type.':'.$target;
  $all[$key]=['type'=>$type,'target'=>$target,'before'=>self::version($type,$target),'php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'at'=>gmdate('c')];update_option(self::PENDING,$all,false);return $response;
 }
 public static function after($upgrader,$extra){
  if(($extra['action']??'')!=='update'||!($item=self::target($extra)))return;[$type,$target]=$item;$key=$type.':'.$target;$all=get_option(self::PENDING,[]);$before=is_array($all)?($all[$key]??null):null;if(!is_array($before))return;
  unset($all[$key]);update_option(self::PENDING,$all,false);$after=self::version($type,$target);$changed=$before['before']!==$after;
  $history=get_option(self::HISTORY,[]);if(!is_array($history))$history=[];$history[]=['at'=>gmdate('c'),'type'=>$type,'target'=>$target,'before'=>$before['before'],'after'=>$after,'wordpress_before'=>$before['wordpress'],'wordpress_after'=>get_bloginfo('version'),'php'=>$before['php'],'changed'=>$changed];update_option(self::HISTORY,array_slice($history,-50),false);
  if(class_exists('AAIHB_RecoveryEvidence'))AAIHB_RecoveryEvidence::event('update_recorded',$target,$changed?'更新差分を記録':'バージョン差分なし');
 }
 public static function latest(){ $history=get_option(self::HISTORY,[]);return is_array($history)&&$history?array_reverse($history):[]; }
}
