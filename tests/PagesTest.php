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
check(!str_contains($html, 'preview'), 'index does not list the skipped preview participant');
