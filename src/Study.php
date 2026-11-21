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

/** 'off' | 'missing' | 'invalid' | 'ok' */
function study_db_status(): string {
    $file = study_db_file();
    $declared = study_db_decl() !== null || (inst_paths()['analyses'] === null && $file !== null);
    if (!$declared || $file === null) { return 'off'; }
    if (!is_file($file)) { return 'missing'; }
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
    return 'ok';
}

function study_mode(): string { return study_db_status() === 'ok' ? 'database' : 'raw'; }

/** One read-only connection per request; null unless the database is present and well-formed. */
function study_db(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (study_db_status() !== 'ok') { return null; }
    try { $pdo = study_db_open((string)study_db_file()); } catch (Throwable $e) { return null; }
    return $pdo;
}

function study_db_notice(): ?string {
    switch (study_db_status()) {
        case 'missing': return "The analysis database hasn't been fetched yet — run Check & fetch in Settings.";
        case 'invalid': return "The analysis database doesn't look like a pipeline analysis database.";
        default: return null;
    }
}
