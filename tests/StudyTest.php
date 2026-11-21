<?php
// Data layer core: declaration, file location, status/shape check, read-only open.
require_once __DIR__ . '/../src/bootstrap.php';

function study_test_build_db(string $file): void {
    @unlink($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec((string)file_get_contents(__DIR__ . '/fixtures/analysis.sql'));
}

$sc = sys_get_temp_dir() . '/ddp-inspector-study-' . getmypid();
exec('rm -rf ' . escapeshellarg($sc));
$saved_study_cfg = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $sc, 'default_n' => 15, 'base_path' => ''];
@mkdir("$sc/data/analyses", 0755, true);
$dbfile = "$sc/data/analyses/analysis.sqlite";
$base = ['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/tmp/x', 'cadence' => 'off', 'default_n' => 15];

inst_save($base);
eq(study_db_decl(), null, 'no declaration -> null');
eq(study_db_status(), 'off', 'status off without declaration');
eq(study_mode(), 'raw', 'mode raw when off');
eq(study_db_notice(), null, 'no notice when off');
eq(study_db(), null, 'no connection when off');

inst_save($base + ['analyses' => ['database' => ['enabled' => false, 'path' => 'x.sqlite']]]);
eq(study_db_status(), 'off', 'disabled declaration is off');

inst_save($base + ['analyses' => ['database' => ['enabled' => true, 'path' => 'x.sqlite']]]);
eq(study_db_decl(), ['path' => 'x.sqlite'], 'enabled declaration returns path');
eq(study_db_file(), $dbfile, 'storage-mode file location');
eq(study_db_status(), 'missing', 'declared, no file -> missing');
eq(study_mode(), 'raw', 'mode raw when missing');
eq(study_db_notice(), "The analysis database hasn't been fetched yet — run Check & fetch in Settings.", 'missing notice');
eq(study_db(), null, 'no connection when missing');

touch($dbfile);
eq(study_db_status(), 'invalid', 'empty file -> invalid');
$e = new PDO('sqlite:' . $dbfile); $e->exec('CREATE TABLE unrelated (a)');
eq(study_db_status(), 'invalid', 'wrong shape -> invalid');
eq(study_mode(), 'raw', 'mode raw when invalid');
eq(study_db_notice(), "The analysis database doesn't look like a pipeline analysis database.", 'invalid notice');
$e = null;

study_test_build_db($dbfile);
eq(study_db_status(), 'ok', 'fixture -> ok');
eq(study_mode(), 'database', 'mode database when ok');
eq(study_db_notice(), null, 'no notice when ok');

$db = study_db();
check($db instanceof PDO, 'study_db returns a PDO when ok');
eq(study_db(), $db, 'one connection per request');
eq((int)$db->query('SELECT COUNT(*) FROM participant')->fetchColumn(), 5, 'fixture readable through study_db');
$threw = false;
try { $db->exec("INSERT INTO meta VALUES ('x', 'y')"); } catch (Throwable $t) { $threw = true; }
eq($threw, true, 'INSERT through the read-only connection throws');
eq((int)(new PDO('sqlite:' . $dbfile))->query("SELECT COUNT(*) FROM meta WHERE key = 'x'")->fetchColumn(), 0, 'nothing was written');

// Request memo: the status is remembered per (file, size, mtime) and reuses
// study_db()'s connection; study_reset() forgets both.
$m0 = filemtime($dbfile);
$fh = fopen($dbfile, 'r+'); fwrite($fh, str_repeat("\0", 16)); fclose($fh); // corrupt the header in place
touch($dbfile, $m0);                                                         // same path, size and mtime
eq(study_db_status(), 'ok', 'status is memoised while file, size and mtime are unchanged');
eq(study_db(), $db, 'the memo keeps the one connection');
study_reset();
eq(study_db_status(), 'invalid', 'study_reset() forgets the memo, so the shape check runs again');

