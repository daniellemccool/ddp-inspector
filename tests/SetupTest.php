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
// The study name is saved on its own, before any source exists; a name alone
// does not make the instance configured.
$r = inst_handle_setup_post(['action' => 'save_name', 'csrf' => csrf_token(), 'study_name' => '  Example study  '], []);
eq($r['flash'][0]['kind'], 'ok', 'study name saved on its own');
eq(inst_load()['study_name'], 'Example study', 'study name trimmed and stored');
eq(inst_configured(), false, 'a name alone does not make the instance configured');

$cfgJson = '{"irods_host": "lp0555.its.uu.nl", "irods_port": 1247, "irods_home": "/nluu10p/home",'
    . ' "irods_user_name": "r@uu.nl", "irods_zone_name": "nluu10p", "irods_default_resource": "irodsResc",'
    . ' "irods_authentication_scheme": "pam_password", "irods_encryption_algorithm": "AES-256-CBC",'
    . ' "irods_encryption_key_size": 32, "irods_client_server_policy": "CS_NEG_REQUIRE"}';
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Example study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => $cfgJson, 'yoda_password' => 'DAP123'], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda source saved');
eq(inst_load()['source_mode'], 'yoda', 'instance saved');
eq(inst_source_load()['password'], 'DAP123', 'data access password stored');
eq(inst_source_load()['env']['irods_host'] ?? null, 'lp0555.its.uu.nl', 'pasted config stored as-is (host)');
eq(inst_source_load()['env']['irods_default_resource'] ?? null, 'irodsResc', 'pasted config stored as-is (resource)');
check(!isset(inst_source_load()['env']['irods_user_password']), 'no password inside the stored config');
eq(inst_load()['study_name'], 'Example study', 'saving the source keeps the study name');
$r = inst_handle_setup_post(['action' => 'save_cadence', 'csrf' => csrf_token(), 'cadence' => 'daily'], []);
eq($r['flash'][0]['kind'], 'ok', 'daily check saved on its own');
eq(inst_load()['cadence'], 'daily', 'daily check stored');
eq(inst_load()['source_mode'], 'yoda', 'saving the daily check keeps the source');

// The page shows the saved configuration (not secret) but never the DAP.
$_POST = [];
$html = render_page('setup.php', []);
check(str_contains($html, 'r@uu.nl'), 'saved configuration shown in the paste box');
check(!str_contains($html, 'DAP123'), 'data access password never echoed');

// Re-saving "Connect your donations" with the config box and password left
// blank keeps both — the password field shows "saved ✓". The form no longer
// holds the study name or the daily check: both keep their saved values.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'IGNORED',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '', 'yoda_password' => ''], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda re-save with blank config and password accepted');
eq(inst_load()['study_name'], 'Example study', 'save_source ignores a posted study name');
eq(inst_load()['cadence'], 'daily', 'saving the source keeps the daily check');
eq(inst_source_load()['password'], 'DAP123', 'blank password keeps the saved DAP');
eq(inst_source_load()['env']['irods_user_name'] ?? null, 'r@uu.nl', 'blank config box keeps the saved config');

// An uploaded YAML file (the portal's "Download as YAML") wins over the box;
// same user, so a blank password still keeps the saved DAP.
$yml = $scratch2 . '-config.yml';
file_put_contents($yml, "irods_host: \"lp0555.its.uu.nl\"\nirods_port: 1247\nirods_home: \"/nluu10p/home\"\n"
    . "irods_user_name: \"r@uu.nl\"\nirods_zone_name: \"nluu10p\"\nirods_authentication_scheme: \"pam_password\"\n"
    . "irods_encryption_key_size: 32\n");
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Example study',
    'collection' => '/nluu10p/home/research-x', 'irods_config' => '{"not": "used"}', 'yoda_password' => ''],
    ['irods_config_file' => ['tmp_name' => $yml, 'error' => UPLOAD_ERR_OK]]);
