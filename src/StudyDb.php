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
            if ($p["deleted_$s"] !== null) { $deleted[$key] = (int)$p["deleted_$s"]; }
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
    study_db_list_failed(false);
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
        study_db_list_failed(true);
        return null;
    }
}

/** Whether the last study_db_participants() call got its empty list from a
 *  failed query rather than an empty database (bin/warm-cache.php exits 1 on
 *  it). Pass a bool to set it. */
function study_db_list_failed(?bool $set = null): bool {
    static $failed = false;
    if ($set !== null) { $failed = $set; }
    return $failed;
}

/** Per section: the donated row keys and the columns they come from (spec §5.3;
 *  key names as real TikTok donations spell them). */
function study_db_row_keys(): array {
    return [
        'watch' => ['Date' => 'watched_at_raw', 'Link' => 'link'],
        'like' => ['Date' => 'liked_at_raw', 'Link' => 'link'],
        'favorite' => ['Date' => 'favorited_at_raw', 'Link' => 'link'],
        'share' => ['Date' => 'shared_at_raw', 'Link' => 'link', 'Method' => 'method', 'SharedContent' => 'content_type'],
        'comment' => ['Comment' => 'comment', 'Date' => 'commented_at_raw'],
    ];
}

/**
 * The deterministic sample of one section as SQL over `$table h`: rows of
 * participant :pid passing $filter, ordered by a (seed, section, donation_row)
 * hash, first :n. Yields donation_row and the sort key k. Stage 1 is
 * `(donation_row * 2654435761 + seed * 40503 + salt) % 2^32`; on its own a
 * new seed only rotates that order (reshuffle would change ~1 row), so a fixed
 * xorshift-multiply finalizer follows (XOR as (a|b)-(a&b); SQLite has no ^).
 */
function study_db_sample_sql(string $table, string $filter): string {
    $q = "SELECT h.donation_row, ((h.donation_row * 2654435761 + :seed * 40503 + :salt) % 4294967296) AS k"
        . " FROM $table h WHERE h.participant_id = :pid$filter";
    foreach (['xs', 'mul', 'xs', 'mul', 'xs'] as $step) {
        $e = $step === 'xs' ? '((k | (k >> 16)) - (k & (k >> 16)))' : '((k * 73244475) % 4294967296)';
        $q = "SELECT donation_row, $e AS k FROM ($q)";
    }
    return "$q ORDER BY k, donation_row LIMIT :n";
}

/** The participant page from the database; null for a malformed or unknown id,
 *  a participant who did not donate, or a database error (logged). */
function study_db_participant(string $id, int $n, int $seed, bool $all): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id)) { return null; }
    $db = study_db();
    if ($db === null) { return null; }
    try {
        return study_db_participant_compute($db, $id, $n, $seed, $all);
    } catch (PDOException $e) {
        error_log('DDP Inspector: analysis database error (participant page): ' . $e->getMessage());
        return null;
    }
}

