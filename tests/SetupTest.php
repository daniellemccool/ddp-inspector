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

// Yoda: the researcher's own configuration (pasted or uploaded, JSON or the
// GoCommands YAML, exactly as the Yoda portal offers it) + data access password.
$cfgJson = '{"irods_host": "lp0555.its.uu.nl", "irods_port": 1247, "irods_home": "/nluu10p/home",'
    . ' "irods_user_name": "r@uu.nl", "irods_zone_name": "nluu10p", "irods_default_resource": "irodsResc",'
    . ' "irods_authentication_scheme": "pam_password", "irods_encryption_algorithm": "AES-256-CBC",'
    . ' "irods_encryption_key_size": 32, "irods_client_server_policy": "CS_NEG_REQUIRE"}';
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => $cfgJson, 'yoda_password' => 'DAP123'], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda source saved');
eq(inst_load()['source_mode'], 'yoda', 'instance saved');
eq(inst_source_load()['password'], 'DAP123', 'data access password stored');
eq(inst_source_load()['env']['irods_host'] ?? null, 'lp0555.its.uu.nl', 'pasted config stored as-is (host)');
eq(inst_source_load()['env']['irods_default_resource'] ?? null, 'irodsResc', 'pasted config stored as-is (resource)');
check(!isset(inst_source_load()['env']['irods_user_password']), 'no password inside the stored config');

// The page shows the saved configuration (not secret) but never the DAP.
$_POST = [];
$html = render_page('setup.php', []);
check(str_contains($html, 'r@uu.nl'), 'saved configuration shown in the paste box');
check(!str_contains($html, 'DAP123'), 'data access password never echoed');

// Re-saving "About your study" (e.g. a new study name) with the config box
// and password left blank keeps both — the password field shows "saved ✓".
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study (renamed)',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '', 'yoda_password' => ''], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda re-save with blank config and password accepted');
eq(inst_load()['study_name'], 'Crime study (renamed)', 'study name updated on re-save');
eq(inst_source_load()['password'], 'DAP123', 'blank password keeps the saved DAP');
eq(inst_source_load()['env']['irods_user_name'] ?? null, 'r@uu.nl', 'blank config box keeps the saved config');

// An uploaded YAML file (the portal's "Download as YAML") wins over the box;
// same user, so a blank password still keeps the saved DAP.
$yml = $scratch2 . '-config.yml';
file_put_contents($yml, "irods_host: \"lp0555.its.uu.nl\"\nirods_port: 1247\nirods_home: \"/nluu10p/home\"\n"
    . "irods_user_name: \"r@uu.nl\"\nirods_zone_name: \"nluu10p\"\nirods_authentication_scheme: \"pam_password\"\n"
    . "irods_encryption_key_size: 32\n");
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '{"not": "used"}', 'yoda_password' => ''],
    ['irods_config_file' => ['tmp_name' => $yml, 'error' => UPLOAD_ERR_OK]]);
eq($r['flash'][0]['kind'], 'ok', 'uploaded YAML config accepted');
eq(inst_source_load()['env']['irods_port'] ?? null, 1247, 'uploaded YAML parsed (port is an int)');
check(!isset(inst_source_load()['env']['irods_default_resource']), 'upload replaced the saved config');
eq(inst_source_load()['password'], 'DAP123', 'same user: blank password keeps the saved DAP');

// A configuration for a DIFFERENT user needs its own password.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x',
    'irods_config' => str_replace('r@uu.nl', 'other@uu.nl', $cfgJson), 'yoda_password' => ''], []);
eq($r['flash'][0]['kind'], 'error', 'new user with blank password rejected');
eq(inst_source_load()['env']['irods_user_name'] ?? null, 'r@uu.nl', 'rejected save leaves the saved config alone');

// An incomplete paste is rejected with a pointer to the portal.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '{"irods_host": "x"}', 'yoda_password' => 'D'], []);
eq($r['flash'][0]['kind'], 'error', 'incomplete config rejected');
check(str_contains($r['flash'][0]['text'], 'Yoda portal'), 'incomplete config points at the Yoda portal');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'rd-link', 'study_name' => 'RD study',
    'share_link' => 'https://researchdrive.surf.nl/index.php/s/AbCdEf123', 'link_password' => 'pw'], []);
eq(inst_source_load()['share_token'], 'AbCdEf123', 'share token from link');
eq(inst_source_load()['webdav_url'], 'https://researchdrive.surf.nl/public.php/dav/files/AbCdEf123/', 'webdav url derived (modern form)');
check(inst_source_exists(), 'rd-link source.json exists before switching to local mode');

// Same share link, blank password: keep the saved password.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'rd-link', 'study_name' => 'RD study (renamed)',
    'share_link' => 'https://researchdrive.surf.nl/index.php/s/AbCdEf123', 'link_password' => ''], []);
eq($r['flash'][0]['kind'], 'ok', 'rd-link re-save with blank password accepted');
eq(inst_source_load()['password'], 'pw', 'blank password keeps the saved password');

// A DIFFERENT share link needs its own password: never carry one over.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'rd-link', 'study_name' => 'RD study',
    'share_link' => 'https://researchdrive.surf.nl/index.php/s/OtherLink9', 'link_password' => ''], []);
eq($r['flash'][0]['kind'], 'error', 'new share link with blank password rejected');
eq(inst_source_load()['share_token'], 'AbCdEf123', 'rejected save leaves the saved link alone');

// Switching rd-link -> yoda with a blank config and password: nothing to keep.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'RD study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '', 'yoda_password' => ''], []);
eq($r['flash'][0]['kind'], 'error', 'switch to yoda without a configuration rejected');
eq(inst_source_load()['mode'], 'rd-link', 'rejected switch leaves the saved source alone');

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
     'message' => 'Yoda did not accept your username and data access password.']);
$_POST = [];
$html = render_page('setup.php', []);
check(!str_contains($html, '>pw<'), 'password never echoed');
check(str_contains($html, 'TikTok'), 'uploaded flow listed');
check(str_contains($html, 'did not accept your username'), 'status message rendered');
check(str_contains($html, 'Technical log'), 'log details present');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_setup'];
