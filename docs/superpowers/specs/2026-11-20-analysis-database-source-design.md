# DDP Inspector: read studies from the pipeline's analysis database (design)

**Date:** 2026-11-20
**Status:** approved 2026-11-20 (builder check done)
**Builds on:** `2026-07-29-ddp-inspector-catalog-component-design.md` (the
SRC component, Setup wizard, refresh machinery) and the Yoda login rework
(`provisioning/docs/OPERATIONS.md` §4).

## 1. Why

The inspector is a researcher's own browsing tool: click into participants,
their donated sections and the transcripts of videos they watched, to get a
feel for the data, check on things, see how a campaign is going, and now
and then pull something up to show a teammate. It is not a demo or overview
product.

On a real transcript-heavy study it does not work at all. The participant
list scans the whole unpacked transcripts tree on every page load
(`analysis_available_ids()`); with ~2.4 million transcripts that scan alone
took 20 minutes on the workspace, so every load hit nginx's 60 s timeout
(504). Holding that ID set in PHP memory is also close to the 512 MB limit.

Studies that have transcripts are processed by the (Rust) transcription
pipeline, whose analysis package already contains a finished, indexed SQLite
database (`analysis.sqlite`) with participants, every donated history,
video metadata and transcripts. The inspector should read that instead of
re-deriving it from raw files.

## 2. Goals, non-goals, anti-goals

Goals
- A study **with analyses** is browsed entirely from its `analysis.sqlite`:
  participant list (with transcript coverage), participant page (sections,
  sampling, transcript links), transcript page.
- A study **without analyses** (just the bare Next export dropped in) keeps
  working exactly as today, with no other data present.
- First load of a page may take 5–10 s; after that, normal page speed.
  Page cost must not grow with the number of transcripts in the corpus.
- The 33 GB database is downloaded only when it has changed.

Non-goals
- A study overview / dashboard page. (Explicitly not wanted.)
- Building our own index of transcripts, or ingesting raw files into SQLite
  (a Rust ingest was considered and is not needed: the pipeline already is
  the ingest).
- Transcripts for bare exports. Bare = donations only.
- The general per-enrichment configuration (transcripts, comments, …) beyond
  the one analysis-database item introduced here; this design leaves room
  for it (§5.1) but does not build it.
- Comments shown alongside videos (they cannot be linked; see the package
  codebook).

Anti-goals (would be a failure even if it technically works)
- Any page load whose cost scales with corpus size (transcript count, total
  watch rows across all participants) without a cache in front of it.
- Writing anything to the pipeline's database or to Yoda.
- Re-downloading the database on every refresh.
- Python anywhere (repo rule).

## 3. Constraints

- App: plain PHP 8.3 + php-fpm behind SRC-Nginx's SRAM-gated location; no
  framework, no build step, no CDN. Tests: `tests/run.php` (PHP, run on a
  workspace or any machine with php-cli), shell harnesses under
  `provisioning/scripts/`.
- nginx gives PHP 60 s (`fastcgi_read_timeout` default); PHP
  `max_execution_time = 120`, `memory_limit = 512M` (`99-ddp-inspector.ini.j2`).
- Data reaches the workspace only through the refresh script (gocmd for
  Yoda with the researcher's own login, rclone for Research Drive, a
  symlink for a local folder) into the volume tree
  `<storage_root>/ddp-inspector/{config,data,state,cache}`.
