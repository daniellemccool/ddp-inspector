<?php
// The Study page: what participants saw (from the study setup) and what was
// collected (the codebook, from the donation flows).
$spRoot = sys_get_temp_dir() . '/ddp-inspector-studypage-' . getmypid();
exec('rm -rf ' . escapeshellarg($spRoot)); mkdir($spRoot, 0755, true);
$GLOBALS['__cfg_saved_sp'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => "$spRoot/root", 'default_n' => 15, 'base_path' => ''];
$spDest = inst_paths()['setup'];

// Render the page; any PHP warning or notice on the way is collected.
$renderStudy = function (string $page = ''): array {
    $problems = [];
    set_error_handler(function (int $no, string $str, string $file, int $line) use (&$problems) {
        $problems[] = "$str @ " . basename($file) . ":$line"; return true;
    });
    $_POST = [];
    try { $html = render_page('study.php', $page === '' ? [] : ['page' => $page]); } finally { restore_error_handler(); }
    return [$html, $problems];
};
$storeSetup = function (array|string $meta, ?array $crate = null, array $assets = [], ?string $warnings = null) use ($spRoot, $spDest): array {
    $entries = ['example/next-metadata.json' => is_string($meta) ? $meta : (string)json_encode($meta)];
    if ($crate !== null) { $entries['example/ro-crate-metadata.json'] = (string)json_encode($crate); }
    if ($warnings !== null) { $entries['example/export-warnings.json'] = $warnings; }
    foreach ($assets as $name => $bytes) { $entries["example/assets/$name"] = $bytes; }
    make_setup_zip("$spRoot/s.zip", $entries);
    return setup_ingest_upload("$spRoot/s.zip", $spDest, 's.zip');
};
$storeFlow = function (bool $withSource) use ($spRoot): void {
    $dir = "$spRoot/root/config/flows/tiktok"; @mkdir($dir, 0755, true);
    $doc = (string)file_get_contents(__DIR__ . '/fixtures/flows/tiktok/documentation.txt');
    // The fixture has no build section; the script selector always writes one.
    $doc .= "\n---\n\n## Build information\n\nThis flow was generated from commit `abcdef1234567` of the data-donation-task repository.\n";
    if ($withSource) { $doc .= "\nView the source code at: https://github.com/d3i-infra/data-donation-task/commit/abcdef1234567\n"; }
    file_put_contents("$dir/documentation.txt", $doc);
    inst_write_json_atomic("$dir/build-meta.json", ['commit' => 'abcdef1234567', 'build_zip_name' => 'build_x_tiktok_y.zip', 'uploaded_at' => '2026-11-20T09:00:00+00:00']);
};

// --- nothing uploaded: one note, and the page needs no source to render ------
[$html, $problems] = $renderStudy();
eq($problems, [], 'empty page renders without warnings');
check(!str_contains($html, 'not set up yet'), 'the Study page does not need a configured source');
check(str_contains($html, 'Nothing describes this study yet.'), 'with nothing uploaded, the page says so');
check(str_contains($html, '<a href="setup.php">Settings</a>'), 'the note links to Settings');
check(!str_contains($html, 'class="screen"') && !str_contains($html, 'class="codebook"'), 'no screens and no codebook with nothing uploaded');
// Study is two pages, switched by a subnav under the header.
check(preg_match('~<nav class="subnav" aria-label="Study">.*<a href="study\.php" aria-current="page">What participants saw</a>.*<a href="study\.php\?page=collected">What was collected</a>.*</nav>~s', $html) === 1, 'the subnav names both pages, the first one current');
[$chtml] = $renderStudy('collected');
check(preg_match('~<a href="study\.php">What participants saw</a>.*<a href="study\.php\?page=collected" aria-current="page">What was collected</a>~s', $chtml) === 1, 'on the second page the second link is current');
check(str_contains($chtml, 'No donation flow yet, so there is no codebook to show.'), 'the second page says its upload is missing');
[$odd] = $renderStudy('nonsense');
check(str_contains($odd, '<a href="study.php" aria-current="page">'), 'an unknown page value is the first page');
$_POST = [];
check(!str_contains(render_page('setup.php', []), 'class="subnav"'), 'other pages have no subnav');
check(preg_match('~<h1>Study</h1>~', $html) === 1, 'the title is just "Study" without a name');
check(preg_match('~Participants</a></li>\s*<li class="site-nav__item is-active"><a href="study.php" aria-current="true">Study</a></li>\s*<li class="site-nav__item"><a href="setup.php">Settings</a>~', $html) === 1,
      'navigation is Participants, Study, Settings, with Study current');

