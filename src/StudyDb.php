<?php
// Database backend of the data layer (src/Study.php dispatches here when
// study_mode() === 'database'). Read-only: plain SELECTs on study_db().

/** Section => [donation section key, history table, timestamp column]; the
 *  section name is also the participant.n_<s> / deleted_<s> suffix. */
function study_db_sections(): array {
    return [
        'watch' => ['tiktok_watch_history', 'watch_history', 'watched_at'],
        'like' => ['tiktok_like_list', 'like_history', 'liked_at'],
        'favorite' => ['tiktok_favorite_videos', 'favorite_history', 'favorited_at'],
        'share' => ['tiktok_share_history', 'share_history', 'shared_at'],
        'comment' => ['tiktok_comments', 'comment_history', 'commented_at'],
    ];
}

function study_db_donation_count(PDO $db): int {
    try {
        return (int)$db->query("SELECT COUNT(*) FROM participant WHERE donation_status = 'donated'")->fetchColumn();
    } catch (PDOException $e) {
        error_log('DDP Inspector: analysis database error (donation count): ' . $e->getMessage());
        return 0;
    }
}

/** Compute the participant list: one query per measure, never per participant. */
function study_db_participants_compute(PDO $db): array {
    $sections = study_db_sections();
    $range = [];
    foreach ($sections as $s => [, $table, $col]) {
        foreach ($db->query("SELECT participant_id, MIN($col) AS lo, MAX($col) AS hi FROM $table WHERE $col IS NOT NULL GROUP BY participant_id") as $r) {
            $range[$s][(string)$r['participant_id']] = [stats_parse_date_any((string)$r['lo']), stats_parse_date_any((string)$r['hi'])];
        }
    }
    // Same definition as raw mode: distinct non-NULL video ids over watch, like, favorite, share.
    $cov = [];
    $sql = 'SELECT u.participant_id AS pid, COUNT(*) AS total, COUNT(t.video_id) AS transcribed FROM ('
        . "SELECT participant_id, video_id FROM watch_history WHERE video_id IS NOT NULL UNION "
        . "SELECT participant_id, video_id FROM like_history WHERE video_id IS NOT NULL UNION "
        . "SELECT participant_id, video_id FROM favorite_history WHERE video_id IS NOT NULL UNION "
        . "SELECT participant_id, video_id FROM share_history WHERE video_id IS NOT NULL"
        . ') u LEFT JOIN transcription t ON t.video_id = u.video_id GROUP BY u.participant_id';
    foreach ($db->query($sql) as $r) { $cov[(string)$r['pid']] = [(int)$r['total'], (int)$r['transcribed']]; }

    $participants = []; $skipped = [];
    foreach ($db->query('SELECT * FROM participant ORDER BY participant_id') as $p) {
        $id = (string)$p['participant_id'];
        if ($p['donation_status'] !== 'donated') {
            $declined = $p['consent_status'] === 'consent declined';
            $skipped[] = ['path' => '', 'participant' => $id,
                'kind' => $declined ? 'declined_consent' : 'not_donated',
                'reason' => $declined ? 'declined consent' : 'consented, did not donate'];
            continue;
        }
        $tables = []; $deleted = [];
        foreach ($sections as $s => [$key]) {
            if ($p["n_$s"] !== null) {
                [$lo, $hi] = $range[$s][$id] ?? [null, null];
                $tables[$key] = ['count' => (int)$p["n_$s"], 'earliest' => $lo, 'latest' => $hi];
            }
            if ($p["deleted_$s"] !== null) { $deleted[$s] = (int)$p["deleted_$s"]; }
        }
        [$vt, $vr] = $cov[$id] ?? [0, 0];
        $participants[$id] = ['id' => $id, 'platforms' => ['tiktok' => [
            'tables' => $tables, 'deleted' => $deleted, 'videos_total' => $vt, 'videos_transcribed' => $vr]]];
    }
    return ['participants' => $participants, 'skipped' => $skipped];
}

/** The participant list, cached as <cache>/study/participants-<size>-<mtime>.json
 *  (keyed by the database file; writing a new one removes the others). */
function study_db_participants(): array {
    $db = study_db();
    $file = study_db_file();
    $cache = inst_paths()['cache'];
    if ($db === null || $file === null) { return ['participants' => [], 'skipped' => []]; }
    $empty = ['participants' => [], 'skipped' => []];
    if ($cache === null) { return study_db_try_compute($db) ?? $empty; }
    clearstatcache(true, $file);
    $dir = $cache . '/study';
    $path = $dir . '/participants-' . filesize($file) . '-' . filemtime($file) . '.json';
    $hit = inst_read_json($path);
    if (is_array($hit) && isset($hit['participants'], $hit['skipped'])) { return $hit; }
    $out = study_db_try_compute($db);
    if ($out === null) { return $empty; } // a failed run is never cached
    if (inst_write_json_atomic($path, $out)) {
        foreach (glob($dir . '/participants-*.json') ?: [] as $f) { if ($f !== $path) { @unlink($f); } }
    }
    return $out;
}

/** The computed list, or null (logged) when the database errors: locked, corrupt, or a shape the check missed. */
function study_db_try_compute(PDO $db): ?array {
    try {
        return study_db_participants_compute($db);
    } catch (PDOException $e) {
        error_log('DDP Inspector: analysis database error (participant list): ' . $e->getMessage());
        return null;
    }
}