- The analysis database is the pipeline's contract, documented by its
  package README and codebook. Its relevant shape (as of package schema
  "provenance database schema 7"):
  - `participant(participant_id PK, consent_status, donation_status,
    donated_at, donation_file, donation_file_count, n_watch, n_like,
    n_favorite, n_share, n_comment, deleted_watch … deleted_comment)`;
    real participants only (test accounts excluded by the pipeline).
  - `watch_history`, `like_history`, `favorite_history`, `share_history`,
    `comment_history`, each `PRIMARY KEY (participant_id, donation_row)`,
    with `<verb>_at` (parsed, UTC), `<verb>_at_raw` (as donated) and, except
    comments, `video_id` + `link`; `share_history` adds `content_type`,
    `method`; `comment_history` has `comment` and no video.
  - `video(video_id PK, availability, description, uploader, uploader_id,
    channel_id, created_at, tiktok_duration_s, view_count, like_count,
    comment_count, repost_count, metadata_fetched_at)`; `availability` is
    `transcribed`, `removed_or_private`, `removed`, `region_gated`,
    `login_gated`, `photo_no_audio`, `retries_exhausted` or `not_attempted`.
  - `transcription(video_id PK, transcript, language, audio_duration_s,
    model, transcribed_at, raw_signals)`; `raw_signals` is the same JSON the
    transcript page already parses (`{schema_version, language, lang_probs,
    segments:[{no_speech_prob, tokens:[{id,text,p,plog}]}]}`).
  - Journal mode `delete` (not WAL): it opens read-only without side files.
  - `video_id` is 19-digit TEXT; keep it a string everywhere.
  - Value sets: `consent_status` ∈ {`consent accepted`, `consent declined`};
    `donation_status` ∈ {`donated`, `not donated`}. A participant is a list
    row iff `donated`; the others go to the set-aside fold with a plain
    label ("consented, did not donate" / "declined consent").
  - Times: `<verb>_at` is ISO-8601 TEXT in UTC (`2026-01-16T03:24:41Z`),
    NULL for TikTok's placeholder date; `<verb>_at_raw` is the donated
    string. Convert with the existing `stats_parse_date_any()` /
    `strtotime` to the integers `fmt_ts()` takes.
  - Counts: `n_<section>` = rows in the donated section (NULL = section
    absent, 0 = present but empty); `deleted_<section>` = rows the
    participant removed before donating. Sections: `watch`, `like`,
    `favorite`, `share`, `comment`.
  - Repeat submissions: the pipeline uses the earliest file containing data
    (`donation_file`; `donation_file_count` counts all submissions,
    including empty ones). Bare mode shows the newest file instead; database
    mode shows the pipeline's choice and, when `donation_file_count > 1`, a
    note "submitted N files; the first one with data is shown".
  - The package build writes the database to a temporary name and `mv`s it
    into place (`driver.sh`), so the file is never rewritten in place: a
    reader holding it open keeps the old inode.
- Measured on a local copy (cold cache): coverage for all participants with
  the bare-mode definition (distinct video IDs across watch, like, favorite
  and share; 4.76 M participant–video pairs, 3.37 M transcribed) 8.5 s;
  per-participant earliest/latest of the watch history 0.8 s; 15 random
  watch rows with metadata + transcript for the largest participant 2.5 s.