// --- helpers ------------------------------------------------------------------
eq(setup_ref('assets/logo.png', ['logo.png']), 'logo.png', 'a stored file reference resolves');
eq(setup_ref('assets/../x', ['x']), null, 'a reference with a path in it does not resolve');
eq(setup_ref('https://e/x.png', ['x.png']), null, 'a URL is not a file reference');
eq(setup_ref('assets/logo.png', []), null, 'a reference to a file that is not stored does not resolve');
eq(setup_ref(null, []), null, 'no reference');
eq(setup_ref(['assets/logo.png'], ['logo.png']), null, 'a reference of the wrong type does not resolve');
eq(setup_text('  a '), 'a', 'text is trimmed');
eq(setup_text(['a']), '', 'a non-string is no text');
eq(setup_text(null), '', 'null is no text');

// --- the full page ------------------------------------------------------------
$meta = example_setup_meta();
$meta['about'] = '<div>About this study.</div>';
$meta['helpdesk'] = '<div>Mail the helpdesk.</div>';
$meta['consent'] = '<h1>Consent</h1><div onclick="x()">I agree <script>alert(1)</script></div>';
$meta['privacy_statement_pdf'] = 'assets/privacy-statement.pdf';
$meta['branding']['subtitle'] = 'A subtitle';
$meta['workflow']['tasks'][] = ['type' => 'questionnaire', 'title' => 'Survey', 'description' => null, 'link' => 'https://survey.example/s/1'];
$meta['workflow']['tasks'][] = ['type' => 'instruction_manual', 'title' => 'How to download', 'description' => 'Follow <b>x</b>',
    'manual_title' => 'Download manual', 'manual_description' => 'Step by step', 'chapters' => [['title' => 'On your phone', 'pages' => [
        ['title' => 'Open the app', 'description' => '<div>Tap <strong>Settings</strong>.</div>', 'image' => 'assets/instruction-step-4-1-1.png'],
        ['title' => 'Request the data', 'description' => null, 'image' => 'assets/instruction-step-4-1-2.png']]]]];
$meta['workflow']['tasks'][] = ['type' => 'unknown', 'title' => 'Mystery', 'description' => null];
$meta['workflow']['tasks'][] = ['type' => 'some_new_tool', 'title' => 'Later', 'description' => null];
$crate = ['@context' => 'https://w3id.org/ro/crate/1.1/context', '@graph' => [
    ['@id' => 'ro-crate-metadata.json', '@type' => 'CreativeWork', 'about' => ['@id' => './']],
    ['@id' => './', '@type' => 'Dataset', 'name' => 'Example study', 'datePublished' => '2026-11-21T17:33:42.034826Z',
     'identifier' => 'next-assignment-7', 'inLanguage' => 'en',
     'author' => [['@id' => '#author-1'], ['@id' => '#author-2']],
     'license' => ['@id' => 'https://creativecommons.org/licenses/by/4.0/'], 'publisher' => ['@id' => 'https://next.example']],
    ['@id' => '#author-1', '@type' => 'Person', 'name' => 'A. Author <i>x</i>', 'contactPoint' => ['@id' => 'mailto:a.author@example.org']],
    ['@id' => '#author-2', '@type' => 'Person', 'name' => 'B. Author'],
    ['@id' => 'mailto:a.author@example.org', '@type' => 'ContactPoint', 'email' => 'a.author@example.org'],
    ['@id' => 'https://next.example', '@type' => 'Organization', 'name' => 'Next'],
    ['@id' => 'https://creativecommons.org/licenses/by/4.0/', '@type' => 'CreativeWork', 'name' => 'Creative Commons Attribution 4.0 International'],
]];
$assets = ['logo.png' => 'PNG', 'document-1.pdf' => 'PDF', 'privacy-statement.pdf' => 'PDF', 'instruction-step-4-1-1.png' => 'PNG'];
eq($storeSetup($meta, $crate, $assets)['ok'], true, 'full study setup stored');
$storeFlow(true);

