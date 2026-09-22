"""Reproducible REAL Docker/MySQL acceptance. Never accepts production backups.
No mocks in this script. A missing Docker runtime is a failed/unavailable run.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request
import zipfile

ROOT=Path(__file__).resolve().parents[1]
REPORTS=ROOT/'reports'
IMAGE='aaihb-restore-test:0.20.0'
DB_IMAGE='mysql:8.4'

def docker(args,data=None,timeout=120):
    return subprocess.run(['docker',*args],input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True,timeout=timeout).stdout

def unzip_safe(archive,dest):
    with zipfile.ZipFile(archive) as z:
        for item in z.infolist():
            parts=Path(item.filename).parts
            if item.filename.startswith('/') or '\\' in item.filename or '..' in parts or ((item.external_attr>>16)&0o170000)==0o120000:
                raise ValueError('Unsafe archive')
        z.extractall(dest)

def main():
    REPORTS.mkdir(exist_ok=True)
    report={'status':'not_run','scope':'synthetic_wordpress_latest_php_8_3_mysql_8_4',
            'production_site':'not_tested','purchase_and_license':'not_configured',
            'custom_forms':'not_configured','external_integration':'not_configured'}
    work=Path(tempfile.mkdtemp(prefix='aaihb-e2e-'))
    stem='aaihb-fixture-'+secrets.token_hex(8);db=stem+'-db';php=stem+'-php';socket=stem+'-socket';containers=[];volume=False
    try:
        report['stage']='docker_preflight'
        report['docker_version']=docker(['version','--format','{{.Server.Version}}'],timeout=15).decode().strip()
        report['stage']='images'
        docker(['pull',DB_IMAGE],timeout=300)
        docker(['build','-t',IMAGE,'-f',str(ROOT/'ci/Dockerfile'),str(ROOT/'ci')],timeout=600)
        report['images']={i:docker(['image','inspect','--format','{{.Id}}',i]).decode().strip() for i in (DB_IMAGE,IMAGE)}
        report['stage']='fixture'
        archive=work/'wordpress.zip'
        with urllib.request.urlopen('https://wordpress.org/latest.zip',timeout=120) as src,archive.open('wb') as dst:shutil.copyfileobj(src,dst)
        report['wordpress_zip_sha256']=hashlib.sha256(archive.read_bytes()).hexdigest()
        unzip_safe(archive,work);site=work/'site';(work/'wordpress').rename(site)
        # Start from the prior release so the deployed-file update path is tested for real.
        unzip_safe(ROOT/'autorepair-ai-hosting-beta20.5.zip',site/'wp-content/plugins')
        report['plugin_previous_zip_sha256']=hashlib.sha256((ROOT/'autorepair-ai-hosting-beta20.5.zip').read_bytes()).hexdigest()
        report['plugin_zip_sha256']=hashlib.sha256((ROOT/'autorepair-ai-hosting-beta-0.20.6.zip').read_bytes()).hexdigest()
        cf7_archive=work/'contact-form-7.zip'
        with urllib.request.urlopen('https://downloads.wordpress.org/plugin/contact-form-7.latest-stable.zip',timeout=120) as src,cf7_archive.open('wb') as dst:shutil.copyfileobj(src,dst)
        unzip_safe(cf7_archive,site/'wp-content/plugins')
        report['contact_form_7_zip_sha256']=hashlib.sha256(cf7_archive.read_bytes()).hexdigest()
        wc_archive=work/'woocommerce.zip'
        with urllib.request.urlopen('https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip',timeout=180) as src,wc_archive.open('wb') as dst:shutil.copyfileobj(src,dst)
        unzip_safe(wc_archive,site/'wp-content/plugins')
        report['woocommerce_zip_sha256']=hashlib.sha256(wc_archive.read_bytes()).hexdigest()
        vault=work/'vault';vault.mkdir(mode=0o700)
        password=secrets.token_hex(24)
        config="""<?php
