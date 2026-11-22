<?php
$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];
function check(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['__t']['pass']++; return; }
    $GLOBALS['__t']['fail']++;
    fwrite(STDERR, "FAIL: $msg\n");
}
function eq($got, $want, string $msg): void {
    check($got === $want, "$msg (got " . var_export($got, true) . ", want " . var_export($want, true) . ")");
}
/** A zip laid out like a study setup exported from Next: name => bytes.
 *  (Next streams its zips; ZipArchive does not reproduce that layout.) */
function make_setup_zip(string $path, array $entries): void {
    @unlink($path);
    $za = new ZipArchive();
    $za->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) { $za->addFromString($name, $bytes); }
    $za->close();
}
/** next-metadata.json of a made-up study: a logo, a document and a donation task. */
function example_setup_meta(): array {
    return ['format_version' => 1, 'assignment' => ['id' => 7, 'name' => 'Example study'], 'language' => 'en',
        'branding' => ['title' => 'News on a platform', 'subtitle' => null, 'logo' => 'assets/logo.png', 'header_image' => null],
        'about' => null, 'consent' => '<div>I agree.</div>', 'helpdesk' => null, 'privacy_statement_pdf' => null,
        'workflow' => ['tasks' => [
            ['type' => 'document', 'title' => 'How to request', 'description' => null, 'filename' => 'guide.pdf', 'file' => 'assets/document-1.pdf'],
            ['type' => 'data_donation', 'title' => 'Donate', 'description' => 'Extracts things', 'filename' => 'dd-example.zip']]]];
}
foreach (['DdpTest.php', 'StatsTest.php', 'SampleTest.php', 'AnalysisTest.php', 'InstanceTest.php', 'PagesTest.php', 'SetupTest.php', 'FlowsTest.php', 'StudyTest.php', 'StudySetupTest.php', 'RichTextTest.php', 'StudyPageTest.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p)) { require $p; }
}
$t = $GLOBALS['__t'];
echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
exit($t['fail'] === 0 ? 0 : 1);