study_test_build_db($dbfile);
check(study_db() instanceof PDO, 'connection available again after the rebuild');
$w = new PDO('sqlite:' . $dbfile); $w->exec("DELETE FROM participant WHERE participant_id = 'pe'"); $w = null;
touch($dbfile, filemtime($dbfile)); // whatever the clock did, keep the mtime the memo may hold
study_reset();
$db2 = study_db();
check($db2 instanceof PDO && $db2 !== $db, 'after study_reset() a new connection is opened');
eq((int)$db2->query('SELECT COUNT(*) FROM participant')->fetchColumn(), 4, 'after study_reset() the rebuilt file is read');
touch($dbfile, time() + 60);
$db3 = study_db();
check($db3 instanceof PDO && $db3 !== $db2, 'a new mtime invalidates the memo without a reset');

// Developer mode: no storage root, location comes from config.
// A missing column makes the shape invalid.
$bad = "$sc/bad.sqlite";
study_test_build_db($bad);
$b = new PDO('sqlite:' . $bad); $b->exec('ALTER TABLE video DROP COLUMN uploader_id'); $b = null;
$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp', 'analysis_db' => $bad];
eq(study_db_file(), $bad, 'developer-mode file location from config');
eq(study_db_status(), 'invalid', 'developer mode reads shape (dropped column -> invalid)');
$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp'];
eq(study_db_file(), null, 'no file location without storage root or config');
eq(study_db_status(), 'off', 'developer mode without analysis_db is off');


// ---- Database backend: participant list and coverage cache ----
$GLOBALS['__cfg'] = ['storage_root' => $sc, 'default_n' => 15, 'base_path' => ''];
inst_save($base + ['analyses' => ['database' => ['enabled' => true, 'path' => 'analysis.sqlite']]]);
study_test_build_db($dbfile);
study_reset();
eq(study_mode(), 'database', 'db list: database mode');
eq(study_donation_count(), 3, 'db list: donation count = donated participants');
$dl = study_participants();
eq(array_keys($dl['participants']), ['pa', 'pb', 'pc'], 'db list: donated participants only');
$skipByP = [];
foreach ($dl['skipped'] as $s) { $skipByP[$s['participant']] = $s['kind']; }
eq($skipByP, ['pd' => 'not_donated', 'pe' => 'declined_consent'], 'db list: the others are set aside with the two kinds');
$pa = $dl['participants']['pa'];
eq($pa['id'], 'pa', 'db list: id is a string');
eq(array_keys($pa['platforms']), ['tiktok'], 'db list: tiktok platform');
$paT = $pa['platforms']['tiktok'];
eq(array_keys($paT['tables']), ['tiktok_watch_history', 'tiktok_favorite_videos', 'tiktok_share_history', 'tiktok_comments'],
   'db list: NULL n_like -> no like table key');
eq($paT['tables']['tiktok_watch_history'], ['count' => 4,
   'earliest' => gmmktime(10, 0, 0, 1, 15, 2026), 'latest' => gmmktime(10, 10, 0, 1, 15, 2026)],
   'db list: watch count and range ignore the NULL watched_at');
eq($paT['deleted'], ['watch' => 0, 'favorite' => 1, 'share' => 0, 'comment' => 0], 'db list: deleted from deleted_* (non-NULL only)');
eq([$paT['videos_total'], $paT['videos_transcribed']], [6, 1], 'db list: coverage over distinct non-NULL video ids');
$pbT = $dl['participants']['pb']['platforms']['tiktok'];
eq($pbT['tables']['tiktok_favorite_videos'], ['count' => 0, 'earliest' => null, 'latest' => null], 'db list: 0 count is present and empty');
eq([$pbT['videos_total'], $pbT['videos_transcribed']], [3, 1], 'db list: pb coverage (video shared with pa counted per participant)');
$pcT = $dl['participants']['pc']['platforms']['tiktok'];
eq(array_keys($pcT['tables']), ['tiktok_watch_history', 'tiktok_comments'], 'db list: pc sections');
eq([$pcT['videos_total'], $pcT['videos_transcribed']], [0, 0], 'db list: comments-only participant has no videos');

