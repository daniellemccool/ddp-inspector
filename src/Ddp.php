<?php
const DDP_SECTION_ORDER = [
    'tiktok_watch_history',
    'tiktok_favorite_videos',
    'tiktok_like_list',
    'tiktok_share_history',
    'tiktok_comments',
];

function ddp_participant_id_from_filename(string $path): string {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    foreach (explode('_', $stem) as $seg) {
        if (str_starts_with($seg, 'participant=')) {
            return substr($seg, strlen('participant='));
        }
    }
    return $stem;
}

/** @return array<string,list<array>>|null */
function ddp_parse_file(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_is_list($data)) { return null; }
    $out = [];
    foreach ($data as $element) {
        if (!is_array($element)) { continue; }
        foreach ($element as $key => $value) {
            if (!is_array($value)) { continue; } // skips "deleted row count" and scalars
            $out[$key] = array_merge($out[$key] ?? [], array_values($value));
        }
    }
    return $out;
}

function ddp_load_dir(string $dir): array {
    $participants = [];
    $skipped = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
        $sections = ddp_parse_file($path);
        if ($sections === null) {
            $skipped[] = ['path' => basename($path), 'reason' => 'not a DDP array (skipped)'];
            continue;
        }
        $id = ddp_participant_id_from_filename($path);
        if (!isset($participants[$id])) {
            $participants[$id] = ['id' => $id, 'files' => [], 'sections' => []];
        }
        $participants[$id]['files'][] = basename($path);
        foreach ($sections as $name => $rows) {
            $participants[$id]['sections'][$name] =
                array_merge($participants[$id]['sections'][$name] ?? [], $rows);
        }
    }
    ksort($participants);
    return ['participants' => $participants, 'skipped' => $skipped];
}
