<?php
putenv('DDP_INSPECTOR_CONFIG=' . __DIR__ . '/config.test.php');
require_once __DIR__ . '/../src/bootstrap.php';

eq(cfg('default_n'), 15, 'config default_n loaded');
eq(h('<a>&"'), '&lt;a&gt;&amp;&quot;', 'h() escapes');
eq(url('participant.php?id=x'), 'participant.php?id=x', 'url() with empty base_path');
eq(fmt_ts(null), '—', 'fmt_ts null');
eq(fmt_ts(gmmktime(11,53,55,7,5,2026)), '2026-07-05 11:53', 'fmt_ts formats UTC');

// --- index.php ---
function render_page(string $script, array $get): string {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include __DIR__ . '/../public/' . $script;
    return ob_get_clean();
}

$html = render_page('index.php', []);
check(str_contains($html, 'p1'), 'index lists participant p1');
check(str_contains($html, 'participant.php?id=p1'), 'index links to participant page');
check(str_contains($html, 'tiktok'), 'index shows platform');
check(str_contains($html, 'skipped'), 'index shows skipped-file notice');
check(!str_contains($html, 'participant.php?id=preview'), 'index does not list skipped preview participant');

// --- participant.php ---
$html = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '1']);
check(str_contains($html, 'tiktok_watch_history') || str_contains($html, 'Tiktok watch history'), 'participant shows watch history table');
check(str_contains($html, 'Igual estoy yo') || str_contains($html, 'second comment'), 'comment text rendered generically');
check(str_contains($html, 'transcript.php?vid=7654562293757250829'), 'video row links to transcript via analysis module');
check(str_contains($html, 'seed=2'), 'reshuffle link bumps seed');
check(str_contains($html, '<h2>') && str_contains(strtolower($html), 'tiktok'), 'platform heading present');

$missing = render_page('participant.php', ['id' => 'nope']);
check(str_contains($missing, 'not found') || str_contains($missing, '404'), 'unknown participant -> not found');

// --- transcript.php ---
$ok = render_page('transcript.php', ['vid' => '7654562293757250829']);
check(str_contains($ok, 'Hello world this is a test'), 'transcript text rendered');
check(str_contains($ok, '0.42') || str_contains($ok, 'low'), 'low-confidence segment surfaced');
check(str_contains($ok, '<td>Hello world</td>'), 'segment text derived from tokens, marker tokens skipped');
check(str_contains($ok, '<td>this is a test</td>'), 'derived segment text trimmed of leading token space');

$none = render_page('transcript.php', ['vid' => '1111111111111111111']);
check(str_contains(strtolower($none), 'not transcribed'), 'missing transcript -> not transcribed');

$bad = render_page('transcript.php', ['vid' => '../etc/passwd']);
check(str_contains($bad, '400') || str_contains($bad, 'invalid'), 'bad vid rejected');

// --- storage mode: freshness header/button, doc-matched titles, deleted-rows note,
//     superseded note, empty-inbox friendly state ---
$storageScratch = sys_get_temp_dir() . '/ddp-inspector-pages-storage-' . getmypid();
exec('rm -rf ' . escapeshellarg($storageScratch));
@mkdir("$storageScratch/data/inbox", 0755, true);
@mkdir("$storageScratch/config/flows/tiktok", 0755, true);
copy(__DIR__ . '/fixtures/flows/tiktok/documentation.txt', "$storageScratch/config/flows/tiktok/documentation.txt");

// spA: a table whose columns match the "Videos you watched" doc section, with a
// nonzero "deleted row count" so the removed-rows note renders.
file_put_contents(
    "$storageScratch/data/inbox/assignment=1_task=1_participant=spA_source=tiktok_key=1-tiktok.json",
    json_encode([
        ['deleted row count' => 3, 'tiktok_watch_history' => [
            ['Date' => '2026-02-01 10:00:00', 'Link' => 'https://www.tiktokv.com/share/video/7000000000000000001/'],
        ]],
    ])
);

// spB: two files for the same participant+source -> the older one is superseded.
file_put_contents(
    "$storageScratch/data/inbox/assignment=1_task=1_participant=spB_source=tiktok_key=1-tiktok.json",
    json_encode([
        ['deleted row count' => 0, 'tiktok_watch_history' => [
            ['Date' => '2026-01-01 10:00:00', 'Link' => 'https://www.tiktokv.com/share/video/7000000000000000002/'],
        ]],
    ])
);
file_put_contents(
    "$storageScratch/data/inbox/assignment=1_task=1_participant=spB_source=tiktok_key=2-tiktok.json",
    json_encode([
        ['deleted row count' => 0, 'tiktok_watch_history' => [
            ['Date' => '2026-03-01 10:00:00', 'Link' => 'https://www.tiktokv.com/share/video/7000000000000000003/'],
        ]],
    ])
);

$__saved_cfg_storage = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $storageScratch, 'default_n' => 15, 'base_path' => ''];
inst_save(['study_name' => 'Storage-mode pages test', 'source_mode' => 'local',
           'local_path' => "$storageScratch/data/inbox", 'cadence' => 'off', 'default_n' => 15]);