$cdir = "$sc/cache/study";
$cf = glob("$cdir/participants-*.json");
eq(count($cf), 1, 'db list: one cache file written');
check(preg_match('/participants-' . filesize($dbfile) . '-' . filemtime($dbfile) . '\.json$/', $cf[0] ?? '') === 1, 'db list: cache file named by size and mtime');
$old = $cf[0];
$cached = json_decode((string)file_get_contents($old), true);
$cached['participants']['pa']['id'] = 'from-cache';
file_put_contents($old, json_encode($cached));
eq(study_participants()['participants']['pa']['id'], 'from-cache', 'db list: second call reads the cache file');
file_put_contents("$cdir/participants-1-1.json", '{}');
touch($dbfile, time() + 120);
study_reset();
$dl2 = study_participants();
eq($dl2['participants']['pa']['id'], 'pa', 'db list: new mtime recomputes');
$cf2 = glob("$cdir/participants-*.json");
eq(count($cf2), 1, 'db list: old cache files removed');
check($cf2[0] !== $old, 'db list: a new cache file replaced the old one');

// A database error while computing: empty shape, logged, nothing cached, index still renders.
$GLOBALS['__cfg'] = ['storage_root' => $sc, 'default_n' => 15, 'base_path' => ''];
study_test_build_db($dbfile);
$bw = new PDO('sqlite:' . $dbfile);
$bw->exec('DROP TABLE watch_history');
$bw->exec("CREATE VIEW watch_history AS SELECT abs(-9223372036854775808) AS participant_id, 1 AS donation_row, 'v' AS video_id, 'w' AS watched_at, 'r' AS watched_at_raw, 'l' AS link");
$bw = null;
exec('rm -rf ' . escapeshellarg("$sc/cache"));
study_reset();
$errlog = "$sc/php-errors.log";
$prevLog = ini_set('error_log', $errlog);
eq(study_mode(), 'database', 'db error: the shape check still passes');
eq(study_participants(), ['participants' => [], 'skipped' => []], 'db error: empty shape');
eq(glob("$sc/cache/study/participants-*.json") ?: [], [], 'db error: nothing cached');
eq(study_donation_count(), 3, 'db error: unrelated donation count still works');
check(str_contains((string)@file_get_contents($errlog), 'analysis database error (participant list)'), 'db error: logged');
$_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); include __DIR__ . '/../public/index.php'; $errIndex = ob_get_clean();
check($errIndex !== '' && !str_contains($errIndex, 'Fatal error'), 'db error: index.php still renders');
ini_set('error_log', $prevLog === false ? '' : $prevLog);

// ---- Database backend: participant page ----
$GLOBALS['__cfg'] = ['storage_root' => $sc, 'default_n' => 15, 'base_path' => ''];
study_test_build_db($dbfile);
exec('rm -rf ' . escapeshellarg("$sc/config/flows"));
study_reset();
eq(study_mode(), 'database', 'db page: database mode');
function study_test_section(?array $p, string $key): ?array {
    foreach ($p['platforms']['tiktok']['sections'] ?? [] as $s) { if ($s['key'] === $key) { return $s; } }
    return null;
}
/** Sample rows keyed by their Link cell. */
function study_test_by_link(?array $sec): array {
    $out = [];
    foreach ($sec['sample'] ?? [] as $it) { $out[$it['row']['Link'] ?? ''] = $it; }
    return $out;
}

eq(study_availability_label('transcribed'), null, 'label: transcribed has no reason');
eq(study_availability_label('removed_or_private'), 'removed or private', 'label: removed_or_private');
eq(study_availability_label('removed'), 'removed', 'label: removed');
eq(study_availability_label('region_gated'), 'not available in this region', 'label: region_gated');
eq(study_availability_label('login_gated'), 'needs a TikTok login', 'label: login_gated');
eq(study_availability_label('photo_no_audio'), 'photo post without audio', 'label: photo_no_audio');
eq(study_availability_label('retries_exhausted'), 'could not be fetched', 'label: retries_exhausted');
eq(study_availability_label('not_attempted'), 'not fetched (only liked, favorited or shared)', 'label: not_attempted');

