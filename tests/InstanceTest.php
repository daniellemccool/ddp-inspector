<?php
// Runs with the legacy test config already loaded by earlier test files.
require_once __DIR__ . '/../src/bootstrap.php';

eq(inst_root(), null, 'legacy config has no storage root');
eq(inst_effective_ddp_dir(), __DIR__ . '/fixtures/ddp', 'legacy effective ddp_dir');
eq(inst_configured(), true, 'legacy mode with ddp_dir counts as configured');

// Switch to storage mode against a scratch tree.
$scratch = sys_get_temp_dir() . '/ddp-inspector-test-' . getmypid();
exec('rm -rf ' . escapeshellarg($scratch));
$GLOBALS['__cfg_saved_inst'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $scratch, 'default_n' => 15, 'base_path' => ''];

eq(inst_root(), $scratch, 'storage root read from config');
eq(inst_paths()['inbox'], "$scratch/data/inbox", 'inbox path derived');
eq(inst_effective_ddp_dir(), "$scratch/data/inbox", 'storage effective ddp_dir');
eq(inst_configured(), false, 'no instance.json -> unconfigured');
eq(inst_load(), null, 'load returns null when missing');

$inst = ['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/tmp/x',
         'cadence' => 'off', 'default_n' => 15];
eq(inst_save($inst), true, 'instance saved');
eq(inst_configured(), true, 'instance.json -> configured');
eq(inst_load()['study_name'], 'Pilot', 'instance round-trips');

$src = ['mode' => 'yoda', 'collection' => '/nluu10p/home/x', 'host' => 'fsw.data.uu.nl',
        'zone' => 'nluu10p', 'ticket' => 'SECRET'];
eq(inst_source_save($src), true, 'source saved');
eq(inst_source_load()['ticket'], 'SECRET', 'source round-trips');
eq(substr(sprintf('%o', fileperms("$scratch/config/source.json")), -3), '600', 'source.json is 0600');
check(!file_exists("$scratch/config/source.json.tmp"), 'atomic write leaves no temp file');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_inst'];