$ov = setup_overview(setup_load());
eq($ov['name'], 'Example study', 'overview: name');
eq($ov['public_title'], 'News on a platform', 'overview: participant-facing title');
eq($ov['study_number'], 7, 'overview: study number');
eq($ov['authors'], [['name' => 'A. Author <i>x</i>', 'email' => 'a.author@example.org'], ['name' => 'B. Author', 'email' => '']], 'overview: authors, in order');
eq($ov['license'], ['name' => 'Creative Commons Attribution 4.0 International', 'url' => 'https://creativecommons.org/licenses/by/4.0/'], 'overview: licence');
eq($ov['publisher'], ['name' => 'Next', 'url' => 'https://next.example'], 'overview: publisher');
eq($ov['logo'], 'logo.png', 'overview: logo');

[$html, $problems] = $renderStudy();
eq($problems, [], 'the full page renders without warnings');
check(preg_match('~<h1><span class="eyebrow">Study</span> Example study</h1>~', $html) === 1, 'title band: Study over the study name');
// Order: as a participant met things, then the codebook.
$pos = [];
foreach (['id="about"', 'id="consent"', 'id="privacy"', 'id="task-1"', 'id="task-6"', 'id="helpdesk"'] as $marker) { $pos[] = strpos($html, $marker); }
$sorted = $pos; sort($sorted);
check(!in_array(false, $pos, true) && $pos === $sorted, 'sections are present and in order');
foreach (['about', 'consent', 'privacy', 'task-1', 'task-2', 'task-3', 'task-4', 'task-5', 'task-6', 'helpdesk'] as $id) {
    check(preg_match('~<nav class="contents"[^>]*>.*href="#' . $id . '".*?</nav>~s', $html) === 1, "the contents row links to #$id");
}
// Each thing a participant saw is a screen: our label and title, then one
// frame holding what the participant saw.
$screen = fn(string $id): string => preg_match('~<section class="screen" id="' . $id . '">.*?</section>~s', $html, $m) === 1 ? $m[0] : '';
$kinds = ['about' => 'Information page', 'consent' => 'Consent', 'privacy' => 'Privacy statement', 'task-1' => 'Task 1 of 6 · document',
    'task-2' => 'Task 2 of 6 · data donation', 'task-3' => 'Task 3 of 6 · questionnaire', 'task-4' => 'Task 4 of 6 · instruction manual',
    'task-5' => 'Task 5 of 6 · unknown', 'task-6' => 'Task 6 of 6 · some new tool', 'helpdesk' => 'Helpdesk page'];
$titles = ['about' => 'About', 'consent' => 'Consent', 'privacy' => 'Privacy statement', 'task-1' => 'How to request', 'task-2' => 'Donate',
    'task-3' => 'Survey', 'task-4' => 'How to download', 'task-5' => 'Mystery', 'task-6' => 'Later', 'helpdesk' => 'Helpdesk'];
