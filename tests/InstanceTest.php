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

// Failure path: parent segment is an existing plain file, so mkdir cannot create it.
$blocker = "$scratch/blocker-file";
file_put_contents($blocker, 'x');
$blocked_target = "$blocker/x.json";
eq(inst_write_json_atomic($blocked_target, ['a' => 1]), false, 'atomic write fails when parent path is blocked by a file');
check(!file_exists($blocked_target), 'blocked write creates no target file');
check(!file_exists($blocked_target . '.tmp'), 'blocked write leaves no temp file');

eq(inst_status()['phase'], 'idle', 'status defaults to idle');
inst_write_json_atomic("$scratch/state/refresh-status.json",
    ['phase' => 'done', 'started_at' => '2026-07-29T10:00:00Z', 'finished_at' => '2026-07-29T10:05:00Z',
     'donations' => 142, 'message' => 'ok']);
eq(inst_status()['donations'], 142, 'status file read');

eq(inst_touch_refresh(), true, 'refresh flag touched');
check(is_file("$scratch/state/refresh-requested"), 'flag file exists');

file_put_contents("$scratch/state/refresh.log", "a\nb\nc\n");
eq(inst_log_tail(2), "b\nc", 'log tail returns last lines');

@mkdir("$scratch/data/inbox", 0755, true);
file_put_contents("$scratch/data/inbox/assignment=1_task=1_participant=z_source=tiktok_key=1-tiktok.json", '[]');
eq(inst_donation_count(), 1, 'donation count');

inst_save(['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => "$scratch/data/inbox",
           'cadence' => 'off', 'default_n' => 15]);
$probe = inst_probe();
eq($probe['ok'], true, 'local probe ok');
eq($probe['count'], 1, 'local probe counts donations');

inst_save(['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/nonexistent/nope',
           'cadence' => 'off', 'default_n' => 15]);
$probe = inst_probe();
eq($probe['ok'], false, 'local probe fails on missing dir');
check(!str_contains(strtolower($probe['message']), 'rclone'), 'probe messages stay plain-language');

// yoda probe with a guaranteed-missing binary degrades gracefully
$GLOBALS['__cfg']['gocmd_bin'] = '/nonexistent/gocmd-binary';
inst_save(['study_name' => 'Pilot', 'source_mode' => 'yoda', 'local_path' => null,
           'cadence' => 'off', 'default_n' => 15]);
inst_source_save(['mode' => 'yoda', 'collection' => '/nluu10p/home/x',
                  'host' => 'fsw.data.uu.nl', 'zone' => 'nluu10p', 'ticket' => 'T']);
$probe = inst_probe();
eq($probe['ok'], false, 'missing binary -> not ok');
check(str_contains($probe['message'], 'unavailable'), 'missing binary -> unavailable message');

// yoda probe uses the live-verified gocmd form: -c <dir with env json>, -T ticket, i: prefix
$fakeg = "$scratch/fake-gocmd";
$glog = "$scratch/gocmd-args.log";
$gcopy = "$scratch/gocmd-env-copy.json";
file_put_contents($fakeg,
    "#!/bin/sh\nprintf '%s' \"\$*\" > " . escapeshellarg($glog) . "\n"
    . "[ \"\$1\" = -c ] || exit 3\n"
    . "[ -f \"\$2/irods_environment.json\" ] || exit 4\n"
    . "cp \"\$2/irods_environment.json\" " . escapeshellarg($gcopy) . "\nexit 0\n");
chmod($fakeg, 0755);
$GLOBALS['__cfg']['gocmd_bin'] = $fakeg;
$probe = inst_probe();
eq($probe['ok'], true, 'yoda probe ok via verified invocation form');
$gargs = (string)file_get_contents($glog);
check(str_contains($gargs, '-T T'), 'yoda probe passes the ticket via -T');
check(str_contains($gargs, 'ls i:/nluu10p/home/x'), 'yoda probe lists the i:-prefixed collection');
$genv = json_decode((string)file_get_contents($gcopy), true);
eq($genv['irods_user_name'] ?? null, 'anonymous', 'env json: anonymous user');
eq($genv['irods_client_zone_name'] ?? null, 'nluu10p', 'env json: client zone set');
eq($genv['irods_authentication_scheme'] ?? null, 'native', 'env json: native auth scheme');
check(!isset($genv['irods_ticket']), 'env json carries no ticket (goes via -T)');

// rd-link probe: modern endpoint fails -> legacy fallback succeeds -> source.json rewritten
$fake = "$scratch/fake-rclone";
file_put_contents($fake,
    "#!/bin/sh\ncase \"\$*\" in\n  *obscure*) echo OBSCURED; exit 0;;\n  *'/public.php/dav/files/'*) exit 1;;\n  *) exit 0;;\nesac\n");