$dp = study_participant('pa', 15, 1, false);
check(is_array($dp), 'db page: known participant returns data');
eq($dp['id'] ?? null, 'pa', 'db page: id');
eq(array_keys($dp['platforms'] ?? []), ['tiktok'], 'db page: tiktok platform');
$dpt = $dp['platforms']['tiktok'] ?? [];
eq($dpt['title'] ?? null, 'Tiktok', 'db page: platform title falls back without flows');
eq($dpt['notes'] ?? null, [], 'db page: no note for a single submission');
eq(array_keys($dpt['scope'] ?? []), ['tiktok_comments', 'tiktok_favorite_videos', 'tiktok_share_history', 'tiktok_watch_history'],
   'db page: scope lists non-NULL sections only (like is NULL)');
eq($dpt['scope']['tiktok_watch_history'] ?? null, ['count' => 4,
   'earliest' => gmmktime(10, 0, 0, 1, 15, 2026), 'latest' => gmmktime(10, 10, 0, 1, 15, 2026)],
   'db page: scope count from n_watch, range ignores the NULL watched_at');
eq(array_column($dpt['sections'] ?? [], 'key'), ['tiktok_comments', 'tiktok_favorite_videos', 'tiktok_share_history', 'tiktok_watch_history'],
   'db page: sections in raw-mode order (unmatched, sorted)');
$w = study_test_section($dp, 'tiktok_watch_history');
eq([$w['title'] ?? null, $w['description'] ?? null, $w['columns'] ?? null], ['Tiktok watch history', '', ['Date', 'Link']],
   'db page: unmatched watch section uses prettified key and the donated row keys');
eq([$w['total'] ?? null, $w['with_artifacts'] ?? null, $w['hidden'] ?? null, $w['deleted'] ?? null], [4, 1, 3, 0],
   'db page: all=false pool is transcribed rows; untranscribed and NULL-video rows hidden');
eq($w['sample'] ?? null, [['row' => ['Date' => '2026-01-15 10:00:00', 'Link' => 'https://example.test/v/7000000000000001001'],
   'links' => [['label' => 'transcript', 'url' => 'transcript.php?vid=7000000000000001001']], 'reason' => null]],
   'db page: only the transcribed row is sampled, with its transcript link');
$f = study_test_section($dp, 'tiktok_favorite_videos');
eq([$f['total'] ?? null, $f['with_artifacts'] ?? null, $f['hidden'] ?? null, $f['deleted'] ?? null, $f['sample'] ?? null], [2, 0, 2, 1, []],
   'db page: favorites none transcribed, deleted from deleted_favorite');
$sh = study_test_section($dp, 'tiktok_share_history');
eq($sh['columns'] ?? null, ['Date', 'Link', 'Method', 'SharedContent'], 'db page: share row keys');
$cm = study_test_section($dp, 'tiktok_comments');
eq([$cm['total'] ?? null, $cm['with_artifacts'] ?? null, $cm['hidden'] ?? null, $cm['columns'] ?? null], [1, 1, 0, ['Comment', 'Date']],
   'db page: comments have no video, so the filter keeps them');
eq($cm['sample'] ?? null, [['row' => ['Comment' => 'first synthetic comment', 'Date' => '2026-01-15 13:00:00'], 'links' => [], 'reason' => null]],
   'db page: comment row');

$da = study_participant('pa', 15, 1, true);
$wa = study_test_section($da, 'tiktok_watch_history');
eq([$wa['total'] ?? null, $wa['with_artifacts'] ?? null, $wa['hidden'] ?? null, count($wa['sample'] ?? [])], [4, 4, 0, 4],
   'db page: all=true pools every row and hides nothing');
$wl = study_test_by_link($wa);
eq($wl['https://example.test/v/7000000000000001001'] ?? null, ['row' => ['Date' => '2026-01-15 10:00:00',
   'Link' => 'https://example.test/v/7000000000000001001'], 'links' => [['label' => 'transcript', 'url' => 'transcript.php?vid=7000000000000001001']],
   'reason' => null], 'db page: transcribed row links its transcript and has no reason');
eq($wl['https://example.test/v/7000000000000001002'] ?? null, ['row' => ['Date' => '2026-01-15 10:05:00',
   'Link' => 'https://example.test/v/7000000000000001002'], 'links' => [], 'reason' => 'removed or private'],
   'db page: untranscribed row carries its reason and no link');