foreach ($kinds as $id => $kind) {
    $sc = $screen($id);
    // A label that would only repeat the title (Consent, Privacy statement) is left out.
    $labelPart = $kind === $titles[$id] ? '' : '<p class="screen__kind">' . preg_quote($kind, '~') . '</p>\s*';
    check(preg_match('~^<section class="screen" id="[^"]+">\s*' . $labelPart . '<h2>' . preg_quote($titles[$id], '~') . '</h2>\s*<div class="screen__frame">~', $sc) === 1, "screen $id: label, title, then the frame");
    eq(substr_count($sc, 'class="screen__frame"'), 1, "screen $id has one frame");
}
check(str_contains($screen('consent'), '<h4>Consent</h4>') && strpos($screen('consent'), '<h4>Consent</h4>') > strpos($screen('consent'), 'screen__frame'), 'the participant' . "\x27" . 's own heading is inside the frame');
check(strpos($screen('task-1'), 'guide.pdf') > strpos($screen('task-1'), 'screen__frame'), 'a document' . "\x27" . 's link is inside the frame');
check(strpos($screen('task-4'), 'Open the app') > strpos($screen('task-4'), 'screen__frame') && str_contains($screen('task-4'), '<h3>On your phone</h3>'), 'a manual' . "\x27" . 's chapters and pages are inside the frame');
check(!str_contains($html, 'class="codebook"'), 'the first page has no codebook');
check(str_contains($html, 'From the study setup'), 'the first page says its source');
// Rich text is sanitised; everything else is escaped.
check(str_contains($html, '<h4>Consent</h4>') && str_contains($html, '<p>I agree</p>'), 'consent is shown formatted');
check(!str_contains($html, 'onclick') && !str_contains($html, 'alert(1)'), 'consent carries no attributes or script');
check(str_contains($html, '<p>About this study.</p>') && str_contains($html, '<p>Mail the helpdesk.</p>'), 'About and Helpdesk are shown');
check(str_contains($html, 'Follow &lt;b&gt;x&lt;/b&gt;'), 'a task description is plain text, escaped');
check(str_contains($html, 'A. Author &lt;i&gt;x&lt;/i&gt;') && !str_contains($html, '<i>x</i>'), 'an author name is escaped');
// Overview.
check(str_contains($html, 'B. Author'), 'overview lists both authors');
eq(substr_count($html, 'href="mailto:'), 1, 'only the author with an address is a mail link');
check(str_contains($html, 'href="mailto:a.author@example.org"'), 'the mail link is the author\'s');
check(preg_match('~<a href="https://next\.example"[^>]*>Next</a> study 7~', $html) === 1, 'the study number, with the publisher linked');
check(str_contains($html, 'setup exported 21 Nov 2026'), 'the export date');
check(preg_match('~<a href="https://creativecommons\.org/licenses/by/4\.0/"[^>]*>Creative Commons Attribution 4\.0 International</a>~', $html) === 1, 'the licence, linked');
check(str_contains($html, 'Shown to participants as “News on a platform”'), 'the participant-facing title, when it differs');
check(str_contains($html, 'A subtitle'), 'the subtitle');
check(str_contains($html, 'English'), 'the language by name');
check(preg_match('~<img[^>]* src="asset\.php\?f=logo\.png&amp;v=[0-9a-f]{8}"~', $html) === 1, 'the logo is shown');
// Files and tasks.
check(preg_match('~href="asset\.php\?f=privacy-statement\.pdf&amp;v=[0-9a-f]{8}"~', $html) === 1, 'the privacy statement links to its file');
check(preg_match('~<a href="asset\.php\?f=document-1\.pdf&amp;v=[0-9a-f]{8}"[^>]*>guide\.pdf</a>~', $html) === 1, 'the document task opens its file, labelled with the original name');
check(str_contains($html, 'dd-example.zip'), 'the donation task shows the script the study ran');
check(str_contains($html, 'href="https://survey.example/s/1"'), 'the questionnaire shows its link');
foreach (['Download manual', 'Step by step', 'On your phone', 'Open the app', 'Request the data', '<p>Tap <strong>Settings</strong>.</p>'] as $part) {
    check(str_contains($html, $part), "the manual shows: $part");
}
check(preg_match('~<img[^>]* src="asset\.php\?f=instruction-step-4-1-1\.png&amp;v=[0-9a-f]{8}"~', $html) === 1, 'a stored screenshot is shown');
check(!str_contains($html, 'f=instruction-step-4-1-2.png'), 'a screenshot that is not stored is not linked');
// A file's address carries the upload's stamp: Next reuses names (logo.png,
// document-1.pdf), and a re-uploaded setup must not show the old file from cache.
preg_match('~f=logo\.png&amp;v=([0-9a-f]{8})~', $html, $v1);
$meta0 = inst_read_json("$spDest/upload-meta.json"); $meta0['uploaded_at'] = '2030-01-01T00:00:00+00:00';
inst_write_json_atomic("$spDest/upload-meta.json", $meta0);
[$html2] = $renderStudy();
preg_match('~f=logo\.png&amp;v=([0-9a-f]{8})~', $html2, $v2);
check(isset($v1[1], $v2[1]) && $v1[1] !== $v2[1], 'the stamp on a file address changes with the upload');
// The page's own lists stay intact around rich text: every list item closes.
eq(preg_match_all('~<li[ >]~', $html), substr_count($html, '</li>'), 'every list item on the page is closed');
eq(substr_count($html, 'not included in the export'), 1, 'the one missing file says so');
// Codebook: the second page.
$firstPage = $html;
[$html, $problems] = $renderStudy('collected');
eq($problems, [], 'the codebook page renders without warnings');
check(!str_contains($html, 'class="screen"'), 'the second page has no screens');
check(str_contains($html, 'From the donation flows'), 'the second page says its source');
check(preg_match('~<nav class="contents"[^>]*>.*href="#t-tiktok-1".*?</nav>~s', $html) === 1 && str_contains($html, 'id="t-tiktok-1"'), 'its contents row links to the tables');
$fixture = flows_parse_doc((string)file_get_contents(__DIR__ . '/fixtures/flows/tiktok/documentation.txt'));
check(str_contains($html, h($fixture['platform_title'])), 'the codebook names the platform');
foreach ($fixture['sections'] as $sec) {
    check(str_contains($html, h($sec['title'])), 'codebook table: ' . $sec['title']);
    foreach ($sec['vars'] as $v) { check(str_contains($html, '>' . h($v) . '<'), "codebook variable: $v"); }
}
check(preg_match('~built from commit <a href="https://github\.com/d3i-infra/data-donation-task/commit/abcdef1234567"[^>]*>abcdef1</a>~', $html) === 1, 'the build commit links to its source');
check(str_contains($html, 'uploaded 20 Nov 2026'), 'the flow\'s upload date');
$html = $firstPage . $html;
check(!str_contains($html, 'No study setup yet') && !str_contains($html, 'No donation flow yet') && !str_contains($html, 'newer format') && !str_contains($html, 'could not include'), 'no notes when everything is there');

