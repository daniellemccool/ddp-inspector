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
// The shared head and header (page_top): the design tokens before the stylesheet,
// the current section marked, and no Settings link outside storage mode.
check(preg_match('~assets/tokens\.css\?v=\d+">\s*<link rel="stylesheet" href="assets/style\.css\?v=\d+">~', $html) === 1,
      'pages load the design tokens, then the stylesheet');
check(str_contains($html, '<li class="site-nav__item is-active"><a href="index.php" aria-current="true">Participants</a></li>'),
      'header marks Participants as the current section');
check(!str_contains($html, 'setup.php'), 'dev-mode header has no Settings link');
check(!str_contains($html, 'study.php'), 'dev-mode header has no Study link');
// The title band: the page's one <h1> sits in it, before the page's own content.
check(preg_match('~<div class="band">.*<h1>Participants</h1>.*</div>\s*<div class="wrap">~s', $html) === 1
      && substr_count($html, '<h1') === 1, 'the page title is in the band, once');
check(str_ends_with(rtrim($html), "</div>\n</main>"), 'page_bottom closes the page');

// --- participant.php ---
$html = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '1']);
check(str_contains($html, 'tiktok_watch_history') || str_contains($html, 'Tiktok watch history'), 'participant shows watch history table');
check(str_contains($html, 'Igual estoy yo') || str_contains($html, 'second comment'), 'comment text rendered generically');
check(str_contains($html, 'transcript.php?vid=7000000000000000029'), 'video row links to transcript via analysis module');
check(str_contains($html, 'seed=2'), 'reshuffle link bumps seed');
check(str_contains($html, '<h2>') && str_contains(strtolower($html), 'tiktok'), 'platform heading present');

$missing = render_page('participant.php', ['id' => 'nope']);
check(str_contains($missing, 'not found') || str_contains($missing, '404'), 'unknown participant -> not found');

// --- transcript.php ---
$ok = render_page('transcript.php', ['vid' => '7000000000000000029']);
check(str_contains($ok, 'Hello world this is a test'), 'transcript text rendered');
check(str_contains($ok, '0.42') || str_contains($ok, 'low'), 'low-confidence segment surfaced');
check(str_contains($ok, '<td>Hello world</td>'), 'segment text derived from tokens, marker tokens skipped');
check(str_contains($ok, '<td>this is a test</td>'), 'derived segment text trimmed of leading token space');
check(str_contains($ok, 'Fixture video about testing'), 'video metadata: description rendered');
check(str_contains($ok, 'it&#039;s great'), 'caption apostrophe survives as one entity');
check(!str_contains($ok, '<span class="tag">#039'), 'hashtag muting must not match inside h() entities');
check(str_contains($ok, '<span class="tag">#test</span>'), 'real hashtags still muted');
check(str_contains($ok, '@fixtureuser'), 'video metadata: uploader rendered');
check(str_contains($ok, '<dt>Posted</dt><dd>19 Apr 2026, 10:28</dd>'), 'video metadata: pretty date');
check(str_contains($ok, '<dt>Views</dt><dd><span title="11,400,000">11.4M</span></dd>')
      && str_contains($ok, '<dt>Likes</dt><dd><span title="1,100,000">1.1M</span></dd>')
      && str_contains($ok, '<dt>Comments</dt><dd><span title="3,181">3,181</span></dd>'),
      'video metadata: compact counts');
check(str_contains($ok, 'title="11,400,000"'), 'video metadata: full count in title attr');
check(str_contains($ok, 'English'), 'video metadata: language name');
check(preg_match('~<a href="https?://[^"]+" rel="noreferrer noopener" target="_blank">Watch on TikTok~', $ok) === 1,
      'video metadata: source url clickable');

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
check(preg_match('~<div class="wrap">\s*<div class="toolbar niche">~', $storageIndex) === 1,
      'the refresh row is the first thing after the band, so it sits in the niche');
check(str_contains($storageIndex, '<span class="site-header__subtitle">Storage-mode pages test</span>'),
      'storage-mode header shows the study name under the product name');
check(str_contains($storageIndex, '<li class="site-nav__item"><a href="setup.php">Settings</a></li>'),
      'storage-mode header links to Settings');

