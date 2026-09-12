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
IMAGE='aaihb-restore-test:0.14.1'
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
    report={'status':'not_run','scope':'synthetic_wordpress_6_8_php_8_3_mysql_8_4',
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
        with urllib.request.urlopen('https://wordpress.org/wordpress-6.8.zip',timeout=120) as src,archive.open('wb') as dst:shutil.copyfileobj(src,dst)
        report['wordpress_zip_sha256']=hashlib.sha256(archive.read_bytes()).hexdigest()
        unzip_safe(archive,work);site=work/'site';(work/'wordpress').rename(site)
        unzip_safe(ROOT/'autorepair-ai-hosting-beta-0.14.1.zip',site/'wp-content/plugins')
        report['plugin_zip_sha256']=hashlib.sha256((ROOT/'autorepair-ai-hosting-beta-0.14.1.zip').read_bytes()).hexdigest()
        vault=work/'vault';vault.mkdir(mode=0o700)
        password=secrets.token_hex(24)
        config="""<?php
define('DB_NAME','rehearsal');define('DB_USER','rehearsal');define('DB_PASSWORD',getenv('TEST_DB_PASSWORD'));define('DB_HOST','localhost:/socket/mysqld.sock');define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');$table_prefix='wp_';
define('WP_HOME','http://127.0.0.1:8080');define('WP_SITEURL',WP_HOME);define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('AUTOMATIC_UPDATER_DISABLED',true);
define('AAIHB_VAULT_DIR','/work/vault');define('AAIHB_PUBLIC_ROOT','/work/site');define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';
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
        docker(['exec',php,'php','-r',"require '/work/site/wp-load.php';update_option('aaihb_fixture_roundtrip','original');"],timeout=60)
        report['stage']='real_backup'
        docker(['exec',php,'php','/ci/backup.php'],timeout=300)
        backup_id=(work/'backup-id.txt').read_text().strip();point=vault/backup_id
        report['stage']='real_same_site_restore'
        docker(['exec',php,'php','/ci/roundtrip.php'],timeout=300)
        report['same_site_restore']=json.loads((work/'roundtrip.json').read_text())
        # Test the shipped rehearsal runner against that REAL backup.
        runner_path=site/'wp-content/plugins/autorepair-ai-hosting-beta/restore-test/runner.py'
        spec=importlib.util.spec_from_file_location('shipped_runner',runner_path);runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
        actual_command=runner.command
        form_proof={}
        def observed_command(args,data=None,timeout=120):
            output=actual_command(args,data,timeout)
            if '-r' in args and args[0]=='exec':
                page=json.loads(output)
                if page.get('homepage') is True and page.get('wordpress') is True:
                    proof=actual_command(['exec','-i',args[1],'php'],(ROOT/'ci/comment.php').read_bytes(),60)
                    form_proof.update(json.loads(proof))
            return output
        runner.command=observed_command
        request={'dir':str(point),'manifest':json.loads((point/'manifest.json').read_text()),'nonce':secrets.token_hex(24)}
        report['stage']='real_clone_rehearsal'
        outcome=runner.execute(request);outcome.pop('nonce',None);report['rehearsal']=outcome;report['wordpress_comment_form']=form_proof
        if outcome['status']!='passed' or form_proof.get('comment_form_http_and_database') is not True:raise RuntimeError('rehearsal or form failed')
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
        print(json.dumps({'status':report['status'],'stage':report.get('stage')}))
    return 0 if report['status']=='passed' else 1

if __name__=='__main__':sys.exit(main())