inst_write_json_atomic("$storageScratch/state/refresh-status.json",
    ['phase' => 'done', 'started_at' => '2026-07-29T10:00:00Z', 'finished_at' => '2026-07-29T10:05:00Z',
     'donations' => 3, 'message' => '']);

$storageIndex = render_page('index.php', []);
check(str_contains($storageIndex, 'Last updated 2026-07-29T10:05:00Z'), 'storage-mode index shows freshness header');
check(str_contains($storageIndex, '3 donation file(s)'), 'storage-mode index shows donation count');
check(str_contains($storageIndex, '<form method="post" action="setup.php">'), 'storage-mode index has the refresh-donations form');
check(str_contains($storageIndex, 'name="csrf"'), 'storage-mode refresh form carries a csrf field');
check(str_contains($storageIndex, 'Check for new donations'), 'storage-mode index shows the refresh button');

$storageParticipantA = render_page('participant.php', ['id' => 'spA', 'seed' => '1', 'n' => '5']);
check(str_contains($storageParticipantA, 'Videos you watched'), 'doc-matched section title rendered');
check(str_contains($storageParticipantA, 'Watch history from your TikTok account.'), 'doc-matched section description rendered');
check(str_contains($storageParticipantA, 'removed') && str_contains($storageParticipantA, 'row(s) before donating'),
      'deleted-rows note rendered');

$storageParticipantB = render_page('participant.php', ['id' => 'spB', 'seed' => '1', 'n' => '5']);
check(str_contains($storageParticipantB, 'donated more than once for this platform'), 'superseded note rendered');

// Separate scratch instance: configured, but nothing donated yet -> friendly empty state.
$emptyScratch = sys_get_temp_dir() . '/ddp-inspector-pages-empty-' . getmypid();
exec('rm -rf ' . escapeshellarg($emptyScratch));
@mkdir("$emptyScratch/data/inbox", 0755, true);
$GLOBALS['__cfg'] = ['storage_root' => $emptyScratch, 'default_n' => 15, 'base_path' => ''];
inst_save(['study_name' => 'Empty inbox', 'source_mode' => 'local', 'local_path' => "$emptyScratch/data/inbox",
           'cadence' => 'off', 'default_n' => 15]);
$emptyIndex = render_page('index.php', []);
check(str_contains($emptyIndex, 'No donations yet'), 'empty inbox shows friendly empty state');
check(!str_contains($emptyIndex, '<table'), 'empty inbox renders no participant table');

$GLOBALS['__cfg'] = $__saved_cfg_storage;
exec('rm -rf ' . escapeshellarg($storageScratch));
exec('rm -rf ' . escapeshellarg($emptyScratch));

// --- cfg_ready: config-missing path (cfg_ready() failure -> guard_configured() is never
//     reached; this is a distinct code path from the guard test below) ---
$__saved_cfg = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = null;
$missingCfg = render_page('index.php', []);
check(str_contains($missingCfg, 'Configuration missing'), 'missing config (cfg_ready() false) shows friendly message');
check(!str_contains($missingCfg, '<table'), 'missing config does not render the participant table');
$GLOBALS['__cfg'] = $__saved_cfg;

// --- guard_configured(): cfg is ready but the storage instance has no instance.json yet
//     -> guard_configured() itself blocks the page (distinct from the cfg_ready path above) ---
$guardScratch = sys_get_temp_dir() . '/ddp-inspector-pages-guard-' . getmypid();
exec('rm -rf ' . escapeshellarg($guardScratch));
$__saved_cfg_guard = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $guardScratch, 'default_n' => 15, 'base_path' => ''];
$guarded = render_page('index.php', []);
check(str_contains($guarded, 'not set up yet'), 'unconfigured storage instance blocked by guard_configured()');
check(str_contains($guarded, 'setup.php'), 'guard message links to setup.php');
check(!str_contains($guarded, '<table'), 'guard-blocked page renders no participant table');
$GLOBALS['__cfg'] = $__saved_cfg_guard;
exec('rm -rf ' . escapeshellarg($guardScratch));

// §7 ground-truth guard: the generic path must reproduce exact per-table row
// counts. Uses the synthetic examples/ dataset when present (regenerable via
// examples/generate.py; counts fixed by its seed).
$examples = __DIR__ . '/../examples/ddp';
if (is_dir($examples)) {
    $ex = ddp_load_dir($examples);
    $one = $ex['participants']['1bf78505c54e3c4ce201abd7']['platforms']['tiktok']['tables'] ?? null;
    check($one !== null, 'examples participant loads under tiktok platform');
    if ($one !== null) {
        eq(count($one['tiktok_watch_history']), 400, 'ground truth: watch rows');
        eq(count($one['tiktok_favorite_videos']), 12, 'ground truth: favorites rows');
        eq(count($one['tiktok_like_list']), 80, 'ground truth: likes rows');
        eq(count($one['tiktok_share_history']), 5, 'ground truth: shares rows');
        eq(count($one['tiktok_comments']), 8, 'ground truth: comments rows');
    }
}