// A saved study name wins over the setup's; the participant-facing title is
// not repeated when it is the same as the name.
inst_save(['study_name' => 'News on a platform']);
[$html] = $renderStudy();
check(str_contains($html, '</span> News on a platform</h1>'), 'the saved study name is the title');
check(!str_contains($html, 'Shown to participants as'), 'a participant-facing title equal to the name is not repeated');
@unlink("$spRoot/root/config/instance.json");

// Without a source address the commit is text, not a link.
$storeFlow(false);
[$html] = $renderStudy('collected');
check(str_contains($html, 'built from commit abcdef1 ·') && !str_contains($html, 'github.com/d3i-infra'), 'no source address: the commit is plain text');

// --- one half only -------------------------------------------------------------
setup_remove($spDest);
[$html, $problems] = $renderStudy();
eq($problems, [], 'flows-only page renders without warnings');
check(str_contains($html, 'No study setup yet, so there is no consent, instructions or tasks to show.'), 'only a flow: the setup half is one line');
check(!str_contains($html, 'id="consent"') && str_contains($renderStudy('collected')[0], 'class="codebook"'), 'only a flow: the codebook is on the second page');
flows_remove("$spRoot/root/config/flows", 'tiktok');
$storeSetup($meta, $crate, $assets);
[$html, $problems] = $renderStudy();
eq($problems, [], 'setup-only page renders without warnings');
check(str_contains($renderStudy('collected')[0], 'No donation flow yet, so there is no codebook to show.') && !str_contains($html, 'No donation flow yet'), 'only a setup: the second page is one line');
check(str_contains($html, 'id="consent"'), 'only a setup: the consent is shown');

