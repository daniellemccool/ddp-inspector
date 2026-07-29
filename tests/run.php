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
foreach (['DdpTest.php', 'StatsTest.php', 'SampleTest.php', 'AnalysisTest.php', 'InstanceTest.php', 'PagesTest.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p)) { require $p; }
}
$t = $GLOBALS['__t'];
echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
exit($t['fail'] === 0 ? 0 : 1);
