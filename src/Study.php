<?php
// Data layer core: whether a study reads from the pipeline's analysis database,
// where that file is, whether it has the expected shape, and the one read-only
// connection to it. The database is the pipeline's; this app never writes to it.

/** Tables and columns the app relies on (pipeline schema; see the design spec). */
function study_db_required(): array {
    return [
        'participant' => ['participant_id', 'consent_status', 'consent_recorded_at', 'donation_status',
            'donated_at', 'donation_file', 'donation_file_count', 'n_watch', 'n_like', 'n_favorite',
            'n_share', 'n_comment', 'deleted_watch', 'deleted_like', 'deleted_favorite',
            'deleted_share', 'deleted_comment'],
        'watch_history' => ['participant_id', 'donation_row', 'video_id', 'watched_at', 'watched_at_raw', 'link'],
        'like_history' => ['participant_id', 'donation_row', 'video_id', 'liked_at', 'liked_at_raw', 'link'],
        'favorite_history' => ['participant_id', 'donation_row', 'video_id', 'favorited_at', 'favorited_at_raw', 'link'],
        'share_history' => ['participant_id', 'donation_row', 'video_id', 'shared_at', 'shared_at_raw',
            'content_type', 'method', 'link'],
        'comment_history' => ['participant_id', 'donation_row', 'commented_at', 'commented_at_raw', 'comment'],
        'video' => ['video_id', 'availability', 'description', 'uploader', 'uploader_id', 'channel_id',
            'created_at', 'tiktok_duration_s', 'view_count', 'like_count', 'comment_count',
            'repost_count', 'metadata_fetched_at'],
        'transcription' => ['video_id', 'transcript', 'language', 'audio_duration_s', 'model',
            'transcribed_at', 'raw_signals'],
        'transcription_process' => ['video_id', 'source_url'],
        'meta' => ['key', 'value'],
    ];
}

/** The setup declaration: ['path'=>string] when enabled, else null. */
function study_db_decl(): ?array {
    $d = inst_load()['analyses']['database'] ?? null;
    if (!is_array($d) || empty($d['enabled'])) { return null; }
    return ['path' => (string)($d['path'] ?? '')];
}

/** Where the database file lives: the volume tree in storage mode, config in developer mode. */
function study_db_file(): ?string {
    $dir = inst_paths()['analyses'];
    if ($dir !== null) { return $dir . '/analysis.sqlite'; }
    $f = cfg('analysis_db');
    return (is_string($f) && $f !== '') ? $f : null;
}

