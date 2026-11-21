-- Synthetic data in the pipeline's analysis-database shape. No real study data.
CREATE TABLE participant (
  participant_id TEXT PRIMARY KEY, consent_status TEXT, consent_recorded_at TEXT,
  donation_status TEXT NOT NULL, donated_at TEXT, donation_file TEXT,
  donation_file_count INTEGER NOT NULL,
  n_watch INTEGER, n_like INTEGER, n_favorite INTEGER, n_share INTEGER, n_comment INTEGER,
  deleted_watch INTEGER, deleted_like INTEGER, deleted_favorite INTEGER, deleted_share INTEGER, deleted_comment INTEGER);
CREATE TABLE watch_history (participant_id TEXT, donation_row INTEGER, video_id TEXT, watched_at TEXT, watched_at_raw TEXT, link TEXT, PRIMARY KEY (participant_id, donation_row));
CREATE TABLE like_history (participant_id TEXT, donation_row INTEGER, video_id TEXT, liked_at TEXT, liked_at_raw TEXT, link TEXT, PRIMARY KEY (participant_id, donation_row));
CREATE TABLE favorite_history (participant_id TEXT, donation_row INTEGER, video_id TEXT, favorited_at TEXT, favorited_at_raw TEXT, link TEXT, PRIMARY KEY (participant_id, donation_row));
CREATE TABLE share_history (participant_id TEXT, donation_row INTEGER, video_id TEXT, shared_at TEXT, shared_at_raw TEXT, content_type TEXT, method TEXT, link TEXT, PRIMARY KEY (participant_id, donation_row));
CREATE TABLE comment_history (participant_id TEXT, donation_row INTEGER, commented_at TEXT, commented_at_raw TEXT, comment TEXT, PRIMARY KEY (participant_id, donation_row));
CREATE TABLE video (
  video_id TEXT PRIMARY KEY, availability TEXT NOT NULL, description TEXT, uploader TEXT, uploader_id TEXT,
  channel_id TEXT, created_at TEXT, tiktok_duration_s REAL, view_count INTEGER, like_count INTEGER,
  comment_count INTEGER, repost_count INTEGER, metadata_fetched_at TEXT);
CREATE TABLE transcription (video_id TEXT PRIMARY KEY, transcript TEXT, language TEXT, audio_duration_s REAL, model TEXT, transcribed_at TEXT, raw_signals TEXT);
CREATE TABLE transcription_process (video_id TEXT PRIMARY KEY, source_url TEXT, attempts INTEGER, last_error TEXT);
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);

INSERT INTO meta VALUES ('schema', 'provenance database schema 7'), ('built_at', '2026-02-01T00:00:00Z');

