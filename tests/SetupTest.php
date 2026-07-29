<?php
// Storage-mode scratch instance for wizard tests.
$scratch2 = sys_get_temp_dir() . '/ddp-inspector-setup-' . getmypid();
exec('rm -rf ' . escapeshellarg($scratch2));
$GLOBALS['__cfg_saved_setup'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $scratch2, 'default_n' => 15, 'base_path' => ''];

// Handlers directly (upload simulated by pre-placed file).
$_COOKIE['ddpi_csrf'] = str_repeat('a', 32);
$zip = $scratch2 . '-flow.zip';
$phar = new PharData($zip);
$phar->addFromString('documentation.txt', (string)file_get_contents(__DIR__ . '/fixtures/flows/tiktok/documentation.txt'));
$phar->addFromString('build_x_y_tiktok_development_2026-01-01_00-00-00.zip', 'x');
unset($phar);
$r = inst_handle_setup_post(['action' => 'upload_flow', 'csrf' => csrf_token()],
                            ['flow_zip' => ['tmp_name' => $zip, 'error' => 0]]);
eq($r['flash'][0]['kind'], 'ok', 'flow upload accepted');
check(str_contains($r['flash'][0]['text'], 'TikTok'), 'upload confirmation names platform');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x', 'access_code' => 'TICKET123'], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda source saved');
eq(inst_load()['source_mode'], 'yoda', 'instance saved');
eq(inst_source_load()['ticket'], 'TICKET123', 'ticket stored');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'rd-link', 'study_name' => 'RD study',
    'share_link' => 'https://researchdrive.surf.nl/index.php/s/AbCdEf123', 'link_password' => 'pw'], []);
eq(inst_source_load()['share_token'], 'AbCdEf123', 'share token from link');
eq(inst_source_load()['webdav_url'], 'https://researchdrive.surf.nl/public.php/dav/files/AbCdEf123/', 'webdav url derived (modern form)');
check(inst_source_exists(), 'rd-link source.json exists before switching to local mode');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'local', 'study_name' => 'Local study', 'local_path' => '/tmp/some-donations'], []);
eq($r['flash'][0]['kind'], 'ok', 'local source saved');
eq(inst_source_exists(), false, 'switching to local mode deletes stale source.json');
check(!is_file("$scratch2/config/source.json"), 'source.json removed from disk on local mode switch');

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => csrf_token()], []);
check(is_file("$scratch2/state/refresh-requested"), 'refresh flag touched by handler');

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => 'nope'], []);
eq($r['flash'][0]['kind'], 'error', 'bad csrf rejected');

// Page renders: no secret echo, flows listed, status shown.
inst_write_json_atomic("$scratch2/state/refresh-status.json",
    ['phase' => 'error', 'started_at' => null, 'finished_at' => null, 'donations' => null,
     'message' => 'Your access code has expired — ask your data manager for a new one.']);
$_POST = [];
$html = render_page('setup.php', []);
check(!str_contains($html, 'TICKET123'), 'ticket never echoed');
check(!str_contains($html, '>pw<'), 'password never echoed');
check(str_contains($html, 'TikTok'), 'uploaded flow listed');
check(str_contains($html, 'access code has expired'), 'status message rendered');
check(str_contains($html, 'Technical log'), 'log details present');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_setup'];
