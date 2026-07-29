<?php
function flows_parse_doc(string $text): ?array {
    $lines = preg_split('/\r?\n/', $text);
    $title = null; $commit = null; $sections = []; $cur = null;
    foreach ($lines as $line) {
        if ($title === null && preg_match('/^# (.+)$/', $line, $m)) { $title = trim($m[1]); continue; }
        if (preg_match('/^## (.+)$/', $line, $m)) {
            if ($cur !== null) { $sections[] = $cur; }
            $head = trim($m[1]);
            $cur = ($head === 'Build information') ? ['__build' => true, 'title' => $head, 'description' => '', 'vars' => [], 'var_desc' => []]
                                                   : ['title' => $head, 'description' => '', 'vars' => [], 'var_desc' => []];
            continue;
        }
        if ($cur === null) { continue; }
        if (preg_match('/^\|\s*`([^`]+)`\s*\|\s*(.*?)\s*\|\s*$/', $line, $m)) {
            $cur['vars'][] = $m[1]; $cur['var_desc'][$m[1]] = $m[2]; continue;
        }
        if (preg_match('/commit `([0-9a-f]{7,40})`/', $line, $m)) { $commit = $m[1]; }
        if (!str_starts_with($line, '|') && trim($line) !== '' && trim($line) !== '---' && $cur['vars'] === []) {
            $cur['description'] .= (($cur['description'] === '') ? '' : ' ') . trim($line);
        }
    }
    if ($cur !== null) { $sections[] = $cur; }
    if ($title === null) { return null; }
    $sections = array_values(array_filter($sections, fn($s) => !isset($s['__build'])));
    return ['platform_title' => $title, 'commit' => $commit, 'sections' => $sections];
}

/** @return array<string, array> parsed doc per platform slug */
function flows_load_all(): array {
    $flows = inst_paths()['flows'];
    if ($flows === null || !is_dir($flows)) { return []; }
    $out = [];
    foreach (glob("$flows/*/documentation.txt") ?: [] as $docPath) {
        $slug = basename(dirname($docPath));
        $doc = flows_parse_doc((string)@file_get_contents($docPath));
        if ($doc !== null) { $out[$slug] = $doc; }
    }
    ksort($out);
    return $out;
}

/** @param array<string,list<array>> $tables @return array<string,?int> */
function flows_match(array $tables, ?array $doc): array {
    $match = array_fill_keys(array_keys($tables), null);
    if ($doc === null) { return $match; }
    $claimed = [];
    foreach ($tables as $name => $rows) {
        $cols = [];
        foreach ($rows as $row) { foreach (array_keys($row) as $k) { $cols[$k] = true; } }
        $cols = array_keys($cols); sort($cols);
        foreach ($doc['sections'] as $i => $sec) {
            if (isset($claimed[$i])) { continue; }
            $vars = $sec['vars']; sort($vars);
            if ($vars === $cols && $vars !== []) { $match[$name] = $i; $claimed[$i] = true; break; }
        }
    }
    return $match;
}

/** @param array<string,?int> $match @return list<string> */
function flows_table_order(array $match): array {
    $matched = array_filter($match, fn($i) => $i !== null);
    asort($matched);
    $unmatched = array_keys(array_filter($match, fn($i) => $i === null));
    sort($unmatched);
    return array_merge(array_keys($matched), $unmatched);
}

function flows_prettify(string $key): string {
    return ucfirst(str_replace('_', ' ', $key));
}

function flows_slug_from_build_name(string $name): ?string {
    $stem = preg_replace('/\.zip$/i', '', basename($name));
    $parts = explode('_', $stem);
    if (count($parts) < 5 || $parts[0] !== 'build') { return null; }
    $slug = $parts[count($parts) - 4];
    return preg_match('/^[a-z0-9]+$/', $slug) ? $slug : null;
}

/** @return array{ok:bool, message:string, slug:?string, table_count:?int} */
function flows_ingest_upload(string $zipPath, string $flowsDir): array {
    $fail = fn(string $msg) => ['ok' => false, 'message' => $msg, 'slug' => null, 'table_count' => null];
    if (!is_file($zipPath)) { return $fail('No file arrived — please choose the zip you downloaded from the flow builder and try again.'); }
    if (filesize($zipPath) > 64 * 1024 * 1024) { return $fail('That file is larger than 64 MB — flow exports are much smaller. Is it the right zip?'); }
    $reading = $zipPath;
    if (!preg_match('/\.zip$/i', $zipPath)) { $reading = $zipPath . '.zip'; if (!@copy($zipPath, $reading)) { return $fail('Could not read the uploaded file — please try again.'); } }
    try { $phar = new PharData($reading); } catch (Throwable) {
        return $fail("That doesn't look like a flow export zip. Upload the zip exactly as downloaded from the flow builder.");
    }
    $entries = []; $n = 0;
    foreach (new RecursiveIteratorIterator($phar) as $file) {
        if (++$n > 64) { return $fail('That zip contains too many files to be a flow export.'); }
        $rel = substr($file->getPathname(), strpos($file->getPathname(), '.zip') + 5);
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) { return $fail('That zip contains unsafe file paths and was not accepted.'); }
        $entries[$rel] = $file->getPathname();
    }
    if (!isset($entries['documentation.txt'])) { return $fail("The zip is missing its documentation file (documentation.txt) — upload the export exactly as downloaded."); }
    $builds = array_values(array_filter(array_keys($entries), fn($e) => preg_match('/^build_.*\.zip$/', $e)));
    if (count($builds) !== 1) { return $fail('The zip should contain exactly one build_… .zip file — upload the export exactly as downloaded.'); }
    $slug = flows_slug_from_build_name($builds[0]);
    if ($slug === null) { return $fail('Could not tell which platform this flow is for — the inner build file has an unexpected name.'); }
    $docText = (string)file_get_contents($entries['documentation.txt']);
    $doc = flows_parse_doc($docText);
    if ($doc === null) { return $fail('The documentation file inside the zip could not be read — upload the export exactly as downloaded.'); }
    $dest = rtrim($flowsDir, '/') . '/' . $slug;
    if (is_dir($dest)) { foreach (glob("$dest/*") ?: [] as $old) { @unlink($old); } }
    elseif (!@mkdir($dest, 0755, true)) { return $fail('Could not save the flow — the storage volume may be full or read-only.'); }
    if (@file_put_contents("$dest/documentation.txt", $docText) === false) { return $fail('Could not save the flow — the storage volume may be full or read-only.'); }
    inst_write_json_atomic("$dest/build-meta.json", [
        'commit' => $doc['commit'], 'build_zip_name' => $builds[0],
        'uploaded_at' => gmdate('c'),
    ]);
    if ($reading !== $zipPath) { @unlink($reading); }
    $count = count($doc['sections']);
    return ['ok' => true, 'slug' => $slug, 'table_count' => $count,
            'message' => $doc['platform_title'] . " — $count data tables ✓"];
}