eq($r['flash'][0]['kind'], 'ok', 'uploaded YAML config accepted');
eq(inst_source_load()['env']['irods_port'] ?? null, 1247, 'uploaded YAML parsed (port is an int)');
check(!isset(inst_source_load()['env']['irods_default_resource']), 'upload replaced the saved config');
eq(inst_source_load()['password'], 'DAP123', 'same user: blank password keeps the saved DAP');

// A configuration for a DIFFERENT user needs its own password.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Example study',
    'collection' => '/nluu10p/home/research-x',
    'irods_config' => str_replace('r@uu.nl', 'other@uu.nl', $cfgJson), 'yoda_password' => ''], []);
eq($r['flash'][0]['kind'], 'error', 'new user with blank password rejected');
eq(inst_source_load()['env']['irods_user_name'] ?? null, 'r@uu.nl', 'rejected save leaves the saved config alone');

// An incomplete paste is rejected with a pointer to the portal.
$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Example study',
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

// Analysis database: declared in Setup, stored under instance.json "analyses".
$base = ['action' => 'save_source', 'csrf' => csrf_token(), 'source_mode' => 'local',
    'study_name' => 'Local study', 'local_path' => '/tmp/some-donations'];
$r = inst_handle_setup_post($base + ['analysis_db_enabled' => '1',
    'analysis_db_path' => 'analysis/out/analysis.sqlite'], []);
eq($r['flash'][0]['kind'], 'ok', 'analysis database declaration saved');
eq(inst_load()['analyses']['database'], ['enabled' => true, 'path' => 'analysis/out/analysis.sqlite'], 'analysis database stored');
$r = inst_handle_setup_post($base + ['analysis_db_path' => 'analysis/out/analysis.sqlite'], []);
eq(inst_load()['analyses']['database'], ['enabled' => false, 'path' => 'analysis/out/analysis.sqlite'], 'unchecked box disables but keeps the path');
inst_handle_setup_post($base + ['analysis_db_enabled' => '1', 'analysis_db_path' => 'x/y.sqlite'], []);
inst_handle_setup_post($base, []);
eq(inst_load()['analyses']['database'], ['enabled' => true, 'path' => 'x/y.sqlite'], 're-save without the block keeps the stored values');
foreach (['../x.sqlite', '/abs.sqlite'] as $bad) {
    $r = inst_handle_setup_post($base + ['analysis_db_enabled' => '1', 'analysis_db_path' => $bad], []);
    eq($r['flash'][0]['kind'], 'error', "analysis path '$bad' rejected");
    check(str_contains($r['flash'][0]['text'], 'path'), "error for '$bad' names the path");
}
eq(inst_load()['analyses']['database']['path'], 'x/y.sqlite', 'rejected path leaves the stored one alone');
check(inst_valid_relative_path('a/b.sqlite'), 'relative path valid');
foreach (['', '/a', 'a/../b', '..', "a\0b", 'analysis/out/', 'a/'] as $bad) {
    check(!inst_valid_relative_path($bad), 'invalid relative path: ' . json_encode($bad));
}

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => csrf_token()], []);
check(is_file("$scratch2/state/refresh-requested"), 'refresh flag touched by handler');

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => 'nope'], []);
eq($r['flash'][0]['kind'], 'error', 'bad csrf rejected');

// An unticked daily-check box posts no "cadence" at all: that switches it off.
$r = inst_handle_setup_post(['action' => 'save_cadence', 'csrf' => csrf_token()], []);
eq(inst_load()['cadence'], 'off', 'daily check switched off');
eq(inst_load()['source_mode'], 'local', 'switching the daily check off keeps the source');

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
// Three steps that read as one path: describe the study, connect the
// donations, fetch. The name and the daily check each have their own form.
$p1 = strpos($html, 'Describe your study'); $p2 = strpos($html, 'Connect your donations'); $p3 = strpos($html, '>03</span> Fetch');
check($p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3, 'three steps in order');
check(!str_contains($html, 'flow builder'), 'the page says script selector, not flow builder');
check(str_contains($html, 'href="https://datadonation.eu/software/script-selector/" target="_blank" rel="noopener noreferrer"'), 'script selector linked');
check(substr_count($html, 'class="optional"') >= 2, 'the step and the flows part are marked optional');
check(preg_match('~name="action" value="remove_flow">\s*<input type="hidden" name="slug" value="tiktok">~', $html) === 1, 'each flow has a Remove form');
check(preg_match('~value="save_name">.*name="study_name".*value="save_source">~s', $html) === 1, 'the name has its own form, before the source form');
check(preg_match('~value="save_source">(?:(?!</form>).)*name="(study_name|cadence)"~s', $html) === 0, 'the source form holds neither name nor daily check');
check(preg_match('~value="save_cadence">(?:(?!</form>).)*name="cadence"~s', $html) === 1, 'the daily check has its own form');
check(!str_contains($html, 'Without a flow'), 'no "without a flow" line while a flow is stored');