eq($wl['https://example.test/v/7000000000000001003']['reason'] ?? null, 'removed', 'db page: removed reason');
eq($wl['https://example.test/v/7000000000000001003']['row']['Date'] ?? null, '1970-01-01 00:00:00', 'db page: Date is the donated string');
eq($wl['https://example.test/v/unresolved'] ?? null, ['row' => ['Date' => '2026-01-15 10:10:00',
   'Link' => 'https://example.test/v/unresolved'], 'links' => [], 'reason' => null],
   'db page: NULL video_id row has no links and no reason');
$fl = study_test_by_link(study_test_section($da, 'tiktok_favorite_videos'));
eq([$fl['https://example.test/v/7000000000000001006']['reason'] ?? null, $fl['https://example.test/v/7000000000000001007']['reason'] ?? null],
   ['photo post without audio', 'could not be fetched'], 'db page: favorite reasons');
$shl = study_test_by_link(study_test_section($da, 'tiktok_share_history'));
eq($shl['https://example.test/v/7000000000000001008'] ?? null, ['row' => ['Date' => '2026-01-15 12:00:00',
   'Link' => 'https://example.test/v/7000000000000001008', 'Method' => 'copy link', 'SharedContent' => 'video'],
   'links' => [], 'reason' => 'not fetched (only liked, favorited or shared)'], 'db page: share row with method, content and reason');

// Deterministic sampling: same (id, n, seed) twice is identical; reshuffle (seed + 1) changes the order;
// a smaller n is a prefix of the same order.
eq(study_participant('pa', 15, 1, true), $da, 'db page: same (id, n, seed) gives the same page');
$order = fn(?array $p) => array_map(fn($it) => $it['row']['Link'], study_test_section($p, 'tiktok_watch_history')['sample'] ?? []);
$o1 = $order($da);
$o2 = $order(study_participant('pa', 15, 2, true));
check($o1 !== $o2, 'db page: the next seed gives a different order');
eq(count(array_unique(array_merge($o1, $o2))), 4, 'db page: a different order of the same rows');
eq($order(study_participant('pa', 2, 1, true)), array_slice($o1, 0, 2), 'db page: n=2 takes the first two of the same order');
eq(count(study_test_section(study_participant('pa', 2, 1, true), 'tiktok_watch_history')['sample']), 2, 'db page: n limits the sample');

$db_pb = study_participant('pb', 15, 1, true);
$pbn = $db_pb['platforms']['tiktok']['notes'] ?? [];
eq(count($pbn), 1, 'db page: repeat submissions produce one note');
check(str_contains($pbn[0] ?? '', 'submitted 2 files; the first one with data is shown'), 'db page: repeat-submission note text');
check(array_key_exists('tiktok_favorite_videos', $db_pb['platforms']['tiktok']['scope'] ?? [])
      && study_test_section($db_pb, 'tiktok_favorite_videos') === null, 'db page: n=0 section is in scope but not listed');
eq($db_pb['platforms']['tiktok']['scope']['tiktok_favorite_videos'] ?? null, ['count' => 0, 'earliest' => null, 'latest' => null], 'db page: empty scope row');
eq(study_test_by_link(study_test_section($db_pb, 'tiktok_like_list'))['https://example.test/v/7000000000000001005']['reason'] ?? null,
   'needs a TikTok login', 'db page: like reason');
eq(study_test_by_link(study_test_section($db_pb, 'tiktok_watch_history'))['https://example.test/v/7000000000000001004']['reason'] ?? null,
   'not available in this region', 'db page: region reason');

eq(study_participant("x' OR 1=1 --", 15, 1, false), null, 'db page: malformed id -> null');
eq(study_participant('nobody', 15, 1, false), null, 'db page: unknown id -> null');
eq(study_participant('pd', 15, 1, true), null, 'db page: a participant who did not donate -> null');

// With the flow installed, titles, descriptions and column order come from the doc.
@mkdir("$sc/config/flows/tiktok", 0755, true);
copy(__DIR__ . '/fixtures/flows/tiktok/documentation.txt', "$sc/config/flows/tiktok/documentation.txt");
$df = study_participant('pa', 15, 1, false);
eq($df['platforms']['tiktok']['title'] ?? null, 'TikTok', 'db page: platform title from the flow doc');
eq(array_column($df['platforms']['tiktok']['sections'] ?? [], 'key'), ['tiktok_watch_history', 'tiktok_comments', 'tiktok_favorite_videos', 'tiktok_share_history'],
   'db page: doc-matched sections first, in doc order');
