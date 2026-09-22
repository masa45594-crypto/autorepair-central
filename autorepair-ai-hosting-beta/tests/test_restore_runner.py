import importlib.util
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

MODULE = Path(__file__).resolve().parents[1] / 'restore-test' / 'runner.py'
spec = importlib.util.spec_from_file_location('runner', MODULE)
r = importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory();self.root = Path(self.tmp.name)
        data = b'<?php // source secret must not execute'
        with zipfile.ZipFile(self.root/'files.zip','w') as z:z.writestr('wp-config.php',data)
        (self.root/'database.jsonl').write_text('{}\n')
        self.m = {'kind':'full','prefix':'wp_','files':{'wp-config.php':{'dir':False,'size':len(data),'sha256':hashlib.sha256(data).hexdigest()}},'payloads':{n:hashlib.sha256((self.root/n).read_bytes()).hexdigest() for n in ('files.zip','database.jsonl')}}
        self.request={'dir':str(self.root),'manifest':self.m,'nonce':'test-nonce'}
        self.calls=[];self.clones=[]
    def tearDown(self):self.tmp.cleanup()
    def docker(self,args,data=None,timeout=120):
        self.calls.append(args)
        if args[0]=='run' and r.PHP_IMAGE in args:
            mount=next(a for a in args if a.endswith('dst=/site'))
            p=Path(mount.split('src=')[1].split(',dst=')[0]);self.clones.append(p)
            config=(p/'wp-config.php').read_text()
            self.assertNotIn('source secret',config);self.assertIn('localhost:/socket/mysqld.sock',config)
            self.assertNotEqual(p,self.root)
        if args[:1]==['exec'] and '/input/import.php' in args:return b'{"database":true}'
        if '/input/probe.php' in args:return b'{"homepage":true,"wordpress":true}'
        return b'fixture'
    def run_mock(self):
        with patch.object(r,'command',side_effect=self.docker):return r.execute(self.request)
    def test_success_requires_all_checks(self):
        out=self.run_mock();self.assertEqual(out['status'],'passed');self.assertTrue(all(out['checks'].values()))
        for args in self.calls:
            if args[0]=='run':self.assertEqual(args[args.index('--network')+1],'none');self.assertNotIn('-p',args)
        self.assertEqual((self.root/'files.zip').read_bytes()[:2],b'PK');self.assertTrue(all(not p.exists() for p in self.clones))
    def test_missing_docker_is_unavailable(self):
        with patch.object(r,'command',side_effect=FileNotFoundError):out=r.execute(self.request)
        self.assertEqual(out['status'],'unavailable');self.assertFalse(out['checks']['database'])
    def test_corrupt_backup_never_starts_clone(self):
        (self.root/'files.zip').write_bytes(b'broken');out=self.run_mock()
        self.assertEqual(out['status'],'failed');self.assertFalse(any(x[0]=='run' for x in self.calls))
    def test_wrong_prefix_rejected(self):
        self.m['prefix']="');evil();";self.assertEqual(self.run_mock()['status'],'failed')
    def test_database_error_not_passed(self):
        original=self.docker
        def fail(args,**kw):
            if '/input/import.php' in args:raise subprocess.CalledProcessError(1,args)
            return original(args,**kw)
        with patch.object(r,'command',side_effect=fail):out=r.execute(self.request)
        self.assertEqual(out['status'],'failed');self.assertEqual(out['stage'],'database');self.assertTrue(out['checks']['cleanup'])
    def test_http_failure_not_passed(self):
        original=self.docker
        def fail(args,**kw):return b'{"homepage":false,"wordpress":true}' if '/input/probe.php' in args else original(args,**kw)
        with patch.object(r,'command',side_effect=fail):out=r.execute(self.request)
        self.assertEqual(out['status'],'failed')
    def test_static_page_without_wordpress_not_passed(self):
        original=self.docker
        def fail(args,**kw):return b'{"homepage":true,"wordpress":false}' if '/input/probe.php' in args else original(args,**kw)
        with patch.object(r,'command',side_effect=fail):out=r.execute(self.request)
        self.assertEqual(out['status'],'failed')
    def test_cleanup_failure_not_passed(self):
        original=self.docker
        def fail(args,**kw):
            if args[0]=='rm':raise subprocess.CalledProcessError(1,args)
            return original(args,**kw)
        with patch.object(r,'command',side_effect=fail):out=r.execute(self.request)
        self.assertEqual(out['status'],'failed');self.assertEqual(out['stage'],'cleanup')
    def test_zip_traversal(self):
        with zipfile.ZipFile(self.root/'bad.zip','w') as z:z.writestr('../escape','bad')
        with self.assertRaises(ValueError):r.extract_verified(self.root/'bad.zip',self.root,{'../escape':{'dir':False}})
    def test_hash_mismatch(self):
        self.m['files']['wp-config.php']['sha256']='0'*64
        self.assertEqual(self.run_mock()['status'],'failed')

if __name__=='__main__':unittest.main()