// --- notes ----------------------------------------------------------------------
$storeSetup(['format_version' => 2] + $meta, $crate, $assets, '{"skipped":[{"path":"example/assets/big-guide.pdf","reason":"x"}]}');
[$html] = $renderStudy();
check(str_contains($html, 'exported in a newer format (version 2)'), 'a newer format is noted');
check(str_contains($html, 'Next could not include 1 file in the export: big-guide.pdf.'), 'export warnings are listed');

// --- a study setup with nothing in it (Review Focus 3) ----------------------------
eq($storeSetup(['format_version' => 1])['ok'], true, 'an empty study setup is accepted');
[$html, $problems] = $renderStudy();
eq($problems, [], 'an empty study setup renders without warnings');
check(!str_contains($html, 'class="contents"'), 'empty setup: no contents row');
foreach (['id="about"', 'id="consent"', 'id="privacy"', 'id="task-1"', 'id="helpdesk"'] as $marker) {
    check(!str_contains($html, $marker), "empty setup: no section $marker");
}
check(str_contains($html, 'This study setup holds no information pages, consent or tasks.'), 'empty setup: says it is empty');
// Everything explicitly null, as Next writes an unconfigured study.
$storeSetup(['format_version' => 1, 'assignment' => ['id' => 1, 'name' => 'Bare'], 'language' => 'nl',
    'branding' => ['title' => null, 'subtitle' => null, 'logo' => null, 'header_image' => null],
    'about' => null, 'consent' => null, 'helpdesk' => null, 'privacy_statement_pdf' => null, 'workflow' => ['tasks' => []]]);
[$html, $problems] = $renderStudy();
eq($problems, [], 'an unconfigured study renders without warnings');
check(str_contains($html, 'Bare') && str_contains($html, 'Dutch'), 'an unconfigured study shows its name and language');

// --- wrong-typed fields (Review Focus 4) -----------------------------------------
$storeSetup(['format_version' => 1, 'assignment' => 'x', 'language' => ['en'], 'branding' => 'x', 'about' => ['x'], 'consent' => 5,
    'helpdesk' => true, 'privacy_statement_pdf' => ['assets/x.pdf'],
    'workflow' => ['tasks' => ['a' => 'scalar', 'b' => 7, 'c' => ['type' => ['x'], 'title' => ['t'], 'description' => 9, 'chapters' => 'x'],
        'd' => ['type' => 'instruction_manual', 'title' => 'M', 'chapters' => 'x'],
        'e' => ['type' => 'instruction_manual', 'title' => 'M2', 'chapters' => ['x', ['title' => ['t'], 'pages' => 'x'], ['title' => 'C', 'pages' => ['x', ['title' => 3, 'description' => ['d'], 'image' => 4]]]]],
        'f' => ['type' => 'document', 'title' => 'D', 'file' => ['assets/x.pdf'], 'filename' => 7],
        'g' => ['type' => 'questionnaire', 'title' => 'Q', 'link' => 'javascript:alert(1)'],
        'h' => ['type' => 'data_donation', 'title' => 'DD', 'filename' => ['x']]]]],
    ['@graph' => 'x']);
[$html, $problems] = $renderStudy();
eq($problems, [], 'wrong-typed metadata renders without warnings');
check(str_contains($html, 'id="task-') && str_contains($html, '<h2>M2</h2>') && str_contains($html, '<h3>C</h3>'), 'wrong-typed metadata: the usable tasks and chapters are still shown');
check(!str_contains($html, 'href="javascript:'), 'a questionnaire link that is not http(s) is never a link');
$storeSetup(['format_version' => 1, 'workflow' => 'x'], ['@graph' => [['@id' => './', 'author' => 'x', 'license' => 3, 'publisher' => ['@id' => ['x']], 'datePublished' => ['x']],
    'x', ['@id' => 5], ['@id' => '#a', 'name' => ['n'], 'contactPoint' => 'x']]]);
