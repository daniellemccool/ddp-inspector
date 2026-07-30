<?php
/** @return array{participant:string, source:string, key_millis:int} */
function ddp_file_meta(string $path): array {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $out = ['participant' => $stem, 'source' => 'unknown', 'key_millis' => 0];
    foreach (explode('_', $stem) as $seg) {
        if (str_starts_with($seg, 'participant=')) { $out['participant'] = substr($seg, 12); }
        if (str_starts_with($seg, 'source='))      { $out['source'] = substr($seg, 7); }
        if (str_starts_with($seg, 'key=')) {
            if (preg_match('/^key=(\d+)/', $seg, $m)) { $out['key_millis'] = (int)$m[1]; }
        }
    }
    return $out;
}

function ddp_participant_id_from_filename(string $path): string {
    return ddp_file_meta($path)['participant'];
}

/** @return array{tables: array<string,list<array>>, deleted: array<string,int>}|null */
function ddp_parse_file(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_is_list($data)) { return null; }
    $tables = []; $deleted = [];
    foreach ($data as $element) {
        if (!is_array($element)) { continue; }
        $del = isset($element['deleted row count']) ? (int)$element['deleted row count'] : 0;

        // Count how many actual table arrays are in this element
        $tableCount = 0;
        foreach ($element as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $tableCount++;
            }
        }

        // Only attribute deleted count if exactly one table; otherwise 0 (ambiguous)
        $attributeDel = ($tableCount === 1) ? $del : 0;

        // Now process the tables
        foreach ($element as $key => $value) {
            if (!is_array($value) || !array_is_list($value)) { continue; }
            $rows = array_values(array_filter($value, 'is_array'));
            $tables[$key] = array_merge($tables[$key] ?? [], $rows);
            $deleted[$key] = ($deleted[$key] ?? 0) + $attributeDel;
        }
    }
    return ['tables' => $tables, 'deleted' => $deleted];
}

// Newest key_millis per participant+platform wins; older files are recorded
// as superseded on the winning entry.
function ddp_place_entry(array &$participants, string $id, string $src, array $entry): void {
    $participants[$id] ??= ['id' => $id, 'platforms' => []];
    $cur = $participants[$id]['platforms'][$src] ?? null;
    if ($cur === null) {
        $participants[$id]['platforms'][$src] = $entry;
    } elseif ($entry['key_millis'] > $cur['key_millis']) {
        $entry['superseded'] = array_merge($cur['superseded'], [$cur['file']]);
        $participants[$id]['platforms'][$src] = $entry;
    } else {
        $participants[$id]['platforms'][$src]['superseded'][] = $entry['file'];
    }
}

function ddp_load_dir(string $dir): array {
    $participants = []; $skipped = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
        $parsed = ddp_parse_file($path);
        if ($parsed === null) {
            $skipped[] = ['path' => basename($path), 'reason' => 'not a donation file (skipped)'];
            continue;
        }
        $meta = ddp_file_meta($path);
        ddp_place_entry($participants, $meta['participant'], $meta['source'],
            ['file' => basename($path), 'key_millis' => $meta['key_millis'],
             'superseded' => [], 'tables' => $parsed['tables'], 'deleted' => $parsed['deleted']]);
    }
    ksort($participants);
    foreach ($participants as &$p) { ksort($p['platforms']); }
    return ['participants' => $participants, 'skipped' => $skipped];
}

/** Small, cacheable per-file summary: meta + per-table stats, no row data. */
function ddp_summarize_file(string $path): ?array {
    $parsed = ddp_parse_file($path);
    if ($parsed === null) { return null; }
    $meta = ddp_file_meta($path);
    $tables = [];
    foreach ($parsed['tables'] as $name => $rows) {
        $tables[$name] = stats_section_summary($rows);
    }
    return ['file' => basename($path), 'participant' => $meta['participant'],
            'source' => $meta['source'], 'key_millis' => $meta['key_millis'],
            'tables' => $tables, 'deleted' => $parsed['deleted']];
}

// Summaries are cached on disk keyed by (mtime, size) so the participant list
// never re-parses unchanged multi-MB donations. Cache lives in the instance
// cache tree; without one (developer mode) summaries are computed each time.
function ddp_summarize_file_cached(string $path): ?array {
    $cacheRoot = inst_paths()['cache'];
    $st = @stat($path);
    if ($cacheRoot === null || $st === false) { return ddp_summarize_file($path); }
    $key = $cacheRoot . '/stats/' . basename($path) . '.stats.json';
    $cached = inst_read_json($key);
    if (is_array($cached)
        && ($cached['_mtime'] ?? -1) === $st['mtime']
        && ($cached['_size'] ?? -1) === $st['size']
        && is_array($cached['summary'] ?? null)) {
        return $cached['summary'];
    }
    $sum = ddp_summarize_file($path);
    if ($sum !== null) {
        inst_write_json_atomic($key, ['_mtime' => $st['mtime'], '_size' => $st['size'], 'summary' => $sum]);
    }
    return $sum;
}

/** ddp_load_dir's shape with per-table SUMMARIES instead of row data —
 *  memory stays flat no matter how many/large the donations are. */
function ddp_load_dir_summaries(string $dir): array {
    $participants = []; $skipped = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
        $sum = ddp_summarize_file_cached($path);
        if ($sum === null) {
            $skipped[] = ['path' => basename($path), 'reason' => 'not a donation file (skipped)'];
            continue;
        }
        ddp_place_entry($participants, $sum['participant'], $sum['source'],
            ['file' => $sum['file'], 'key_millis' => $sum['key_millis'],
             'superseded' => [], 'tables' => $sum['tables'], 'deleted' => $sum['deleted']]);
    }
    ksort($participants);
    foreach ($participants as &$p) { ksort($p['platforms']); }
    return ['participants' => $participants, 'skipped' => $skipped];
}

/** Full-row load of ONE participant's files only (the participant page's
 *  working set) — never parses the rest of the inbox. */
function ddp_load_participant(string $dir, string $id): ?array {
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) { return null; }
    $participants = [];
    foreach (glob(rtrim($dir, '/') . '/*participant=' . $id . '_*.json') ?: [] as $path) {
        $meta = ddp_file_meta($path);
        if ($meta['participant'] !== $id) { continue; }
        $parsed = ddp_parse_file($path);
        if ($parsed === null) { continue; }
        ddp_place_entry($participants, $id, $meta['source'],
            ['file' => basename($path), 'key_millis' => $meta['key_millis'],
             'superseded' => [], 'tables' => $parsed['tables'], 'deleted' => $parsed['deleted']]);
    }
    if (!isset($participants[$id])) { return null; }
    ksort($participants[$id]['platforms']);
    return $participants[$id];
}