- Database freshness: for a study with analyses, the database is the truth.
  Mid-campaign it reflects the pipeline's latest package build; donations
  that arrived after that build are not shown until the next build lands
  (Danielle: "assume there's always an analysis.sqlite IF there are
  analyses attached").

## 4. Approaches

- **A. One PHP data layer, two backends (chosen).** Pages call a small set
  of study functions; a raw backend (today's `src/Ddp.php`, unchanged) serves
  bare exports, a database backend serves studies with analyses via PDO
  SQLite, read-only. Smallest change, bare path stays as tested, stays
  generic across platforms, nothing new to build or ship. Cost: two
  backends to keep consistent; some information exists only in database
  mode.
- B. Always SQLite: a Rust step at refresh loads bare exports into a small
  database too. One read path and fast bare exports at any size, but a new
  binary to build/release/download, and the pipeline schema is TikTok-only
  while the bare path is generic over platforms via flow docs.
- C. Database queries inline in the pages next to the raw code. Fastest to
  write; two tangled paths in every page, worse with each new enrichment.

Danielle picked A.

## 5. Design

### 5.1 How a study declares its analysis database

Setup, step 2 ("About your study"), gains an **Analyses** block:

```
Analyses
  [x] This study has an analysis database
      Path in the data folder: [ analysis/out/analysis.sqlite ]
```

Stored in `instance.json` as

```json
"analyses": { "database": { "enabled": true, "path": "analysis/out/analysis.sqlite" } }
```

The path is relative to the **study folder**, which every mode now has
(Danielle's decision):

- yoda: the collection (as today);
- rd-link: the share link points at the study's top folder (no longer
  necessarily the donations folder);
- local: the configured folder is the study's top folder.

Inside the study folder the donations are found the same way in every
mode: `inbox/*.json`, else the newest `donations/*.zip` (today's Yoda
logic, §OPERATIONS 4). Backward compatible: if the study folder itself
holds donation `*.json` files (an existing Research Drive or local setup
that points straight at a donations folder), that folder is the inbox, as
today. Saving validates the analysis path is relative without `..`.
Unchecked = bare study.

Consequences the builder carries through: the rd-link refresh syncs
`inbox/` or `donations/` from the share instead of the share root (and,
with analyses enabled, only the database); local mode resolves `inbox/` /
`donations/` / the database under the folder instead of symlinking the
folder itself as the inbox; Setup's help text for both modes says "the
study's folder" instead of "the donations folder"; OPERATIONS §2/§5 are
updated to match.
This is the first entry of the per-enrichment list Danielle wants later
(transcripts, comments, each with a checkbox and a relative path); the
structure is a map so later entries slot in beside `database`.

### 5.2 Data layer (`src/Study.php`)

Pages stop calling `ddp_*`/`analysis_*` directly and call:

- `study_participants(): array` — list rows for the index page: id,
  platforms, per-section counts, earliest/latest, transcribed-video coverage
  ("M of N"), plus the set-aside list (bare mode's skipped files; database
  mode: participants who did not donate, by status).
- `study_participant(string $id): ?array` — the participant page's data:
  per platform, per section: title/description/column order (from flow
  docs), total rows, deleted rows, earliest/latest, and a way to sample
  rows (see 5.4).
- `study_transcript(string $videoId): ?array` — transcript text, language,
  durations, raw signals, video metadata, availability.

`study_mode()` is `database` when `analyses.database.enabled` and the local
copy of the database exists and passes a shape check (required tables and
columns present); otherwise `raw`. If the database is enabled but missing or
the wrong shape, pages show a plain notice ("The analysis database hasn't
been fetched yet" / "doesn't look like a pipeline analysis database") rather
than silently falling back.

Raw backend: today's code paths moved behind these functions unchanged
(`ddp_load_dir_summaries`, `ddp_load_participant`, flows matching,
`analysis_*` for an unpacked transcripts tree if one exists).

Database backend: PDO SQLite opened read-only (`immutable=1` URI or
`SQLITE_OPEN_READONLY`; the builder verifies which the workspace's PHP
supports), one connection per request.

### 5.3 Mapping database tables onto the existing pages

Flow docs are matched to donated tables by their set of column names
(`flows_match()` compares a section's variables with the row keys), not by
table key. So the database backend hands the pages each section under the
donation's own section key, with sample rows whose keys are exactly the
donated column names, rebuilt from the `_raw` and verbatim columns. Then
`flows_match()`, titles, descriptions and column order work unchanged:

| table | donation section key | row keys (from column) |
|---|---|---|
| `watch_history` | `tiktok_watch_history` | `Date` (`watched_at_raw`), `Link` (`link`) |
| `like_history` | `tiktok_like_list` | `Date` (`liked_at_raw`), `Link` |
| `favorite_history` | `tiktok_favorite_videos` | `Date` (`favorited_at_raw`), `Link` |
| `share_history` | `tiktok_share_history` | `Date` (`shared_at_raw`), content type, method, `Link` |
| `comment_history` | `tiktok_comments` | `Date` (`commented_at_raw`), `Comment` |

Section keys are from the codebook's source notes. The share section's
exact donated column names (for `content_type` and `method`) are for the
builder to take from a real TikTok donation file of the flow; the
principle is "show what the participant donated, labelled as the flow
labels it". A section with no matching flow doc falls back to prettified
keys, as today. Counts and deleted rows come from `participant.n_*` and
`deleted_*`; earliest/latest from `min/max(<verb>_at)` per participant
(cheap: primary-key range).

Transcript links and the "rows with transcripts" filter use a join to
`transcription` instead of the in-memory ID set. Database mode adds what
only it knows, in the existing visual language: on rows without a
transcript, the reason (`video.availability`, in plain words: removed,
private or removed, region-gated, needs login, photo without audio, not
attempted, failed); on the transcript page for an untranscribed video, the
same reason instead of "Not transcribed yet".

Participants who consented but did not donate, or declined, appear in the
list's existing "set aside" fold with their status, not as rows (my call:
matches how bare mode treats declined/empty files).

### 5.4 Sampling

Bare mode: unchanged (`sample_rows` over loaded rows, seed + salt).
Database mode: the same `(seed, section)`-stable sample without loading
rows into PHP: order by a deterministic hash of `(seed, section,
donation_row)` and take `LIMIT n` (with the transcript filter as a join
condition). Reshuffle = next seed, as today. The builder picks the hash
(e.g. an integer mix of `donation_row` and the seed in SQL) as long as the
same URL always shows the same rows.

### 5.5 Caching

- Database mode: the participant-list aggregate (coverage per participant
  with the same definition as bare mode, so both modes show the same "M of
  N" for the same donation; earliest/latest) is computed once and cached as
  JSON in
  `<cache>/study/participants-<size>-<mtime>.json`, keyed by the database
  file's size and mtime; first load pays the ~5–10 s. Old cache files are
  removed when a new one is written. Participant and transcript pages are
  direct indexed queries, uncached.
- Bare mode: unchanged per-file summary cache; the transcript-ID scan is
  only done when an unpacked transcripts tree exists (bare studies have
  none).
- Both: the refresh script ends by warming the list cache
  (`php /opt/ddp-inspector/bin/warm-cache.php`, run as the service user), so
  a researcher normally never pays the first-load cost. Warm-up failure is
  logged and does not fail the refresh.

### 5.6 Refresh

Yoda / Research Drive / local, when `analyses.database.enabled`:
- fetch only the database file (no donations zip, no transcript tars, no
  unpacking), to `<data>/analyses/analysis.sqlite`;
- download only if changed: gocmd `get --diff --no_hash` (size + mtime) for
  Yoda (verify live), rclone's default size/modtime check for Research
  Drive; local mode symlinks the file (safe: the pipeline replaces it by
  rename, never in place, see §3);
- never expose a partial file: download into a staging name on the same
  filesystem and `mv` into place; pages opened mid-swap keep reading the
  old inode;
- status messages in the existing plain-language style ("Fetching the
  analysis database (33 GB)…", "The analysis database is up to date.");
- donation count in the status = participants who donated, from the
  database.

When not enabled: today's behaviour (inbox/ or newest export zip,
transcript tars if present), plus the cache warm-up.

Volume sizing: the database (~33 GB for a 2.4 M-transcript study) plus one
temporary copy during replacement. On the existing workspace, the
previously unpacked transcripts tree (2.4 M files) is no longer used and
can be deleted once database mode works.

### 5.7 Provisioning

- Add `php-sqlite3` (pdo_sqlite) to the inspector role's packages.
- Ship `bin/warm-cache.php` with the app.
- Runbook: OPERATIONS §2 sizing row for studies with an analysis database;
  §4/§6 notes on database mode.

### 5.8 Tests

- PHP (`tests/`): a tiny analysis database built at test time with PDO
  from a committed SQL fixture (`tests/fixtures/analysis.sql`, synthetic
  data in the pipeline's schema); tests for mode detection and the shape
  check, list rows + coverage, participant sections + counts + deleted rows,
  deterministic sampling (same seed → same rows, filter applied), transcript
  page from the database incl. an untranscribed video's availability
  reason, the set-aside fold, cache keying/invalidation, and that bare mode
  is unchanged.
- Refresh harness: database mode fetches only the database, skips zip/tars,
  swaps atomically, does not re-fetch when unchanged (fake gocmd honours
  `--diff`), warms the cache.
- No Python in tests or fixtures.

## 6. Left to the builder

- Exact PDO read-only open form on Ubuntu 24.04's PHP 8.3.
- The deterministic sampling expression.
- Plain-language wording for availability labels and notices (existing
  tone: short, researcher-facing).
- Whether `src/Study.php` is one file or split per backend.
- Live verification of gocmd `get --diff --no_hash` against Yoda before
  relying on it.