// A body PHP refuses (over post_max_size) arrives as a POST with nothing in
// it. nginx lets through twice PHP's limit so that this, and not nginx's
// bare 413 page, is what an over-size upload usually meets.
$big = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string)(70 * 1024 * 1024)];
check(str_contains((string)inst_post_too_large($big, [], [], '64M'), '64 MB'), 'a refused body is reported as too large, with the limit');
eq(inst_post_too_large($big, ['action' => 'x'], [], '64M'), null, 'a post that arrived is not too large');
eq(inst_post_too_large(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '100'], [], [], '64M'), null, 'a small empty post is not too large');
eq(inst_post_too_large(['REQUEST_METHOD' => 'GET'], [], [], '64M'), null, 'GET is never too large');
check(str_contains((string)inst_post_too_large(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '3000000'], [], [], '2097152'), '2 MB'), 'a limit given in bytes is reported in MB');
eq(inst_post_too_large($big, [], [], '0'), null, 'post_max_size 0 means no limit');

// A flow uploaded by mistake can be removed; the message names what went.
$r = inst_handle_setup_post(['action' => 'remove_flow', 'csrf' => csrf_token(), 'slug' => 'tiktok'], []);
eq($r['flash'][0]['kind'], 'ok', 'flow removal accepted');
check(str_contains($r['flash'][0]['text'], 'TikTok'), 'removal message names the platform');
eq(flows_load_all(), [], 'no flows left');
$r = inst_handle_setup_post(['action' => 'remove_flow', 'csrf' => csrf_token(), 'slug' => 'tiktok'], []);
eq($r['flash'][0]['kind'], 'error', 'removing a flow twice is an error');

// The study setup exported from Next is uploaded in step 01, beside the flows.
$setupZip = $scratch2 . '-setup.zip';
make_setup_zip($setupZip, ['example/next-metadata.json' => (string)json_encode(example_setup_meta()),
    'example/ro-crate-metadata.json' => '{"@graph":[{"@id":"./","@type":"Dataset","name":"Example study","datePublished":"2026-11-21T17:33:42.034826Z"}]}',
    'example/assets/logo.png' => 'PNG', 'example/assets/document-1.pdf' => 'PDF']);
$upSetup = fn() => inst_handle_setup_post(['action' => 'upload_setup', 'csrf' => csrf_token()],
    ['setup_zip' => ['tmp_name' => $setupZip, 'error' => 0, 'name' => 'example_20261121.zip']])['flash'][0];
