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
        if (preg_match('/^\|\s*`([^`]+)`\s*\|\s*(.*?)\s*\|$/', $line, $m)) {
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