$wf = study_test_section($df, 'tiktok_watch_history');
eq([$wf['title'] ?? null, $wf['description'] ?? null, $wf['columns'] ?? null], ['Videos you watched', 'Watch history from your TikTok account.', ['Date', 'Link']],
   'db page: watch section matched to the flow doc');
$cf3 = study_test_section($df, 'tiktok_comments');
eq([$cf3['title'] ?? null, $cf3['columns'] ?? null], ['Comments you posted', ['Date', 'Comment']], 'db page: comments matched, doc column order');
eq(study_test_section($df, 'tiktok_favorite_videos')['title'] ?? null, 'Tiktok favorite videos', 'db page: undocumented section falls back');
exec('rm -rf ' . escapeshellarg("$sc/config/flows"));

// A database error on the page: logged, null (the page answers 404).
$bw = new PDO('sqlite:' . $dbfile);
$bw->exec('DROP TABLE watch_history');
$bw->exec("CREATE VIEW watch_history AS SELECT abs(-9223372036854775808) AS participant_id, 1 AS donation_row, 'v' AS video_id, 'w' AS watched_at, 'r' AS watched_at_raw, 'l' AS link");
$bw = null;
study_reset();
$prevLog = ini_set('error_log', $errlog);
eq(study_participant('pa', 15, 1, false), null, 'db page error: null');
check(str_contains((string)@file_get_contents($errlog), 'analysis database error (participant page)'), 'db page error: logged');
ini_set('error_log', $prevLog === false ? '' : $prevLog);

// Developer mode: no cache root -> computed, nothing cached.
study_test_build_db($dbfile);
$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp', 'analysis_db' => $dbfile];
study_reset();
eq(study_mode(), 'database', 'db list: developer-mode database');
eq(array_keys(study_participants()['participants']), ['pa', 'pb', 'pc'], 'db list: developer mode computes without a cache');
// Database transcript.
$dt = study_transcript('7000000000000001001');
eq($dt['text'] ?? null, 'Hello world', 'db transcript: text');
eq($dt['availability'] ?? null, 'transcribed', 'db transcript: availability');
eq($dt['meta']['language_detected'] ?? null, 'en', 'db transcript: language');
eq($dt['meta']['duration_s'] ?? null, 12.1, 'db transcript: duration');
eq($dt['meta']['source_url'] ?? null, 'https://example.test/v/7000000000000001001', 'db transcript: source url');
eq($dt['meta']['video_metadata']['uploader'] ?? null, 'uploader_a', 'db transcript: uploader');
eq($dt['meta']['video_metadata']['view_count'] ?? null, 1000, 'db transcript: view count');
eq($dt['meta']['raw_signals']['segments'][0]['tokens'][1]['text'] ?? null, ' Hello', 'db transcript: raw_signals decoded');
$dt9 = study_transcript('7000000000000001009');
check(array_key_exists('raw_signals', $dt9['meta']) && $dt9['meta']['raw_signals'] === null, 'db transcript: NULL raw_signals -> null');
check(array_key_exists('source_url', $dt9['meta']) && $dt9['meta']['source_url'] === null, 'db transcript: no process row -> null source url');
$dg = study_transcript('7000000000000001004');
eq([$dg['text'], $dg['meta'], $dg['availability']], [null, null, 'region_gated'], 'db transcript: untranscribed video');
eq(study_transcript('1111111111111111111'), ['text' => null, 'meta' => null, 'availability' => null], 'db transcript: unknown video');
eq(study_transcript('abc'), null, 'db transcript: malformed id');

// Raw backend (developer config: fixture donations + transcripts tree, no database).
$GLOBALS['__cfg'] = require __DIR__ . '/config.test.php';
study_reset();
eq(study_mode(), 'raw', 'developer config without analysis_db is raw');