$_POST = [];
check(str_contains(render_page('setup.php', []), 'Without a study setup'), 'with no study setup, the page says what is missing');
// It fills an empty study name and never overwrites one.
inst_handle_setup_post(['action' => 'save_name', 'csrf' => csrf_token(), 'study_name' => ''], []);
$r = $upSetup();
eq($r['kind'], 'ok', 'study setup upload accepted');
check(str_contains($r['text'], 'Example study'), 'the upload message names the study');
eq(inst_load()['study_name'], 'Example study', 'an empty study name is filled from the study setup');
eq(inst_load()['source_mode'], 'local', 'filling the name keeps the source');
eq(setup_load()['uploaded']['zip_name'] ?? null, 'example_20261121.zip', 'the uploaded file name is recorded');
inst_handle_setup_post(['action' => 'save_name', 'csrf' => csrf_token(), 'study_name' => 'Mine'], []);
$upSetup();
eq(inst_load()['study_name'], 'Mine', 'a typed study name is never overwritten');
$_POST = [];
$html = render_page('setup.php', []);
check(preg_match('~<span class="flows__name">Example study</span>\s*exported 21 Nov 2026~', $html) === 1, 'the stored study setup is listed with its export date');
check(str_contains($html, 'href="https://next.eyra.co/" target="_blank" rel="noopener noreferrer"'), 'Next is linked');
check(str_contains($html, 'name="action" value="remove_setup"'), 'the study setup has a Remove form');
check(substr_count($html, 'class="optional"') >= 3, 'the step and both upload parts are marked optional');
$ps = strpos($html, '<h3>Study setup'); $pf = strpos($html, '<h3>Donation flows');
check($ps !== false && $pf !== false && $ps < $pf, 'the study setup part comes before the donation flows');
check(!str_contains($html, 'Without a study setup'), 'no "without" line while a study setup is stored');
// The two uploads are easy to mix up: each names the right place.
$r = inst_handle_setup_post(['action' => 'upload_flow', 'csrf' => csrf_token()], ['flow_zip' => ['tmp_name' => $setupZip, 'error' => 0]]);
eq($r['flash'][0]['kind'], 'error', 'a study setup is not accepted as a donation flow');
check(str_contains($r['flash'][0]['text'], 'Study setup'), 'the message points at the study setup part');
$r = inst_handle_setup_post(['action' => 'upload_setup', 'csrf' => csrf_token()], ['setup_zip' => ['tmp_name' => $zip, 'error' => 0]]);
eq($r['flash'][0]['kind'], 'error', 'a donation flow is not accepted as a study setup');
check(str_contains($r['flash'][0]['text'], 'Donation flows'), 'the message points at the donation flows part');
check(setup_load() !== null, 'a refused upload leaves the stored study setup alone');
$r = inst_handle_setup_post(['action' => 'upload_setup', 'csrf' => csrf_token()], []);
eq($r['flash'][0]['kind'], 'error', 'an upload without a file is an error');
// Removing it leaves the name alone.
$r = inst_handle_setup_post(['action' => 'remove_setup', 'csrf' => csrf_token()], []);
eq($r['flash'][0]['kind'], 'ok', 'study setup removal accepted');
eq(setup_load(), null, 'the study setup is gone');
eq(inst_load()['study_name'], 'Mine', 'removing the study setup keeps the study name');
$r = inst_handle_setup_post(['action' => 'remove_setup', 'csrf' => csrf_token()], []);
eq($r['flash'][0]['kind'], 'error', 'removing the study setup twice is an error');
// Before any source exists, a study setup can already be uploaded: it names
// the study without making the instance configured.
@unlink("$scratch2/config/instance.json");
$upSetup();
eq(inst_load()['study_name'] ?? null, 'Example study', 'a study setup names a brand-new instance');
eq(inst_configured(), false, 'a study setup alone does not make the instance configured');
inst_handle_setup_post(['action' => 'remove_setup', 'csrf' => csrf_token()], []);

// An instance.json written by the old Setup (every key it ever wrote) keeps
// all of it when only the name or only the daily check is saved: a save
// that wrote just its own key would unconfigure a live workspace.
$oldStyle = ['study_name' => 'Old', 'source_mode' => 'yoda', 'local_path' => null, 'cadence' => 'daily',
    'default_n' => 20, 'analysis_dirs' => ['transcripts' => 'transcripts'],
    'analyses' => ['database' => ['enabled' => true, 'path' => 'a/b.sqlite']]];
inst_save($oldStyle);
inst_handle_setup_post(['action' => 'save_name', 'csrf' => csrf_token(), 'study_name' => 'New'], []);
check(inst_load() == ['study_name' => 'New'] + $oldStyle, 'saving the name changes nothing else');
inst_handle_setup_post(['action' => 'save_cadence', 'csrf' => csrf_token()], []);
check(inst_load() == ['study_name' => 'New', 'cadence' => 'off'] + $oldStyle, 'saving the daily check changes nothing else');
check(inst_configured(), 'the instance is still configured after both');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_setup'];
