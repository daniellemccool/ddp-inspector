<?php
/**
 * Deterministic sample: a stable pseudo-random subset chosen by hashing each
 * row's index with the seed and salt, sorting by the hash, and taking the first n.
 * @param list<array> $rows
 * @return list<array>
 */
function sample_rows(array $rows, int $n, int $seed, string $salt): array {
    $count = count($rows);
    if ($n >= $count) { return $rows; }
    $keys = [];
    foreach ($rows as $i => $_) {
        $keys[$i] = hash('crc32b', $salt . '|' . $seed . '|' . $i);
    }
    asort($keys);
    $chosen = array_slice(array_keys($keys), 0, $n, true);
    $out = [];
    foreach ($chosen as $i) { $out[] = $rows[$i]; }
    return $out;
}