function study_db_participant_compute(PDO $db, string $id, int $n, int $seed, bool $all): ?array {
    $st = $db->prepare('SELECT * FROM participant WHERE participant_id = :pid');
    $st->execute([':pid' => $id]);
    $p = $st->fetch();
    if ($p === false || $p['donation_status'] !== 'donated') { return null; }
    $slug = 'tiktok';
    $doc = flows_load_all()[$slug] ?? null;
    $sections = study_db_sections();
    $rowKeys = study_db_row_keys();

    // flows_match() reads column names from rows: one key-only row per non-empty
    // section (an empty one never matches, as in raw mode).
    $present = []; $tables = [];
    foreach ($sections as $s => [$key]) {
        if ($p["n_$s"] === null) { continue; }
        $present[$key] = $s;
        $tables[$key] = (int)$p["n_$s"] > 0 ? [array_fill_keys(array_keys($rowKeys[$s]), '')] : [];
    }
    $match = flows_match($tables, $doc);
    $order = flows_table_order($match);

    $scope = []; $out = [];
    foreach ($order as $key) {
        $s = $present[$key];
        [, $table, $col] = $sections[$s];
        $total = (int)$p["n_$s"];
        $r = $db->prepare("SELECT MIN($col) AS lo, MAX($col) AS hi FROM $table WHERE participant_id = :pid");
        $r->execute([':pid' => $id]);
        $range = $r->fetch() ?: ['lo' => null, 'hi' => null];
        $scope[$key] = ['count' => $total,
            'earliest' => $range['lo'] === null ? null : stats_parse_date_any((string)$range['lo']),
            'latest' => $range['hi'] === null ? null : stats_parse_date_any((string)$range['hi'])];
        if ($total === 0) { continue; }

        $hasVideo = $s !== 'comment';
        $filter = ($hasVideo && !$all) ? ' AND EXISTS (SELECT 1 FROM transcription t WHERE t.video_id = h.video_id)' : '';
        if ($filter === '') {
            $pool = $total;
        } else {
            $c = $db->prepare("SELECT COUNT(*) FROM $table h WHERE h.participant_id = :pid$filter");
            $c->execute([':pid' => $id]);
            $pool = (int)$c->fetchColumn();
        }
        $cols = implode(', ', array_map(fn($c) => "h.$c", array_values($rowKeys[$s])));
        $sql = $hasVideo
            ? "SELECT $cols, h.video_id, t.video_id AS transcribed, v.availability FROM (" . study_db_sample_sql($table, $filter) . ') s'
              . " JOIN $table h ON h.participant_id = :pid AND h.donation_row = s.donation_row"
              . ' LEFT JOIN transcription t ON t.video_id = h.video_id LEFT JOIN video v ON v.video_id = h.video_id'
              . ' ORDER BY s.k, s.donation_row'
            : "SELECT $cols FROM (" . study_db_sample_sql($table, '') . ') s'
              . " JOIN $table h ON h.participant_id = :pid AND h.donation_row = s.donation_row ORDER BY s.k, s.donation_row";
        $q = $db->prepare($sql);
        $q->bindValue(':pid', $id);
        $q->bindValue(':seed', $seed & 0xFFFFFFFF, PDO::PARAM_INT);
        $q->bindValue(':salt', crc32($key), PDO::PARAM_INT);
        $q->bindValue(':n', max(0, $n), PDO::PARAM_INT);
        $q->execute();
        $sample = [];
        foreach ($q as $row) {
            $cells = [];
            foreach ($rowKeys[$s] as $k => $c) { $cells[$k] = $row[$c] === null ? '' : (string)$row[$c]; }
            $links = []; $reason = null;
            if ($hasVideo && $row['video_id'] !== null) {
                if ($row['transcribed'] !== null) {
                    $links[] = ['label' => analysis_modules()['transcripts']['label'], 'url' => url('transcript.php?vid=' . (string)$row['video_id'])];
                } elseif ($row['availability'] !== null) {
                    $reason = study_availability_label((string)$row['availability']);
                }
            }
            $sample[] = ['row' => $cells, 'links' => $links, 'reason' => $reason];
        }
        $sec = ($match[$key] ?? null) !== null ? $doc['sections'][$match[$key]] : null;
        $out[] = [
            'key' => $key,
            'title' => $sec !== null ? $sec['title'] : flows_prettify($key),
            'description' => $sec !== null ? $sec['description'] : '',
            'columns' => $sec !== null ? $sec['vars'] : array_keys($rowKeys[$s]),
            'total' => $total,
            'with_artifacts' => $pool,
            'hidden' => max(0, $total - $pool),
            'deleted' => (int)($p["deleted_$s"] ?? 0),
            'sample' => $sample,
        ];
    }
    $notes = [];
    $files = (int)$p['donation_file_count'];
    if ($files > 1) { $notes[] = "Note: this participant submitted $files files; the first one with data is shown."; }
    return ['id' => (string)$p['participant_id'], 'platforms' => [$slug => [
        'title' => $doc['platform_title'] ?? ucfirst($slug),
        'notes' => $notes,
        'scope' => $scope,
        'sections' => $out,
    ]]];
}

/** One video's transcript from the database, in the raw artifact's shape; null
 *  for a malformed id, no database, or a database error (logged). A video known
 *  only from `video` yields null text and meta with its availability set. */
function study_db_transcript(string $vid): ?array {
    if (!preg_match('/^\d{19}$/D', $vid)) { return null; }
    $db = study_db();
    if ($db === null) { return null; }
    try {
        $st = $db->prepare('SELECT t.transcript, t.language, t.audio_duration_s, t.raw_signals, p.source_url,'
            . ' v.availability, v.description, v.uploader, v.created_at, v.view_count, v.like_count,'
            . ' v.comment_count, v.metadata_fetched_at, (t.video_id IS NOT NULL) AS has_tx, (v.video_id IS NOT NULL) AS has_video'
            . ' FROM (SELECT :vid AS video_id) q'
            . ' LEFT JOIN transcription t ON t.video_id = q.video_id'
            . ' LEFT JOIN video v ON v.video_id = q.video_id'
            . ' LEFT JOIN transcription_process p ON p.video_id = q.video_id');
        $st->execute([':vid' => $vid]);
        $r = $st->fetch();
    } catch (PDOException $e) {
        error_log('DDP Inspector: analysis database error (transcript page): ' . $e->getMessage());
        return null;
    }
    $availability = $r['has_video'] ? ($r['availability'] === null ? null : (string)$r['availability']) : null;
    if (!$r['has_tx']) { return ['text' => null, 'meta' => null, 'availability' => $availability]; }
    $vm = array_filter([
        'video_description' => $r['description'], 'uploader' => $r['uploader'], 'video_created_at' => $r['created_at'],
        'view_count' => $r['view_count'], 'like_count' => $r['like_count'], 'comment_count' => $r['comment_count'],
        'metadata_fetched_at' => $r['metadata_fetched_at'],
    ], fn($v) => $v !== null);
    $signals = $r['raw_signals'] === null ? null : json_decode((string)$r['raw_signals'], true);
    $meta = ['video_id' => $vid, 'source_url' => $r['source_url'], 'language_detected' => $r['language'],
        'duration_s' => $r['audio_duration_s'], 'raw_signals' => is_array($signals) ? $signals : null];
    if ($vm !== []) { $meta['video_metadata'] = $vm; }
    return ['text' => $r['transcript'] === null ? '' : (string)$r['transcript'], 'meta' => $meta, 'availability' => $availability];
}