$storageParticipantA = render_page('participant.php', ['id' => 'spA', 'seed' => '1', 'n' => '5']);
check(str_contains($storageParticipantA, 'Videos you watched'), 'doc-matched section title rendered');
check(str_contains($storageParticipantA, 'Watch history from your TikTok account.'), 'doc-matched section description rendered');
check(str_contains($storageParticipantA, 'removed') && str_contains($storageParticipantA, 'row(s) before donating'),
      'deleted-rows note rendered');

$storageParticipantB = render_page('participant.php', ['id' => 'spB', 'seed' => '1', 'n' => '5']);
check(str_contains($storageParticipantB, 'donated more than once for this platform'), 'superseded note rendered');
$bigN = render_page('participant.php', ['id' => 'spA', 'seed' => '1', 'n' => '100000']);
check(str_contains($bigN, 'n=500&amp;') && !str_contains($bigN, 'n=100000'), 'raw participant page: sample size clamped to 500');

// Database mode index: donated participants listed, the other two set aside.
$dbScratch = sys_get_temp_dir() . '/ddp-inspector-pages-db-' . getmypid();
exec('rm -rf ' . escapeshellarg($dbScratch));
@mkdir("$dbScratch/data/analyses", 0755, true);
$GLOBALS['__cfg'] = ['storage_root' => $dbScratch, 'default_n' => 15, 'base_path' => ''];
inst_save(['study_name' => 'Db index', 'source_mode' => 'local', 'local_path' => "$dbScratch/data/inbox",
           'cadence' => 'off', 'default_n' => 15,
           'analyses' => ['database' => ['enabled' => true, 'path' => 'analysis.sqlite']]]);
(new PDO("sqlite:$dbScratch/data/analyses/analysis.sqlite"))->exec((string)file_get_contents(__DIR__ . "/fixtures/analysis.sql"));
study_reset();
$dbIndex = render_page('index.php', []);
check(str_contains($dbIndex, '>pa<') || str_contains($dbIndex, 'pa</a>'), 'db index lists pa');
check(str_contains($dbIndex, 'consented, did not donate'), 'db index labels the not-donated set-aside kind');
check(str_contains($dbIndex, 'declined consent'), 'db index labels the declined-consent kind');
check(str_contains($dbIndex, '3 donation(s)') && !str_contains($dbIndex, 'donation file(s)'), 'db index counts donations, not donation files');
$dbBigN = render_page('participant.php', ['id' => 'pa', 'seed' => '1', 'n' => '100000']);
check(str_contains($dbBigN, 'n=500&amp;') && !str_contains($dbBigN, 'n=100000'), 'db participant page: sample size clamped to 500');
$dbPage = render_page('participant.php', ['id' => 'pa', 'seed' => '1', 'n' => '15']);
check(str_contains($dbPage, 'transcript.php?vid=7000000000000001001'), 'db participant page links the transcribed video');
check(!str_contains($dbPage, 'removed or private'), 'db participant page hides untranscribed rows by default');
$dbPageAll = render_page('participant.php', ['id' => 'pa', 'seed' => '1', 'n' => '15', 'all' => '1']);
check(str_contains($dbPageAll, '<span class="reason">removed or private</span>'), 'db participant page renders a reason text');
check(str_contains($dbPageAll, 'example.test/v/unresolved'), 'db participant page shows the NULL-video row');
$dbPagePb = render_page('participant.php', ['id' => 'pb', 'seed' => '1', 'n' => '15']);
check(str_contains($dbPagePb, 'submitted 2 files; the first one with data is shown'), 'db participant page shows the repeat-submission note');
$dbTx = render_page('transcript.php', ['vid' => '7000000000000001001']);
check(str_contains($dbTx, 'Hello world'), 'db transcript page: text');
check(str_contains($dbTx, 'Segment confidence') && str_contains($dbTx, '0.75'), 'db transcript page: segment confidence');
check(str_contains($dbTx, '@uploader_a') && str_contains($dbTx, 'Watch on TikTok'), 'db transcript page: metadata');
$dbTxGated = render_page('transcript.php', ['vid' => '7000000000000001004']);
check(str_contains($dbTxGated, 'not available in this region') && !str_contains(strtolower($dbTxGated), 'not transcribed yet'), 'db transcript page: untranscribed reason');
$dbTxNone = render_page('transcript.php', ['vid' => '1111111111111111111']);
check(str_contains(strtolower($dbTxNone), 'not transcribed yet'), 'db transcript page: unknown id');
check(str_contains(render_page('transcript.php', ['vid' => '../etc/passwd']), '400'), 'db transcript page: malformed id -> 400');
$dbMissing = render_page('participant.php', ['id' => 'nobody']);
check(str_contains($dbMissing, 'not found') || str_contains($dbMissing, '404'), 'db participant page: unknown id -> not found');
study_reset();
exec('rm -rf ' . escapeshellarg($dbScratch));

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