INSERT INTO participant VALUES
 ('pa', 'consent accepted', '2026-01-10T09:00:00Z', 'donated', '2026-01-16T03:30:00Z', 'pa-file-1.json', 1, 4, NULL, 2, 1, 1, 0, NULL, 1, 0, 0),
 ('pb', 'consent accepted', '2026-01-11T09:00:00Z', 'donated', '2026-01-17T10:00:00Z', 'pb-file-1.json', 2, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0),
 ('pc', 'consent accepted', '2026-01-12T09:00:00Z', 'donated', '2026-01-18T11:00:00Z', 'pc-file-1.json', 1, 0, NULL, NULL, NULL, 2, 0, NULL, NULL, NULL, 0),
 ('pd', 'consent accepted', '2026-01-13T09:00:00Z', 'not donated', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 ('pe', 'consent declined', '2026-01-14T09:00:00Z', 'not donated', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

INSERT INTO watch_history VALUES
 ('pa', 1, '7000000000000001001', '2026-01-15T10:00:00Z', '2026-01-15 10:00:00', 'https://example.test/v/7000000000000001001'),
 ('pa', 2, '7000000000000001002', '2026-01-15T10:05:00Z', '2026-01-15 10:05:00', 'https://example.test/v/7000000000000001002'),
 ('pa', 3, NULL, '2026-01-15T10:10:00Z', '2026-01-15 10:10:00', 'https://example.test/v/unresolved'),
 ('pa', 4, '7000000000000001003', NULL, '1970-01-01 00:00:00', 'https://example.test/v/7000000000000001003'),
 ('pb', 1, '7000000000000001001', '2026-01-16T08:00:00Z', '2026-01-16 08:00:00', 'https://example.test/v/7000000000000001001'),
 ('pb', 2, '7000000000000001004', '2026-01-16T08:01:00Z', '2026-01-16 08:01:00', 'https://example.test/v/7000000000000001004');
INSERT INTO like_history VALUES
 ('pb', 1, '7000000000000001005', '2026-01-16T08:02:00Z', '2026-01-16 08:02:00', 'https://example.test/v/7000000000000001005');
INSERT INTO favorite_history VALUES
 ('pa', 1, '7000000000000001006', '2026-01-15T11:00:00Z', '2026-01-15 11:00:00', 'https://example.test/v/7000000000000001006'),
 ('pa', 2, '7000000000000001007', '2026-01-15T11:01:00Z', '2026-01-15 11:01:00', 'https://example.test/v/7000000000000001007');
INSERT INTO share_history VALUES
 ('pa', 1, '7000000000000001008', '2026-01-15T12:00:00Z', '2026-01-15 12:00:00', 'video', 'copy link', 'https://example.test/v/7000000000000001008');
INSERT INTO comment_history VALUES
 ('pa', 1, '2026-01-15T13:00:00Z', '2026-01-15 13:00:00', 'first synthetic comment'),
 ('pc', 1, '2026-01-18T09:00:00Z', '2026-01-18 09:00:00', 'second synthetic comment'),
 ('pc', 2, '2026-01-18T09:05:00Z', '2026-01-18 09:05:00', 'third synthetic comment');

INSERT INTO video VALUES
 ('7000000000000001001', 'transcribed', 'synthetic clip one', 'uploader_a', 'ua1', 'ch1', '2025-12-01T00:00:00Z', 12.5, 1000, 100, 10, 5, '2026-01-20T00:00:00Z'),
 ('7000000000000001002', 'removed_or_private', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-20T00:00:00Z'),
 ('7000000000000001003', 'removed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-20T00:00:00Z'),
 ('7000000000000001004', 'region_gated', 'synthetic gated', 'uploader_b', 'ub1', 'ch2', '2025-12-02T00:00:00Z', 30, 50, 5, 1, 0, '2026-01-20T00:00:00Z'),
 ('7000000000000001005', 'login_gated', 'synthetic login', 'uploader_c', 'uc1', 'ch3', '2025-12-03T00:00:00Z', 8, 20, 2, 0, 0, '2026-01-20T00:00:00Z'),
 ('7000000000000001006', 'photo_no_audio', 'synthetic photo', 'uploader_d', 'ud1', 'ch4', '2025-12-04T00:00:00Z', NULL, 70, 7, 1, 1, '2026-01-20T00:00:00Z'),
 ('7000000000000001007', 'retries_exhausted', 'synthetic retry', 'uploader_e', 'ue1', 'ch5', '2025-12-05T00:00:00Z', 45, 90, 9, 2, 1, '2026-01-20T00:00:00Z'),
 ('7000000000000001008', 'not_attempted', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 ('7000000000000001009', 'transcribed', 'synthetic clip two', 'uploader_a', 'ua1', 'ch1', '2025-12-06T00:00:00Z', 20, 300, 30, 3, 2, '2026-01-20T00:00:00Z');

INSERT INTO transcription VALUES
 ('7000000000000001001', 'Hello world', 'en', 12.1, 'synthetic-model', '2026-01-21T00:00:00Z',
  '{"schema_version":"1","language":"en","lang_probs":null,"segments":[{"no_speech_prob":0.01,"tokens":[{"id":1,"text":"[_BEG_]","p":0.9,"plog":-0.1},{"id":2,"text":" Hello","p":0.8,"plog":-0.2},{"id":3,"text":" world","p":0.7,"plog":-0.3}]}]}'),
 ('7000000000000001009', 'Another clip', 'en', 19.8, 'synthetic-model', '2026-01-21T00:05:00Z', NULL);

INSERT INTO transcription_process VALUES
 ('7000000000000001001', 'https://example.test/v/7000000000000001001', 1, NULL),
 ('7000000000000001007', 'https://example.test/v/7000000000000001007', 3, 'synthetic error');
