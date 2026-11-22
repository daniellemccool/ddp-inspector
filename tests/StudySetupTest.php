<?php
// The study setup exported from Next: read with ZipArchive, unpacked once
// into config/study-setup/ under names the app chooses.
$ssRoot = sys_get_temp_dir() . '/ddp-inspector-studysetup-' . getmypid();
exec('rm -rf ' . escapeshellarg($ssRoot)); mkdir($ssRoot, 0755, true);
$GLOBALS['__cfg_saved_ss'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => "$ssRoot/root", 'default_n' => 15, 'base_path' => ''];

eq(inst_paths()['setup'], "$ssRoot/root/config/study-setup", 'study setup path derived');
eq(setup_load(), null, 'no study setup yet');
$dest = inst_paths()['setup'];
$zip = "$ssRoot/setup.zip";
$metaJson = (string)json_encode(example_setup_meta());
$crate = (string)json_encode(['@context' => 'https://w3id.org/ro/crate/1.1/context', '@graph' => [['@id' => './', '@type' => 'Dataset', 'name' => 'Example study']]]);
$full = ['example/next-metadata.json' => $metaJson, 'example/ro-crate-metadata.json' => $crate,
         'example/assets/logo.png' => 'PNGBYTES', 'example/assets/document-1.pdf' => '%PDF-1.4 example'];

foreach (['logo.png', 'instruction-step-1-1-1.png', 'privacy-statement.pdf', 'A.b_c-1'] as $ok) {
    check(setup_asset_name_ok($ok), "asset name accepted: $ok");
}
foreach (['', '.x', 'a/b', '..', 'a b', '-x', "a\0b", str_repeat('a', 101)] as $bad) {
    check(!setup_asset_name_ok($bad), 'asset name refused: ' . json_encode($bad));
}

// A well-formed setup, plus entries the app does not recognise: those are
// left out, counted in the message, and never written.
make_setup_zip($zip, $full + ['example/assets/.hidden' => 'x', 'example/assets/sub/x.png' => 'x',
                              'example/notes.txt' => 'x', 'example/assets/a b.png' => 'x']);
$r = setup_ingest_upload($zip, $dest, 'example_20261121.zip');
eq($r['ok'], true, 'well-formed study setup accepted');
eq($r['name'], 'Example study', 'the study name is returned');
eq($r['tasks'], 2, 'the task count is returned');
check(str_contains($r['message'], 'Example study') && str_contains($r['message'], '2 task'), 'the message names the study and counts the tasks');
check(str_contains($r['message'], '4 file'), 'the message counts the entries left out');
$s = setup_load();
eq($s['assets'], ['document-1.pdf', 'logo.png'], 'stored assets listed, sorted');
eq($s['meta']['assignment']['id'], 7, 'metadata loaded');
eq($s['crate']['@graph'][0]['name'] ?? null, 'Example study', 'crate loaded');
eq($s['warnings'], [], 'no export warnings');
eq($s['uploaded']['zip_name'], 'example_20261121.zip', 'the uploaded file name is kept');
check(isset($s['uploaded']['uploaded_at']), 'the upload time is kept');
eq((string)file_get_contents("$dest/assets/logo.png"), 'PNGBYTES', 'asset bytes stored');
foreach (['.hidden', 'sub', 'a b.png'] as $no) { check(!file_exists("$dest/assets/$no"), "unrecognised entry not written: $no"); }
check(!file_exists("$dest/notes.txt"), 'a stray file beside the metadata is not written');
$listed = scandir($dest); sort($listed);
eq($listed, ['.', '..', 'assets', 'next-metadata.json', 'ro-crate-metadata.json', 'upload-meta.json'], 'only the fixed names are stored');

