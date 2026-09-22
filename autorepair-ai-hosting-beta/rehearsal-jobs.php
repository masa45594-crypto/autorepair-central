<?php
if(!defined('ABSPATH'))exit;
/** Queues isolated rehearsal only; it never restores the production site. */
final class AAIHB_RehearsalJobs {
 const JOBS='aaihb_rehearsal_jobs';const SETTINGS='aaihb_rehearsal_settings';
 public static function boot(){
  add_filter('cron_schedules',function($s){if(!isset($s['weekly']))$s['weekly']=['interval'=>7*DAY_IN_SECONDS,'display'=>'Weekly'];return $s;});
  add_action('aaihb_rehearsal_run',[__CLASS__,'run']);add_action('aaihb_rehearsal_weekly',[__CLASS__,'weekly']);
 }
 public static function settings(){return wp_parse_args(get_option(self::SETTINGS,[]),['weekly'=>false,'notify'=>false]);}
 public static function jobs(){ $jobs=get_option(self::JOBS,[]);return is_array($jobs)?$jobs:[]; }
 private static function put($jobs){update_option(self::JOBS,array_slice($jobs,-30,true),false);}
 public static function enqueue($point,$source='manual'){
  [, $m]=AAIHB_VaultIO::point($point);if(($m['kind']??'')!=='full')throw new RuntimeException('サイト全体の保存ポイントを選択してください。');
  foreach(self::jobs() as $job)if(in_array($job['status']??'',['queued','running'],true))throw new RuntimeException('すでに復旧リハーサルが実行中または予約済みです。');
  $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));$jobs=self::jobs();$jobs[$id]=['id'=>$id,'point'=>$point,'source'=>$source,'status'=>'queued','queued_at'=>gmdate('c'),'started_at'=>'','finished_at'=>'','stage'=>''];self::put($jobs);
  if(!wp_schedule_single_event(time()+5,'aaihb_rehearsal_run',[$id]))throw new RuntimeException('リハーサルの予約を作成できません。WP-Cronの設定を確認してください。');
  AAIHB_RecoveryEvidence::event('recovery_test_started',$point,'予約');return $id;
 }
 public static function run($id){
  $jobs=self::jobs();$job=$jobs[$id]??null;if(!is_array($job)||($job['status']??'')!=='queued')return;$job['status']='running';$job['started_at']=gmdate('c');$jobs[$id]=$job;self::put($jobs);
  try{$r=AAIHB_RestoreTest::run($job['point']);$job['status']=in_array($r['status']??'', ['passed','unavailable'],true)?$r['status']:'failed';$job['stage']=$r['stage']??'';}catch(Throwable $e){$job['status']='failed';$job['stage']='runner';}
  $job['finished_at']=gmdate('c');$jobs=self::jobs();$jobs[$id]=$job;self::put($jobs);
  if($job['status']==='failed'&&self::settings()['notify']){ $to=get_option('admin_email');if(is_email($to))wp_mail($to,'[AutoRepair AI] 隔離復元テストが不合格です','保存ポイント：'.$job['point']."\n確認段階：".$job['stage']."\n\n管理画面で復旧リハーサルの結果を確認してください。"); }
 }
 public static function weekly(){if(!self::settings()['weekly'])return;$latest=AAIHB_RecoveryEvidence::latest();if($latest)try{self::enqueue($latest['point']['id'],'weekly');}catch(Throwable $e){/* Existing job or unavailable vault: no production action. */}}
 public static function save_settings($weekly,$notify){$s=['weekly'=>(bool)$weekly,'notify'=>(bool)$notify];update_option(self::SETTINGS,$s,false);wp_clear_scheduled_hook('aaihb_rehearsal_weekly');if($s['weekly']&&!wp_schedule_event(time()+60,'weekly','aaihb_rehearsal_weekly',[],true))throw new RuntimeException('定期リハーサルを予約できません。WP-Cronの設定を確認してください。');}
 public static function latest(){ $jobs=self::jobs();return $jobs?end($jobs):null; }
}