// --- analysis database declared but not usable: pages show only the notice,
//     never the raw donations or the old transcripts tree (ruling R11) ---
$noticeScratch = sys_get_temp_dir() . '/ddp-inspector-pages-notice-' . getmypid();
exec('rm -rf ' . escapeshellarg($noticeScratch));
@mkdir("$noticeScratch/data/inbox", 0755, true);
copy(__DIR__ . '/fixtures/ddp/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json',
     "$noticeScratch/data/inbox/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json");
@mkdir("$noticeScratch/data/analyses/transcripts/29", 0755, true);
copy(__DIR__ . '/fixtures/transcripts/29/7000000000000000029.txt', "$noticeScratch/data/analyses/transcripts/29/7000000000000000029.txt");
$__saved_cfg_notice = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $noticeScratch, 'default_n' => 15, 'base_path' => ''];
inst_save(['study_name' => 'Notice test', 'source_mode' => 'local', 'local_path' => "$noticeScratch/data/inbox",
           'cadence' => 'off', 'default_n' => 15,
           'analyses' => ['database' => ['enabled' => true, 'path' => 'analysis.sqlite']]]);
study_reset();
$noticeIndex = render_page('index.php', ['all' => '1']);
check(str_contains($noticeIndex, '<p class="notice">The analysis database hasn&#039;t been fetched yet'),
      'declared database missing -> index shows the not-fetched notice');
check(!str_contains($noticeIndex, 'participant.php?id=p1'), 'declared database missing -> index lists no raw donations');
check(!str_contains($noticeIndex, 'No donations yet'), 'declared database missing -> no empty-inbox text under the notice');
check(!str_contains($noticeIndex, 'donation file(s)'), 'declared database missing -> no raw donation-file count');
$noticeParticipant = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '5']);
check(str_contains($noticeParticipant, '<p class="notice">The analysis database hasn&#039;t been fetched yet'),
      'declared database missing -> participant page shows the not-fetched notice');
check(!str_contains($noticeParticipant, 'Participant not found') && !str_contains($noticeParticipant, 'tiktok_watch_history')
      && !str_contains($noticeParticipant, 'Tiktok watch history'), 'declared database missing -> participant page shows only the notice');
$noticeTx = render_page('transcript.php', ['vid' => '7000000000000000029']);
check(str_contains($noticeTx, '<p class="notice">The analysis database hasn&#039;t been fetched yet'),
      'declared database missing -> transcript page shows the not-fetched notice');
check(!str_contains($noticeTx, 'Hello world') && !str_contains(strtolower($noticeTx), 'not transcribed yet'),
      'declared database missing -> transcript page reads nothing from the old transcripts tree');
@mkdir("$noticeScratch/data/analyses", 0755, true);
file_put_contents("$noticeScratch/data/analyses/analysis.sqlite", 'not a database');
$noticeIndex = render_page('index.php', []);
check(str_contains($noticeIndex, '<p class="notice">The analysis database doesn&#039;t look like a pipeline analysis database.'),
      'declared database with the wrong shape -> index shows the shape notice');
check(!str_contains($noticeIndex, 'participant.php?id=p1'), 'declared database with the wrong shape -> index lists no raw donations');
$noticeParticipant = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '5']);
check(str_contains($noticeParticipant, 'doesn&#039;t look like a pipeline analysis database')
      && !str_contains($noticeParticipant, 'Participant not found') && !str_contains($noticeParticipant, 'Tiktok watch history'),
      'declared database with the wrong shape -> participant page shows only the notice');
$GLOBALS['__cfg'] = $__saved_cfg_notice;
study_reset();
exec('rm -rf ' . escapeshellarg($noticeScratch));
$plainIndex = render_page('index.php', []);
check(!str_contains($plainIndex, 'analysis database'), 'no database declared -> no notice');

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