chmod($fake, 0755);
$GLOBALS['__cfg']['rclone_bin'] = $fake;
inst_save(['study_name' => 'RD', 'source_mode' => 'rd-link', 'local_path' => null,
           'cadence' => 'off', 'default_n' => 15]);
inst_source_save(['mode' => 'rd-link',
                  'webdav_url' => 'https://uu.data.surf.nl/public.php/dav/files/TOK/',
                  'share_token' => 'TOK', 'password' => 'pw']);
$probe = inst_probe();
eq($probe['ok'], true, 'rd-link probe falls back to legacy endpoint');
eq(inst_source_load()['webdav_url'], 'https://uu.data.surf.nl/public.php/webdav/',
   'source.json rewritten with working legacy url');

// both endpoint forms failing -> friendly error, source.json untouched
file_put_contents($fake, "#!/bin/sh\ncase \"\$*\" in *obscure*) echo OBSCURED; exit 0;; *) exit 1;; esac\n");
$probe = inst_probe();
eq($probe['ok'], false, 'rd-link probe fails when both endpoint forms fail');
eq(inst_source_load()['webdav_url'], 'https://uu.data.surf.nl/public.php/webdav/',
   'failed probe does not rewrite source.json');

$_COOKIE['ddpi_csrf'] = '';
$tok = csrf_token();
check(strlen($tok) >= 32, 'csrf token generated');
eq(csrf_token(), $tok, 'csrf token stable within request');
$_POST['csrf'] = $tok;
eq(csrf_ok(), true, 'matching token accepted');
$_POST['csrf'] = 'wrong';
eq(csrf_ok(), false, 'wrong token rejected');
check(str_contains(csrf_field(), $tok), 'csrf field embeds token');
$_POST = [];

// guard: unconfigured storage mode -> setup pointer, no crash
inst_save(['study_name' => '', 'source_mode' => 'local', 'local_path' => null, 'cadence' => 'off', 'default_n' => 15]);
@unlink("$scratch/config/instance.json");
ob_start(); $ok = guard_configured(); $out = ob_get_clean();
eq($ok, false, 'guard blocks unconfigured instance');
check(str_contains($out, 'setup.php'), 'guard points at setup');

// donation-summary disk cache: hit on (mtime,size) match, recompute on change
$fixture = __DIR__ . '/fixtures/ddp/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json';
$sum1 = ddp_summarize_file_cached($fixture);
check(is_array($sum1) && $sum1['participant'] === 'p1', 'summary computed in storage mode');
$cacheFile = "$scratch/cache/stats/" . basename($fixture) . '.stats.json';
check(is_file($cacheFile), 'summary cache file written');
$c = inst_read_json($cacheFile);
$c['summary']['participant'] = 'sentinel-from-cache';
inst_write_json_atomic($cacheFile, $c);
eq(ddp_summarize_file_cached($fixture)['participant'], 'sentinel-from-cache', 'unchanged file served from cache');
$c['_mtime'] = 1;
inst_write_json_atomic($cacheFile, $c);
eq(ddp_summarize_file_cached($fixture)['participant'], 'p1', 'stale cache recomputed');

// coverage fingerprint: same file, changed transcript state -> recompute
$ctxA = ['ids' => [], 'fp' => 'fpA'];
ddp_summarize_file_cached($fixture, $ctxA);
$c2 = inst_read_json($cacheFile);
eq($c2['_ctx_fp'] ?? null, 'fpA', 'ctx fingerprint stored in cache');
$c2['summary']['participant'] = 'sentinel-ctx';
inst_write_json_atomic($cacheFile, $c2);
eq(ddp_summarize_file_cached($fixture, $ctxA)['participant'], 'sentinel-ctx', 'matching fingerprint served from cache');
eq(ddp_summarize_file_cached($fixture, ['ids' => [], 'fp' => 'fpB'])['participant'], 'p1', 'changed fingerprint recomputed');

// local-folder candidate discovery (bounded scan, own tree excluded)
$scan = $scratch . '/scan';
mkdir("$scan/vol1/inbox", 0777, true);
mkdir("$scan/vol1/ddp-inspector", 0777, true);
mkdir("$scan/vol2", 0777, true);
mkdir("$scan/rdbylink", 0777, true);
file_put_contents("$scan/vol1/inbox/a.json", '{}');
file_put_contents("$scan/rdbylink/b.json", '{}');
file_put_contents("$scan/vol1/ddp-inspector/self.json", '{}');
$GLOBALS['__cfg']['storage_root'] = "$scan/vol1/ddp-inspector";
eq(inst_local_folder_candidates($scan), ["$scan/rdbylink", "$scan/vol1/inbox"],
   'candidates: donation folders found, empty vol skipped, own tree excluded');
eq(inst_local_folder_candidates("$scan/does-not-exist"), [], 'missing scan root -> no candidates');
$GLOBALS['__cfg']['storage_root'] = $scratch;

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_inst'];
