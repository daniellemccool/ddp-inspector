<?php
const DDP_VIDEO_SECTIONS = [
    'tiktok_watch_history',
    'tiktok_favorite_videos',
    'tiktok_like_list',
];

function stats_canonical_video_id(string $url): ?string {
    $re = '~^https?://(?:www\.)?(?:tiktokv|tiktok)\.com/(?:share/video|@[^/]+/video)/(\d{19})(?:/|\?|$)~';
    if (preg_match($re, $url, $m)) { return $m[1]; }
    return null;
}

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

/** @param list<array> $rows */
function stats_section_summary(array $rows): array {
    $count = count($rows);
    $earliest = null; $latest = null;
    foreach ($rows as $row) {
        $ts = isset($row['Date']) ? stats_parse_date((string)$row['Date']) : null;
        if ($ts === null) { continue; }
        if ($earliest === null || $ts < $earliest) { $earliest = $ts; }
        if ($latest === null || $ts > $latest) { $latest = $ts; }
    }
    return ['count' => $count, 'earliest' => $earliest, 'latest' => $latest];
}

function stats_unique_video_count(array $sections): int {
    $ids = [];
    foreach (DDP_VIDEO_SECTIONS as $name) {
        foreach ($sections[$name] ?? [] as $row) {
            if (!isset($row['Link'])) { continue; }
            $id = stats_canonical_video_id((string)$row['Link']);
            if ($id !== null) { $ids[$id] = true; }
        }
    }
    return count($ids);
}

function stats_participant_scope(array $participant): array {
    $sections = $participant['sections'];
    $ordered = [];
    $names = array_merge(
        array_values(array_filter(DDP_SECTION_ORDER, fn($n) => isset($sections[$n]))),
        array_values(array_filter(array_keys($sections), fn($n) => !in_array($n, DDP_SECTION_ORDER, true)))
    );
    $total = 0; $earliest = null; $latest = null;
    foreach ($names as $name) {
        $s = stats_section_summary($sections[$name]);
        $ordered[$name] = $s;
        $total += $s['count'];
        if ($s['earliest'] !== null && ($earliest === null || $s['earliest'] < $earliest)) { $earliest = $s['earliest']; }
        if ($s['latest'] !== null && ($latest === null || $s['latest'] > $latest)) { $latest = $s['latest']; }
    }
    return [
        'sections' => $ordered,
        'unique_videos' => stats_unique_video_count($sections),
        'total_rows' => $total,
        'earliest' => $earliest,
        'latest' => $latest,
    ];
}
