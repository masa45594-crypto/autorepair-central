<?php
if(!defined('ABSPATH'))exit;
/** Explicitly acknowledged full-backup queue. It never runs without user approval. */
final class AAIHB_BackupJobs {
 const JOB='aaihb_full_backup_job';
 public static function boot(){add_action('aaihb_full_backup_run',[__CLASS__,'run']);}
 public static function enqueue(){$prior=get_option(self::JOB,[]);if(is_array($prior)&&in_array(($prior['status']??''),['queued','running'],true))throw new RuntimeException('すでにバックアップが予約済みまたは実行中です。');$job=['status'=>'queued','queued_at'=>gmdate('c'),'finished_at'=>'','id'=>'','stage'=>'queued','message'=>''];update_option(self::JOB,$job,false);if(!wp_schedule_single_event(time()+5,'aaihb_full_backup_run')){delete_option(self::JOB);throw new RuntimeException('バックアップ予約を作成できません。WP-Cronの設定を確認してください。');}}
 private static function label($stage){$labels=['queued'=>'実行待ち','preflight'=>'開始前確認','lock'=>'保管先のロック','database'=>'データベースの保存','files'=>'ファイルのZIP保存','finalize'=>'署名・確定'];return $labels[$stage]??'不明な段階';}
 private static function hint($stage){$hints=['preflight'=>'サイト親フォルダの書き込み権限と通常のWordPress配置を確認してください。','lock'=>'別の保管・復元処理が終わってから再実行してください。','database'=>'DB権限、対象テーブルの形式、空き容量を確認してください。','files'=>'読み込めないファイル、特殊ファイル、2GiB・5万項目の上限、空き容量を確認してください。','finalize'=>'保管先の空き容量と書き込み権限を確認してください。'];return $hints[$stage]??'保管先、空き容量、書き込み停止を確認してください。';}
 private static function detail($message){$message=preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)$message);$message=trim($message);return function_exists('mb_substr')?mb_substr($message,0,240):substr($message,0,240);}
 public static function run(){$job=get_option(self::JOB,[]);if(!is_array($job)||($job['status']??'')!=='queued')return;$job['status']='running';$job['stage']='preflight';update_option(self::JOB,$job,false);try{$job['id']=AAIHB_FullSite::backup(function($stage)use(&$job){$job['stage']=$stage;update_option(self::JOB,$job,false);});$job['status']='passed';$job['stage']='complete';$job['message']='サイト全体バックアップを作成しました。';}catch(Throwable $e){$job['status']='failed';$stage=$job['stage']??'';$job['message']=self::label($stage).'で停止しました。'.self::hint($stage).' 詳細：'.self::detail($e->getMessage());}$job['finished_at']=gmdate('c');update_option(self::JOB,$job,false);}
 public static function latest(){ $v=get_option(self::JOB,[]);return is_array($v)?$v:[]; }
}
