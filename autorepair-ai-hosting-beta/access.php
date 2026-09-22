<?php
if(!defined('ABSPATH'))exit;
final class AAIHB_Access {
 const VIEW='aaihb_view_recovery';const RUN='aaihb_run_rehearsal';const MANAGE='aaihb_manage_recovery';
 public static function boot(){add_action('init',[__CLASS__,'install']);}
 public static function install(){if(get_option('aaihb_access_v1'))return;$admin=get_role('administrator');if($admin)foreach([self::VIEW,self::RUN,self::MANAGE] as $cap)$admin->add_cap($cap);add_role('aaihb_recovery_operator','AutoRepair AI 保守担当者',[self::VIEW=>true,self::RUN=>true,'read'=>true]);update_option('aaihb_access_v1',1,false);}
 public static function can($cap){return current_user_can('manage_options')||current_user_can($cap);}
}