define('DB_NAME','rehearsal');define('DB_USER','rehearsal');define('DB_PASSWORD',getenv('TEST_DB_PASSWORD'));define('DB_HOST','localhost:/socket/mysqld.sock');define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');$table_prefix='wp_';
define('WP_HOME','http://127.0.0.1:8080');define('WP_SITEURL',WP_HOME);define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ACCESSIBLE_HOSTS','127.0.0.1,localhost');define('AUTOMATIC_UPDATER_DISABLED',true);
define('AAIHB_VAULT_DIR','/work/vault');define('AAIHB_PUBLIC_ROOT','/work/site');if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';
"""
        (site/'wp-config.php').write_text(config);(site/'fixture.txt').write_text('original')
        docker(['volume','create',socket]);volume=True
        containers.append(db)
        docker(['run','-d','--name',db,'--network','none','--memory=1g','--cpus=1',
                '-e','MYSQL_ROOT_PASSWORD='+secrets.token_hex(24),'-e','MYSQL_DATABASE=rehearsal',
                '-e','MYSQL_USER=rehearsal','-e','MYSQL_PASSWORD='+password,
                '--tmpfs','/var/lib/mysql:rw,size=2g','--mount','type=volume,src='+socket+',dst=/var/run/mysqld',DB_IMAGE])
        for _ in range(60):
            try:docker(['exec','-e','MYSQL_PWD='+password,db,'mysql','-urehearsal','-e','SELECT 1','rehearsal'],timeout=5);break
            except subprocess.SubprocessError:time.sleep(1)
        else:raise RuntimeError('DB readiness failed')
        containers.append(php)
        docker(['run','-d','--name',php,'--network','none','--user',str(os.getuid())+':'+str(os.getgid()),'--mount','type=bind,src='+str(work)+',dst=/work',
                '--mount','type=bind,src='+str(ROOT/'ci')+',dst=/ci,readonly','--mount','type=volume,src='+socket+',dst=/socket,readonly',
                '-e','TEST_DB_PASSWORD='+password,'-e','AAIHB_CI=1',IMAGE,'php','-S','0.0.0.0:8080','-t','/work/site'])
        docker(['exec',php,'php','/ci/setup.php'],timeout=180)
        report['stage']='real_plugin_upgrade'
        plugin_dir=site/'wp-content/plugins/autorepair-ai-hosting-beta'
        shutil.rmtree(plugin_dir)
        unzip_safe(ROOT/'autorepair-ai-hosting-beta20.6.zip',site/'wp-content/plugins')
        docker(['exec',php,'php','/ci/upgrade.php'],timeout=180)
        report['plugin_update']=json.loads((work/'upgrade.json').read_text())
        if not all(report['plugin_update'].values()):raise RuntimeError('plugin update verification failed')
        docker(['exec',php,'php','-r',"require '/work/site/wp-load.php';update_option('aaihb_fixture_roundtrip','original');"],timeout=60)
        report['stage']='real_backup'
        docker(['exec',php,'php','/ci/backup.php'],timeout=300)
        backup_id=(work/'backup-id.txt').read_text().strip();point=vault/backup_id
        report['stage']='real_same_site_restore'
        docker(['exec',php,'php','/ci/roundtrip.php'],timeout=300)
        report['same_site_restore']=json.loads((work/'roundtrip.json').read_text())
        report['stage']='real_plugin_update_rollback'
        try:
            docker(['exec',php,'php','/ci/rollback.php'],timeout=180)
        except subprocess.CalledProcessError:
            # The script writes its privacy-safe boolean evidence before failing.
            pass
        report['plugin_update_rollback']=json.loads((work/'rollback.json').read_text()) if (work/'rollback.json').is_file() else {'evidence_written':False}
        rollback=report['plugin_update_rollback']
        if not (rollback.get('pre_update_backup_created') and rollback.get('real_http_500_detected_and_rolled_back') and rollback.get('restored_plugin_files') and rollback.get('site_http_after_restore')==200 and rollback.get('database_preserved') and rollback.get('operation_history_saved')):
            raise RuntimeError('plugin update rollback failed')
        # Test the shipped rehearsal runner against that REAL backup.
        runner_path=site/'wp-content/plugins/autorepair-ai-hosting-beta/restore-test/runner.py'
        spec=importlib.util.spec_from_file_location('shipped_runner',runner_path);runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
        actual_command=runner.command
        form_proof={}
        def observed_command(args,data=None,timeout=120):
            output=actual_command(args,data,timeout)
            if args[0]=='exec' and ('-r' in args or '/input/probe.php' in args):
                page=json.loads(output)
                if page.get('homepage') is True and page.get('wordpress') is not True:
                    diag = "$b=file_get_contents('http://127.0.0.1:8080/');echo json_encode(['headers'=>array_map(fn($h)=>explode(':',$h,2)[0],$http_response_header),'mu_file'=>is_file('/site/wp-content/mu-plugins/aaihb-test.php'),'nonce_available'=>strlen(getenv('TEST_NONCE'))>0,'install_page'=>strpos($b,'Installation')!==false]);"
                    report['homepage_diagnostics']=json.loads(actual_command(['exec',args[1],'php','-r',diag]))
                if page.get('homepage') is True and page.get('wordpress') is True:
                    try:
                        proof=actual_command(['exec','-i',args[1],'php'],(ROOT/'ci/comment.php').read_bytes(),60)
                        form_proof.update(json.loads(proof))
                    except subprocess.CalledProcessError as error:
                        form_proof.update({'comment_form_http_and_database':False,'exit_code':error.returncode})
                    try:
                        cf7_proof=actual_command(['exec','-i',args[1],'php'],(ROOT/'ci/contact_form_7.php').read_bytes(),60)
                        form_proof.update(json.loads(cf7_proof))
                    except subprocess.CalledProcessError as error:
                        form_proof.update({'contact_form_7_http_and_mail':False,'cf7_exit_code':error.returncode})
                    try:
                        wc_proof=actual_command(['exec','-i',args[1],'php'],(ROOT/'ci/woocommerce.php').read_bytes(),60)
                        form_proof.update(json.loads(wc_proof))
                    except subprocess.CalledProcessError as error:
                        form_proof.update({'woocommerce_add_to_cart':False,'wc_exit_code':error.returncode})
                    try:
                        ext_proof=actual_command(['exec','-i',args[1],'php'],(ROOT/'ci/external_integration.php').read_bytes(),60)
                        form_proof.update(json.loads(ext_proof))
                    except subprocess.CalledProcessError as error:
                        form_proof.update({'external_api_reachable':False,'ext_exit_code':error.returncode})
            return output
        runner.command=observed_command
        request={'dir':str(point),'manifest':json.loads((point/'manifest.json').read_text()),'nonce':secrets.token_hex(24)}
        report['stage']='real_clone_rehearsal'
        outcome=runner.execute(request);outcome.pop('nonce',None);report['rehearsal']=outcome;report['wordpress_comment_form']=form_proof
        report['custom_forms']={'contact_form_7':form_proof.get('contact_form_7_http_and_mail') is True}
        report['purchase_and_license']={'woocommerce_add_to_cart':form_proof.get('woocommerce_add_to_cart') is True}
        report['external_integration']={'partner_api':form_proof.get('external_api_reachable') is True}
        if outcome['status']!='passed' or form_proof.get('comment_form_http_and_database') is not True or form_proof.get('contact_form_7_http_and_mail') is not True or form_proof.get('woocommerce_add_to_cart') is not True or form_proof.get('external_api_reachable') is not True:raise RuntimeError('rehearsal or form failed')
        report['stage']='corrupt_backup_rejection'
        corrupt=work/'corrupt';shutil.copytree(point,corrupt);(corrupt/'files.zip').write_bytes(b'invalid fixture')
        negative=runner.execute({**request,'dir':str(corrupt)});report['corrupt_backup_rejected']=negative['status']=='failed'
        if not report['corrupt_backup_rejected']:raise RuntimeError('corruption accepted')
        report['status']='passed';report['stage']='complete'
    except Exception:
        report['status']='failed'
    finally:
        clean=True
        for container in reversed(containers):
            try:docker(['rm','-f','-v',container],timeout=30)
            except Exception:clean=False
        if volume:
            try:docker(['volume','rm',socket],timeout=30)
            except Exception:clean=False
        try:shutil.rmtree(work)
        except Exception:clean=False
        report['fixture_cleanup']=clean
        if not clean:report['status']='failed';report['stage']='fixture_cleanup'
        (REPORTS/'docker-e2e.json').write_text(json.dumps(report,indent=2)+'\n')
        print(json.dumps(report))
    return 0 if report['status']=='passed' else 1

if __name__=='__main__':sys.exit(main())