$p1 = study_participant('p1', 1, 1, false);
check(is_array($p1), 'raw: known participant returns data');
eq($p1['id'] ?? null, 'p1', 'raw: participant id');
$tt = $p1['platforms']['tiktok'] ?? null;
check(is_array($tt), 'raw: tiktok platform present');
eq($tt['title'] ?? null, 'Tiktok', 'raw: platform title falls back to the prettified slug without flows');
eq($tt['notes'] ?? null, [], 'raw: no notes for a single donation');
$fixtureRows = ddp_parse_file(__DIR__ . '/fixtures/ddp/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json')['tables'];
$watch = null;
foreach ($tt['sections'] ?? [] as $s) { if ($s['key'] === 'tiktok_watch_history') { $watch = $s; } }
check(is_array($watch), 'raw: watch section present');
eq($watch['total'] ?? null, count($fixtureRows['tiktok_watch_history']), 'raw: watch total = fixture row count');
eq($watch['title'] ?? null, 'Tiktok watch history', 'raw: unmatched section title is the prettified key');
eq($watch['columns'] ?? null, ['Date', 'Link'], 'raw: unmatched section columns are the row keys');
eq($watch['with_artifacts'] ?? null, 1, 'raw: one watch row has a transcript');
eq($watch['hidden'] ?? null, count($fixtureRows['tiktok_watch_history']) - 1, 'raw: the others are hidden');
eq(count($watch['sample'] ?? []), 1, 'raw: 1-row sample');
eq($watch['sample'][0]['links'] ?? null, [['label' => 'transcript', 'url' => 'transcript.php?vid=7000000000000000029']],
   'raw: sampled row links to its transcript');
check(array_key_exists('reason', $watch['sample'][0] ?? []) && $watch['sample'][0]['reason'] === null, 'raw: no availability reason');
eq($watch['sample'][0]['row']['Date'] ?? null, '2026-07-05 11:53:55', 'raw: sampled row carries its cells as strings');
eq($tt['scope']['tiktok_watch_history'] ?? null, stats_section_summary($fixtureRows['tiktok_watch_history']),
   'raw: scope per table');
$pAll = study_participant('p1', 15, 1, true);
$watchAll = null;
foreach ($pAll['platforms']['tiktok']['sections'] as $s) { if ($s['key'] === 'tiktok_watch_history') { $watchAll = $s; } }
eq([$watchAll['with_artifacts'], $watchAll['hidden'], count($watchAll['sample'])], [3, 0, 3], 'raw: all=true hides nothing');
eq(study_participant('nope', 15, 1, false), null, 'raw: unknown participant -> null');

$tx = study_transcript('7000000000000000029');
check(str_contains((string)($tx['text'] ?? ''), 'Hello world'), 'raw: transcript text read from the tree');
check(is_array($tx['meta'] ?? null) && isset($tx['meta']['raw_signals']), 'raw: transcript meta is the artifact JSON');
check(array_key_exists('availability', $tx ?? []) && $tx['availability'] === null, 'raw: no availability');
eq(study_transcript('1111111111111111111'), ['text' => null, 'meta' => null, 'availability' => null], 'raw: untranscribed video');

$list = study_participants();
check(isset($list['participants']['p1']), 'raw: participant list has p1');
check(!isset($list['participants']['preview']), 'raw: preview file is not a participant');
$previewSkipped = array_values(array_filter($list['skipped'], fn($s) => $s['participant'] === 'preview'));
eq(count($previewSkipped), 1, 'raw: skipped lists the preview file');
eq($previewSkipped[0]['kind'] ?? null, 'declined', 'raw: preview file is a declined donation');
eq(study_donation_count(), 2, 'raw: donation count = donation files');

$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp2'];
$p2 = study_participant('p2', 15, 1, true);
eq(count($p2['platforms']['instagram']['notes'] ?? []), 1, 'raw: superseded donation produces one note');
check(str_contains($p2['platforms']['instagram']['notes'][0] ?? '', 'donated more than once for this platform')
      && str_contains($p2['platforms']['instagram']['notes'][0] ?? '', '(1 older file(s) ignored)'), 'raw: superseded note text');

$GLOBALS['__cfg'] = $saved_study_cfg;
study_reset();
exec('rm -rf ' . escapeshellarg($sc));
