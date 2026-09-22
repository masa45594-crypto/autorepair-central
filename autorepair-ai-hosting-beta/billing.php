<?php
if(!defined('ABSPATH'))exit;
// Compatibility with existing registration callers. No fixed commercial tiers.
final class AAIHB_Billing {
 public static function boot(){add_action('admin_menu',function(){add_submenu_page('aaihb-home','利用数と料金','利用数と料金','manage_options','aaihb-license',['AAIHB_Central','page']);});}
 public static function configured(){return false;}
 public static function assert_registration($id){
  if(!AAIHB_Beta::sites_exists($id)){
   if(get_option('aaihb_central_suspended',false))throw new RuntimeException('中央サーバーとの接続が拒否されているため、新しいサイトを登録できません。「利用数と料金」画面で状態を確認してください。');
   if(AAIHB_Beta::sites_count()>=100000)throw new RuntimeException('試作の送信上限です。運用規模の検証が必要です。');
  }
 }
 public static function scan_error(){return null;}
 public static function meter($ignored=null){}
}
