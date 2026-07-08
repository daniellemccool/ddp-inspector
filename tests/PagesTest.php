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
check(str_contains($html, '1 file') || str_contains($html, 'skipped'), 'index shows skipped-file notice');
check(!str_contains($html, 'participant.php?id=preview'), 'index does not link the skipped preview participant as a row');

// --- participant.php ---
$html = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '1']);
check(str_contains($html, 'tiktok_watch_history'), 'participant shows watch history section');
check(str_contains($html, 'tiktok_comments'), 'participant shows comments section');
check(str_contains($html, 'Igual estoy yo') || str_contains($html, 'second comment'), 'comment text rendered');
check(str_contains($html, 'transcript.php?vid=7654562293757250829'), 'video row links to transcript');
check(str_contains($html, 'seed=2'), 'reshuffle link bumps seed');

$missing = render_page('participant.php', ['id' => 'nope']);
check(str_contains($missing, 'not found') || str_contains($missing, '404'), 'unknown participant -> not found');

// --- transcript.php ---
$ok = render_page('transcript.php', ['vid' => '7654562293757250829']);
check(str_contains($ok, 'Hello world this is a test'), 'transcript text rendered');
check(str_contains($ok, '0.42') || str_contains($ok, 'low'), 'low-confidence segment surfaced');

$none = render_page('transcript.php', ['vid' => '1111111111111111111']);
check(str_contains(strtolower($none), 'not transcribed'), 'missing transcript -> not transcribed');

$bad = render_page('transcript.php', ['vid' => '../etc/passwd']);
check(str_contains($bad, '400') || str_contains($bad, 'invalid'), 'bad vid rejected');
