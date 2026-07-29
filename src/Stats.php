<?php
function stats_parse_date(string $s): ?int {
    $s = trim($s);
    $hasUtc = str_ends_with($s, ' UTC');
    $core = $hasUtc ? substr($s, 0, -4) : $s;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $core, new DateTimeZone('UTC'));
    if ($dt === false) { return null; }
    // Reject inputs the parser silently corrected (e.g. impossible dates)
    if ($dt->format('Y-m-d H:i:s') !== $core) { return null; }
    return $dt->getTimestamp();
}

function stats_parse_date_any(string $s): ?int {
    $ts = stats_parse_date($s);
    if ($ts !== null) { return $ts; }
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $s)) { return null; }
    try { $dt = new DateTimeImmutable($s, new DateTimeZone('UTC')); } catch (Exception) { return null; }
    // Reject inputs DateTimeImmutable silently overflowed (e.g. Feb 30 -> Mar 2)
    if ($dt->format('Y-m-d\TH:i:s') !== substr($s, 0, 19)) { return null; }
    return $dt->getTimestamp();
}

function stats_row_date(array $row): ?int {
    foreach (['Date', 'Timestamp', 'Time', 'time'] as $col) {
        if (!isset($row[$col]) || !is_scalar($row[$col])) { continue; }
        $ts = stats_parse_date_any((string)$row[$col]);
        if ($ts !== null) { return $ts; }
    }
    return null;
}

/** @param list<array> $rows */
function stats_section_summary(array $rows): array {
    $count = count($rows);
    $earliest = null; $latest = null;
    foreach ($rows as $row) {
        $ts = stats_row_date($row);
        if ($ts === null) { continue; }
        if ($earliest === null || $ts < $earliest) { $earliest = $ts; }
        if ($latest === null || $ts > $latest) { $latest = $ts; }
    }
    return ['count' => $count, 'earliest' => $earliest, 'latest' => $latest];
}

/** @param array<string,list<array>> $tables @param list<string> $order */
function stats_platform_scope(array $tables, array $order): array {
    $out = []; $total = 0; $earliest = null; $latest = null;
    $names = array_values(array_unique(array_merge($order, array_keys($tables))));
    foreach ($names as $name) {
        $s = stats_section_summary($tables[$name] ?? []);
        $out[$name] = $s;
        $total += $s['count'];
        if ($s['earliest'] !== null && ($earliest === null || $s['earliest'] < $earliest)) { $earliest = $s['earliest']; }
        if ($s['latest'] !== null && ($latest === null || $s['latest'] > $latest)) { $latest = $s['latest']; }
    }
    return ['tables' => $out, 'total_rows' => $total, 'earliest' => $earliest, 'latest' => $latest];
}