[$html, $problems] = $renderStudy();
eq($problems, [], 'a wrong-typed crate renders without warnings');
$storeSetup(['format_version' => 1, 'assignment' => ['id' => 2, 'name' => 'Odd']], ['@graph' => [['@id' => './',
    'author' => [['@id' => '#a'], ['@id' => '#missing'], 'x'], 'license' => ['@id' => 'javascript:alert(1)'], 'publisher' => ['@id' => '#p']],
    ['@id' => '#a', 'name' => 'Ann', 'contactPoint' => ['@id' => 'mailto:not an address"onclick="x']],
    ['@id' => 'javascript:alert(1)', 'name' => 'Lic'], ['@id' => '#p', 'name' => 'Pub']]]);
[$html, $problems] = $renderStudy();
eq($problems, [], 'an odd crate renders without warnings');
check(str_contains($html, 'Ann') && !str_contains($html, 'href="mailto:'), 'an author with a malformed address is listed without a mail link');
// A mail address is only an address: nothing that would pre-fill a message.
foreach (['a?subject=x&body=y@example.org', 'a%0Abcc=z@example.org', 'a,b@example.org', 'a@example.org?cc=z@example.org'] as $addr) {
    $storeSetup(['format_version' => 1, 'assignment' => ['id' => 2, 'name' => 'Odd']], ['@graph' => [['@id' => './', 'author' => [['@id' => '#a']]],
        ['@id' => '#a', 'name' => 'Ann', 'contactPoint' => ['@id' => 'mailto:' . $addr]]]]);
    [$h2] = $renderStudy();
    check(str_contains($h2, 'Ann') && !str_contains($h2, 'mailto:'), "no mail link for the address $addr");
}
$storeSetup(['format_version' => 1], ['@graph' => [['@id' => './', 'author' => [['@id' => '#a']]],
    ['@id' => '#a', 'name' => 'Ann', 'contactPoint' => ['@id' => 'mailto:ann.o+study_1@mail.example.org']]]]);
[$h2] = $renderStudy();
check(str_contains($h2, 'href="mailto:ann.o+study_1@mail.example.org"'), 'an ordinary address is a mail link');
// A bare list item in a manual page's text cannot end the page's own list item.
$storeSetup(['format_version' => 1, 'workflow' => ['tasks' => [
    ['type' => 'instruction_manual', 'title' => 'M', 'chapters' => [['title' => 'C', 'pages' => [['title' => 'P', 'description' => '<li>x</li></li></ol></li></ol></div>']]]]],
    ['type' => 'unknown', 'title' => 'Second task']]]]);
[$h2] = $renderStudy();
eq(preg_match_all('~<li[ >]~', $h2), substr_count($h2, '</li>'), 'a bare list item in rich text leaves the lists of the page balanced');
eq(preg_match_all('~<ol[ >]~', $h2), substr_count($h2, '</ol>'), 'and its ordered lists');
check(preg_match('~<div class="rich">x</div>~', $h2) === 1, 'the stray tags are gone, the text stays');
check(!str_contains($html, 'javascript:'), 'a licence that is not a web address is never a link');
check(str_contains($html, 'Lic'), 'the licence name is still shown');

// --- Setup says what the Study page loses -----------------------------------------
setup_remove($spDest);
$_POST = [];
$setupHtml = render_page('setup.php', []);
check(str_contains($setupHtml, 'Not added. Tables keep their raw names, and the Study page has no codebook.'), 'Setup: what is lost without a flow');
check(str_contains($setupHtml, 'Not added. The Study page has no consent, instructions or tasks.'), 'Setup: what is lost without a study setup');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_sp'];
exec('rm -rf ' . escapeshellarg($spRoot));

// --- developer mode: no storage volume, nothing to describe ------------------------
$_POST = [];
$devHtml = render_page('study.php', []);
check(str_contains($devHtml, 'configured by files on disk (developer mode)'), 'developer mode: the Study page says so');
check(!str_contains($devHtml, 'Nothing describes this study yet'), 'developer mode: no pointer to a Settings page that is not there');
