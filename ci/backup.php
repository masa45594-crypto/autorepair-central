<?php
if(PHP_SAPI!=='cli'||getenv('AAIHB_CI')!=='1')exit(1);
require '/work/site/wp-load.php';
$id=AAIHB_FullSite::backup();
file_put_contents('/work/backup-id.txt',$id);
