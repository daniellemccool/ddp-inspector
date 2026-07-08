<?php
require_once __DIR__ . '/../src/Sample.php';

$rows = [];
for ($i = 0; $i < 100; $i++) { $rows[] = ['i' => $i]; }

$a = sample_rows($rows, 10, 1, 'watch');
$b = sample_rows($rows, 10, 1, 'watch');
eq($a, $b, 'same seed+salt is deterministic');
eq(count($a), 10, 'sample size honored');

$c = sample_rows($rows, 10, 2, 'watch');
check($a !== $c, 'different seed yields different sample');

$d = sample_rows($rows, 10, 1, 'comments');
check($a !== $d, 'different salt yields different sample');

$all = sample_rows($rows, 500, 1, 'watch');
eq($all, $rows, 'n >= count returns all rows unchanged');

eq(sample_rows([], 10, 1, 'x'), [], 'empty input returns empty');
