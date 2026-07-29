<?php
function analysis_tiktok_video_id(string $url): ?string {
    $re = '~^https?://(?:www\.)?(?:tiktokv|tiktok)\.com/(?:share/video|@[^/]+/video)/(\d{19})(?:/|\?|$)~';
    if (preg_match($re, $url, $m)) { return $m[1]; }
    return null;
}

/** @return array<string,array{platform:string, entity_id:callable, label:string}> */
function analysis_modules(): array {
    return [
        'transcripts' => [
            'platform'  => 'tiktok',
            'label'     => 'transcript',
            'entity_id' => fn(array $row): ?string =>
                isset($row['Link']) ? analysis_tiktok_video_id((string)$row['Link']) : null,
        ],
    ];
}

function analysis_dir(string $name): ?string {
    $inst = function_exists('inst_load') ? inst_load() : null;
    if (is_array($inst) && isset($inst['analysis_dirs'][$name])) { return rtrim((string)$inst['analysis_dirs'][$name], '/'); }
    if (function_exists('inst_paths')) {
        $paths = inst_paths();
        if ($paths['analyses'] !== null) { return $paths['analyses'] . '/' . $name; }
    }
    if ($name === 'transcripts' && cfg('transcripts_dir')) { return rtrim((string)cfg('transcripts_dir'), '/'); }
    return null;
}

/** @return list<array{label:string, url:string}> */
function analysis_row_links(string $platform, array $row): array {
    $links = [];
    foreach (analysis_modules() as $mod) {
        if ($mod['platform'] !== $platform) { continue; }
        $id = ($mod['entity_id'])($row);
        if ($id !== null) { $links[] = ['label' => $mod['label'], 'url' => url('transcript.php?vid=' . $id)]; }
    }
    return $links;
}

/** @return array{txt:?string, json:?string} */
function analysis_transcript_paths(string $vid): array {
    $dir = analysis_dir('transcripts');
    if ($dir === null) { return ['txt' => null, 'json' => null]; }
    $base = $dir . '/' . substr($vid, -2) . '/' . $vid;
    return ['txt' => is_file($base . '.txt') ? $base . '.txt' : null,
            'json' => is_file($base . '.json') ? $base . '.json' : null];
}