// Rejections: plain messages, and the stored setup is left as it was.
$bad = [];
file_put_contents("$ssRoot/notazip.zip", 'not a zip at all'); $bad['not a zip'] = "$ssRoot/notazip.zip";
make_setup_zip("$ssRoot/two.zip", $full + ['other/next-metadata.json' => $metaJson]); $bad['two top folders'] = "$ssRoot/two.zip";
make_setup_zip("$ssRoot/nometa.zip", ['example/ro-crate-metadata.json' => $crate]); $bad['no next-metadata.json'] = "$ssRoot/nometa.zip";
make_setup_zip("$ssRoot/flat.zip", ['next-metadata.json' => $metaJson]); $bad['metadata not in a folder'] = "$ssRoot/flat.zip";
make_setup_zip("$ssRoot/list.zip", ['example/next-metadata.json' => '[1,2]']); $bad['metadata is a list'] = "$ssRoot/list.zip";
make_setup_zip("$ssRoot/garbage.zip", ['example/next-metadata.json' => '{not json']); $bad['metadata is not JSON'] = "$ssRoot/garbage.zip";
make_setup_zip("$ssRoot/nover.zip", ['example/next-metadata.json' => '{"assignment":{"name":"x"}}']); $bad['no format_version'] = "$ssRoot/nover.zip";
make_setup_zip("$ssRoot/strver.zip", ['example/next-metadata.json' => '{"format_version":"1"}']); $bad['format_version is a string'] = "$ssRoot/strver.zip";
$many = ['example/next-metadata.json' => $metaJson];
for ($i = 0; $i < 500; $i++) { $many["example/assets/f$i.png"] = 'x'; }
make_setup_zip("$ssRoot/many.zip", $many); $bad['501 entries'] = "$ssRoot/many.zip";
$bad['a file that is not there'] = "$ssRoot/nosuch.zip";
foreach ($bad as $what => $path) {
    $r = setup_ingest_upload($path, $dest);
    eq($r['ok'], false, "rejected: $what");
    check($r['message'] !== '' && !str_contains($r['message'], 'ZipArchive'), "plain message: $what");
    eq(setup_load()['assets'] ?? null, ['document-1.pdf', 'logo.png'], "previous setup intact after: $what");
}
check(str_contains(setup_ingest_upload($bad['501 entries'], $dest)['message'], '500 files'), 'the too-many message gives the limit');
// A donation flow uploaded here by mistake is named for what it is.
make_setup_zip("$ssRoot/flow.zip", ['documentation.txt' => "# TikTok\n", 'build_x_y_tiktok_development_2026-01-01_00-00-00.zip' => 'x']);
$r = setup_ingest_upload("$ssRoot/flow.zip", $dest);
eq($r['ok'], false, 'a donation flow is not a study setup');
check(str_contains($r['message'], 'Donation flows'), 'the message points at the donation flows part');

// A newer format is accepted (the Study page says some parts may be missing).
make_setup_zip("$ssRoot/v2.zip", ['example/next-metadata.json' => '{"format_version":2,"assignment":{"id":1,"name":"Later"}}']);
$r = setup_ingest_upload("$ssRoot/v2.zip", $dest);
eq($r['ok'], true, 'format version 2 accepted');
eq($r['tasks'], 0, 'no tasks counted when there are none');
// ...and that second upload, which has no files, leaves none of the first's.
eq(setup_load()['assets'], [], 'a second setup with fewer files leaves no stale assets');
check(!file_exists("$dest/assets/logo.png"), 'the first setup\'s logo is gone');
check(!file_exists("$dest/ro-crate-metadata.json"), 'the first setup\'s crate is gone');
eq(setup_load()['crate'], null, 'no crate loaded when the zip had none');
check(glob("$dest.*") === [], 'no .new or .old directory left beside the setup');

// Export warnings: the files Next could not include, top folder stripped.
make_setup_zip($zip, $full + ['example/export-warnings.json' =>
    '{"skipped":[{"path":"example/assets/logo.png","reason":"x"},{"path":"example/assets/big.pdf","reason":"y"},{"nopath":1}]}']);
eq(setup_ingest_upload($zip, $dest)['ok'], true, 'setup with export warnings accepted');
eq(setup_load()['warnings'], ['assets/logo.png', 'assets/big.pdf'], 'export warnings loaded without the top folder');
// A crate or a warnings file that is not JSON is ignored, not fatal.
make_setup_zip($zip, ['example/next-metadata.json' => $metaJson, 'example/ro-crate-metadata.json' => '{oops', 'example/export-warnings.json' => 'nope']);
eq(setup_ingest_upload($zip, $dest)['ok'], true, 'a broken crate does not stop the upload');
eq(setup_load()['crate'], null, 'a broken crate is not stored');
eq(setup_load()['warnings'], [], 'a broken warnings file is ignored');
// A zip whose folder entries are listed explicitly (as some tools write them).
$za = new ZipArchive(); $za->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$za->addEmptyDir('example'); $za->addEmptyDir('example/assets');
foreach ($full as $n => $b) { $za->addFromString($n, $b); }
$za->close();
$r = setup_ingest_upload($zip, $dest);
eq($r['ok'], true, 'explicit folder entries are fine');
check(!str_contains($r['message'], 'left out'), 'folder entries are not counted as left out');

// Removing.
eq(setup_remove($dest), true, 'study setup removed');
eq(setup_load(), null, 'nothing loads after removal');
check(!is_dir($dest), 'the directory is gone');
eq(setup_remove($dest), false, 'removing twice reports nothing to remove');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_ss'];
eq(setup_load(), null, 'developer mode has no study setup');
exec('rm -rf ' . escapeshellarg($ssRoot));