/** Read-only handle on any SQLite file. Throws if it cannot be opened or is not a database. */
function study_db_open(string $file): PDO {
    // Opened read-only at the SQLite level, so a write fails even if app code
    // tried one. Chosen form: the open-flags attribute rather than a
    // `file:...?mode=ro&immutable=1` URI. It behaves the same on PHP 8.3 and
    // newer, needs no URI support, and (unlike `immutable=1`) never assumes the
    // file is frozen. Proven by the INSERT-must-throw test in tests/StudyTest.php.
    $flag = defined('Pdo\Sqlite::OPEN_READONLY') ? constant('Pdo\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY;
    $attr = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS;
    return new PDO('sqlite:' . $file, null, null, [
        $attr => $flag,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** Request-level memo: the status of the database file last checked, keyed by
 *  (path, inode, size, mtime), and the read-only connection the shape check
 *  opened when it passed. Returned by reference so study_reset() can clear it. */
function &study_memo(): array {
    static $memo = ['key' => null, 'status' => null, 'pdo' => null];
    return $memo;
}

/** Forget the memoised status and drop the connection (tests that rebuild or swap the file). */
function study_reset(): void {
    $memo = &study_memo();
    $memo = ['key' => null, 'status' => null, 'pdo' => null];
}

/** 'off' | 'missing' | 'invalid' | 'ok' — the shape check runs once per file version per request. */
function study_db_status(): string {
    $file = study_db_file();
    $declared = study_db_decl() !== null || (inst_paths()['analyses'] === null && $file !== null);
    if (!$declared || $file === null) { return 'off'; }
    clearstatcache(true, $file);
    $st = @stat($file);
    if ($st === false || !is_file($file)) { return 'missing'; }
    $key = $file . '|' . $st['ino'] . '|' . $st['size'] . '|' . $st['mtime'];
    $memo = &study_memo();
    if ($memo['key'] === $key) { return $memo['status']; }
    $memo = ['key' => $key, 'status' => 'invalid', 'pdo' => null];
    try {
        $pdo = study_db_open($file);
        foreach (study_db_required() as $table => $cols) {
            $have = [];
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $r) { $have[$r['name']] = true; }
            foreach ($cols as $c) { if (!isset($have[$c])) { return 'invalid'; } }
        }
    } catch (Throwable $e) {
        return 'invalid';
    }
    $memo['status'] = 'ok';
    $memo['pdo'] = $pdo;
    return 'ok';
}

function study_mode(): string { return study_db_status() === 'ok' ? 'database' : 'raw'; }

/** One read-only connection per database version per request; null unless the database is present and well-formed. */
function study_db(): ?PDO {
    if (study_db_status() !== 'ok') { return null; }
    return study_memo()['pdo'];
}

function study_db_notice(): ?string {
    switch (study_db_status()) {
        case 'missing': return "The analysis database hasn't been fetched yet — run Check & fetch in Settings.";
        case 'invalid': return "The analysis database doesn't look like a pipeline analysis database.";
        default: return null;
    }
}

// ---------------------------------------------------------------------------
// Data layer. Pages render only from these four functions' return values.
// Each one dispatches to its backend: study_raw_* (the donation files, today's
// logic) or, once present, study_db_* (the analysis database); the database
// branch goes in front of the raw call as `if (study_mode() === 'database')`.
// ---------------------------------------------------------------------------

/**
 * The participant list: ddp_load_dir_summaries()' shape.
 * @return array{participants: array<string, array{id:string, platforms: array<string, array{tables: array<string, array{count:int, earliest:?int, latest:?int}>, deleted: array<string,int>, videos_total:?int, videos_transcribed:?int}>}>, skipped: list<array{path:string, participant:string, kind:string, reason:string}>}
 */
function study_participants(): array {
    if (study_mode() === 'database') { return study_db_participants(); }
    return study_raw_participants();
}

/**
 * One participant's page: per platform its title, notes, per-table scope and
 * the sections to show (tables with rows), each with a deterministic sample.
 * Per section: `total` is every row; `with_artifacts` is the pool the sample is
 * drawn from and `hidden` the rows left out of it ($all = true hides nothing,
 * so then with_artifacts = total and hidden = 0).
 * @return ?array{id:string, platforms: array<string, array{title:string, notes: list<string>, scope: array<string, array{count:int, earliest:?int, latest:?int}>, sections: list<array{key:string, title:string, description:string, columns: list<string>, total:int, with_artifacts:int, hidden:int, deleted:int, sample: list<array{row: array<string,string>, links: list<array{label:string, url:string}>, reason:?string}>}>}>}
 */
function study_participant(string $id, int $n, int $seed, bool $all): ?array {
    return study_raw_participant($id, $n, $seed, $all);
}

/**
 * One video's transcript: `meta` in the artifact JSON's shape (video_metadata,
 * source_url, language_detected, duration_s, raw_signals); each part null when
 * unknown. Null for a malformed video id.
 * @return ?array{text:?string, meta:?array, availability:?string}
 */
function study_transcript(string $vid): ?array {
    if (!preg_match('/^\d{19}$/D', $vid)) { return null; }
    return study_raw_transcript($vid);
}

function study_donation_count(): int {
    if (study_mode() === 'database') { return study_db_donation_count(study_db()); }
    return inst_donation_count();
}

// ---- Raw backend: the donation files and the unpacked transcripts tree ----
// analysis_available_ids() sweeps the transcripts tree only when that
// directory exists; otherwise it returns no ids without touching the disk.

function study_raw_participants(): array {
    $ids = analysis_available_ids('transcripts');
    $ctx = ['ids' => $ids, 'fp' => analysis_ids_fingerprint($ids)];
    return ddp_load_dir_summaries((string)inst_effective_ddp_dir(), $ctx);
}

function study_raw_participant(string $id, int $n, int $seed, bool $all): ?array {
    $p = ddp_load_participant((string)inst_effective_ddp_dir(), $id);
    if ($p === null) { return null; }
    $docs = flows_load_all();
    $ids = $all ? [] : analysis_available_ids('transcripts');
    $platforms = [];
    foreach ($p['platforms'] as $slug => $entry) {
        $doc = $docs[$slug] ?? null;
        $match = flows_match($entry['tables'], $doc);
        $order = flows_table_order($match);
        $notes = [];
        if ($entry['superseded']) {
            $notes[] = 'Note: this participant donated more than once for this platform; showing the most recent donation ('
                . count($entry['superseded']) . ' older file(s) ignored).';
        }
        $sections = [];
        foreach ($order as $name) {
            $rows = $entry['tables'][$name] ?? [];
            if (!$rows) { continue; }
            [$pool, $hidden] = $all ? [$rows, 0] : analysis_filter_rows_with_artifacts($slug, $rows, $ids);
            $sec = ($match[$name] ?? null) !== null ? $doc['sections'][$match[$name]] : null;
            $sample = [];
            foreach (sample_rows($pool, $n, $seed, (string)$name) as $row) {
                $cells = [];
                foreach ($row as $k => $v) { $cells[$k] = is_scalar($v) ? (string)$v : ''; }
                $sample[] = ['row' => $cells, 'links' => analysis_row_links($slug, $row), 'reason' => null];
            }
            $sections[] = [
                'key' => (string)$name,
                'title' => $sec !== null ? $sec['title'] : flows_prettify((string)$name),
                'description' => $sec !== null ? $sec['description'] : '',
                'columns' => $sec !== null ? $sec['vars'] : array_map('strval', array_keys($rows[0])),
                'total' => count($rows),
                'with_artifacts' => count($pool),
                'hidden' => $hidden,
                'deleted' => (int)($entry['deleted'][$name] ?? 0),
                'sample' => $sample,
            ];
        }
        $platforms[$slug] = [
            'title' => $doc['platform_title'] ?? ucfirst($slug),
            'notes' => $notes,
            'scope' => stats_platform_scope($entry['tables'], $order)['tables'],
            'sections' => $sections,
        ];
    }
    return ['id' => $p['id'], 'platforms' => $platforms];
}

function study_raw_transcript(string $vid): array {
    $paths = analysis_transcript_paths($vid);
    $text = $paths['txt'] !== null ? @file_get_contents($paths['txt']) : false;
    $meta = $paths['json'] !== null ? json_decode((string)@file_get_contents($paths['json']), true) : null;
    return ['text' => $text === false ? null : $text, 'meta' => is_array($meta) ? $meta : null, 'availability' => null];
}
