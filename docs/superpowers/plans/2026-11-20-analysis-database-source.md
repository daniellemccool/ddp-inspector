# Analysis-Database Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Studies with analyses are browsed from the pipeline's read-only `analysis.sqlite`; bare Next exports keep working unchanged; page cost no longer grows with corpus size.

**Architecture:** A new data layer `src/Study.php` exposes `study_participants()`, `study_participant()`, `study_transcript()`; a raw backend wraps today's `src/Ddp.php` logic, a database backend queries `analysis.sqlite` via PDO SQLite. Pages call only the data layer. The refresh script fetches only the database (when declared, when changed, swapped in atomically) and ends by warming the list cache via `bin/warm-cache.php`.

**Tech Stack:** PHP 8.3 (+ pdo_sqlite), bash, jq, gocmd v0.12.2, rclone, Ansible (SRC). Tests: `php tests/run.php`, `provisioning/scripts/*.sh`.

**Spec:** `docs/superpowers/specs/2026-11-20-analysis-database-source-design.md`

## Global Constraints

- No Python anywhere: code, tests, fixtures, ad-hoc checks.
- Never write to the pipeline's database or to Yoda; open the database read-only.
- `video_id` and `participant_id` are TEXT; never cast to numbers.
- Page cost must not scale with corpus size without a cache in front; first load 5–10 s is acceptable.
- Researcher-facing text: short, plain language, no jargon (existing tone).
- No study-identifying names, IDs or paths in tracked files.
- Prerequisite to run PHP tests locally: `php` with `pdo_sqlite` (Arch: `php`, `php-sqlite`; enable `extension=pdo_sqlite` in `/etc/php/php.ini`). Without it, PHP tests run on a workspace (`php /tmp/ddpi/tests/run.php`, OPERATIONS §8).

## Review Focus

- A section absent from a donation (`n_<section>` NULL) must not render as "0 rows"; an absent section is not shown, an empty one shows 0. Pinned in Task 4.
- History rows with NULL `video_id` (malformed donated link) get no transcript link, count as neither transcribed nor untranscribed in coverage, and never break sampling. Pinned in Task 4/5.
- NULL `<verb>_at` (TikTok's placeholder date) is ignored for earliest/latest; the row still shows its raw date string. Pinned in Task 5.
- Unknown or malformed participant id / video id in database mode returns the existing 404 / 400, never a PDO error. Pinned in Task 5/6.
- Database declared but missing, wrong shape, or replaced by a new build: pages show the plain notice, and a new build (new size/mtime) rebuilds the list cache and deletes the old cache file. Pinned in Task 2/4.

---

### Task 1: Declare an analysis database in Setup

**Files:**
- Modify: `src/Instance.php` (`inst_handle_setup_post` save_source branch)
- Modify: `public/setup.php` (step 2 form)
- Test: `tests/SetupTest.php`

**Interfaces:**
- Produces: `instance.json` key `analyses` = `['database' => ['enabled' => bool, 'path' => string]]`; default path `analysis/out/analysis.sqlite`. Read later via `inst_load()['analyses']['database']`.
- Produces: `inst_valid_relative_path(string $p): bool` in `src/Instance.php` (non-empty, no leading `/`, no `..` segment, no `\0`).

- [ ] **Step 1: Write failing tests** in `tests/SetupTest.php` (after the Yoda block): save_source with `analysis_db_enabled=1, analysis_db_path='analysis/out/analysis.sqlite'` → `inst_load()['analyses']['database']` equals `['enabled'=>true,'path'=>'analysis/out/analysis.sqlite']`; checkbox absent → `enabled` false and the path kept; path `'../x.sqlite'` and `'/abs.sqlite'` → error flash naming "path"; re-save without touching the block keeps the stored values; `inst_valid_relative_path` true for `a/b.sqlite`, false for `''`, `'/a'`, `'a/../b'`, `'..'`.
- [ ] **Step 2: Run** `php tests/run.php` → the new checks FAIL.
- [ ] **Step 3: Implement** the `analyses` read/validate/store in save_source (preserve `analyses` across saves the way `analysis_dirs` is preserved), `inst_valid_relative_path`, and the form block from spec §5.1 (checkbox `analysis_db_enabled`, text `analysis_db_path` prefilled from instance or the default). Help text for rd-link/local choices says "the study's folder" (spec §5.1).
- [ ] **Step 4: Run** `php tests/run.php` → PASS (all suites).
- [ ] **Step 5: Commit** `feat(setup): declare a study's analysis database`.

### Task 2: Data layer core and database access

**Files:**
- Create: `src/Study.php`; require it from `src/bootstrap.php`
- Create: `tests/fixtures/analysis.sql` (synthetic data, pipeline schema from spec §3: tables `participant`, `watch_history`, `like_history`, `favorite_history`, `share_history`, `comment_history`, `video`, `transcription`, `transcription_process(video_id, source_url, …)`, `meta`)
- Create: `tests/StudyTest.php`; add to the list in `tests/run.php`
- Modify: `provisioning/roles/inspector/tasks/main.yaml` (add `php-sqlite3` to the PHP packages)

**Interfaces:**
- Produces in `src/Study.php`:
  - `study_db_decl(): ?array` → `['path'=>string]` when `analyses.database.enabled`, else null.
  - `study_db_file(): ?string` → `inst_paths()['analyses'] . '/analysis.sqlite'` in storage mode; `cfg('analysis_db')` in developer mode; null if neither.
  - `study_db_status(): string` → `'off'|'missing'|'invalid'|'ok'` (`invalid` = required tables/columns from spec §3 absent).
  - `study_mode(): string` → `'database'` iff status `ok`, else `'raw'`.
  - `study_db(): ?PDO` → one read-only connection per request (static), null unless status `ok`.
  - `study_db_notice(): ?string` → `missing`: "The analysis database hasn't been fetched yet — run Check & fetch in Settings."; `invalid`: "The analysis database doesn't look like a pipeline analysis database."; else null.
- Test helper (in `tests/StudyTest.php`, reused by Task 4–6 tests): `study_test_build_db(string $file): void` executes `tests/fixtures/analysis.sql` via `new PDO('sqlite:'.$file)`.

- [ ] **Step 1: Write the fixture** with at least: 3 donated participants (`pa` with all five sections incl. one row with NULL `video_id`, one NULL `watched_at`, `n_like` NULL; `pb` with `donation_file_count` 2; `pc` with only comments), 1 `not donated` + `consent accepted`, 1 `consent declined`; videos covering every `availability` value; transcriptions for some; `raw_signals` with one segment of tokens (`[_BEG_]`, ` Hello`, ` world`).
- [ ] **Step 2: Write failing tests**: status `off` without declaration; `missing` when declared and no file; `invalid` for an empty SQLite file; `ok` for the fixture; `study_mode()` follows; `study_db_notice()` texts for `missing`/`invalid`; opening the fixture read-only then attempting `INSERT INTO meta …` throws.
- [ ] **Step 3: Run** `php tests/run.php` → FAIL.
- [ ] **Step 4: Implement** the functions. Read-only open: try `new PDO('sqlite:file:'.rawurlencode-path.'?mode=ro&immutable=1', …)`; if URI filenames are unsupported, `new PDO('sqlite:'.$file, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY])`. Keep whichever the read-only test proves on PHP 8.3; note the choice in a comment.
- [ ] **Step 5: Run** → PASS. Run `provisioning/scripts/tier1.sh` → `TIER 1 PASS`.
- [ ] **Step 6: Commit** `feat(study): data layer core with read-only analysis database access`.

### Task 3: Raw backend behind the data layer; pages switched over

**Files:**
- Modify: `src/Study.php`
- Modify: `public/index.php`, `public/participant.php`, `public/transcript.php`
- Test: `tests/PagesTest.php` (unchanged expectations must still pass), `tests/StudyTest.php`

**Interfaces:**
- Produces (both backends implement these shapes; Task 4–6 add the database side):
  - `study_participants(): array{participants: array<string, array{id:string, platforms: array<string, array{tables: array<string, array{count:int, earliest:?int, latest:?int}>, deleted: array<string,int>, videos_total:?int, videos_transcribed:?int}>}>, skipped: list<array{path:string, participant:string, kind:string, reason:string}>}` — today's `ddp_load_dir_summaries` shape.
  - `study_participant(string $id, int $n, int $seed, bool $all): ?array{id:string, platforms: array<string, array{title:string, notes: list<string>, scope: array<string, array{count:int, earliest:?int, latest:?int}>, sections: list<array{key:string, title:string, description:string, columns: list<string>, total:int, with_artifacts:int, hidden:int, deleted:int, sample: list<array{row: array<string,string>, links: list<array{label:string, url:string}>, reason:?string}>}>}>}`
  - `study_transcript(string $vid): ?array{text:?string, meta:?array, availability:?string}` — `meta` in today's artifact JSON shape (`video_metadata`, `source_url`, `language_detected`, `duration_s`, `raw_signals`).
  - `study_donation_count(): int`.
- Consumes: Task 2's `study_mode()`.

- [ ] **Step 1: Write failing tests** in `tests/StudyTest.php` (developer config, raw mode): `study_participant('p1', 1, 1, false)` returns the watch section with `total` equal to the fixture's row count and a 1-row sample; `study_transcript('7000000000000000029')['text']` contains "Hello world"; `study_participants()['skipped']` lists the preview file; `study_participant('nope', 15, 1, false)` is null.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the raw backend by moving the per-section logic out of `public/participant.php` (flows matching, filter, `sample_rows`, `analysis_row_links`, superseded note → `notes`) and the transcript file reading out of `public/transcript.php`. The transcript-ID scan (`analysis_available_ids`) runs only when the transcripts directory exists. Rewrite the three pages to render only from these return values; markup and copy stay the same. When `study_db_notice()` is non-null, `index.php` and `participant.php` show it as a `<p class="notice">` above the content instead of falling back silently (test: fixture declared, file absent → index contains "hasn't been fetched yet").
- [ ] **Step 4: Run** `php tests/run.php` → PASS, including every existing `PagesTest` check.
- [ ] **Step 5: Commit** `refactor(pages): render from the study data layer (raw backend)`.

### Task 4: Database backend — participant list and coverage cache

**Files:** Modify `src/Study.php`, `public/index.php` (set-aside labels); Test `tests/StudyTest.php`, `tests/PagesTest.php`

**Interfaces:**
- Database side of `study_participants()` and `study_donation_count()` (count of `donated`).
- Section keys / sources (spec §5.3): `watch`→`tiktok_watch_history`, `like`→`tiktok_like_list`, `favorite`→`tiktok_favorite_videos`, `share`→`tiktok_share_history`, `comment`→`tiktok_comments`; platform slug `tiktok`.
- Skipped kinds added: `not_donated` (label "consented, did not donate"), `declined_consent` ("declined consent").
- Cache: `<cache>/study/participants-<size>-<mtime>.json`; writing a new one deletes other `participants-*.json`.

- [ ] **Step 1: Write failing tests** (storage-mode scratch with the fixture DB declared): `pa`,`pb`,`pc` are participants, the other two are skipped with the two kinds; `pa` has no `tiktok_like_list` table (NULL count) while a 0-count section is present with `count` 0; `deleted` from `deleted_*`; `videos_total` = distinct non-NULL `video_id` over watch/like/favorite/share, `videos_transcribed` = those in `transcription`; earliest/latest ignore NULL `<verb>_at`; second call reads the cache file (assert it exists and a `touch` of the DB with a new mtime produces a new file and removes the old); index page lists `pa` and shows "consented, did not donate".
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** with one aggregate query per measure (counts from `participant`, min/max per section grouped by participant, the coverage UNION from spec §3 grouped by participant); timestamps via `stats_parse_date_any()`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(study): participant list from the analysis database, cached per build`.

### Task 5: Database backend — participant page

**Files:** Modify `src/Study.php`; Test `tests/StudyTest.php`, `tests/PagesTest.php`

**Interfaces:**
- Database side of `study_participant()`. Row keys per section (spec §5.3): `Date` from `<verb>_at_raw`, `Link` from `link`, `Comment` from `comment`; share's content type / method keys taken from the TikTok flow doc's share section variables (fall back to `SharedContent`, `Method`).
- Sampling expression (deterministic, in SQL): `ORDER BY ((donation_row * 2654435761 + :seed * 40503 + :salt) % 4294967296), donation_row LIMIT :n`, with `:salt = crc32($sectionKey)`.
- Availability reason labels: `removed_or_private` "removed or private", `removed` "removed", `region_gated` "not available in this region", `login_gated` "needs a TikTok login", `photo_no_audio` "photo post without audio", `retries_exhausted` "could not be fetched", `not_attempted` "not fetched (only liked, favorited or shared)".

- [ ] **Step 1: Write failing tests**: same `(id, n, seed)` returns identical samples twice, a different seed a different order; `all=false` only samples rows with a transcription and reports `hidden` = untranscribed + NULL-video rows; `all=true` rows without a transcript carry their `reason`; a NULL-`video_id` row has no links and `reason` null; `pb`'s platform `notes` contains "submitted 2 files; the first one with data is shown"; titles come from `tests/fixtures/flows/tiktok/documentation.txt` when flows are installed (copy it into the scratch flows dir), prettified keys otherwise; `study_participant("x' OR 1=1 --", …)` and an unknown id return null; participant page renders a reason text.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (prepared statements only; validate ids with the existing `^[A-Za-z0-9_-]+$` pattern before querying).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(study): participant page from the analysis database`.

### Task 6: Database backend — transcript page

**Files:** Modify `src/Study.php`, `public/transcript.php`; Test `tests/StudyTest.php`, `tests/PagesTest.php`

**Interfaces:**
- Database side of `study_transcript()`: `text` = `transcription.transcript`; `meta` = `['video_id', 'source_url' (transcription_process), 'language_detected' (language), 'duration_s' (audio_duration_s), 'raw_signals' (decoded JSON), 'video_metadata' => ['video_description','uploader','video_created_at','view_count','like_count','comment_count','metadata_fetched_at']]` from `video`; `availability` = `video.availability`.
- Page: when `text` and `meta` are null and `availability` is set, show the Task 5 reason label instead of "Not transcribed yet".

- [ ] **Step 1: Write failing tests**: a transcribed fixture video returns its text and segment confidence renders ("Hello world"); an untranscribed `region_gated` video shows "not available in this region"; an unknown 19-digit id renders "Not transcribed yet"; the existing 400 for a malformed id still holds in database mode.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(study): transcript page from the analysis database`.

### Task 7: Cache warm-up after each refresh

**Files:**
- Create: `bin/warm-cache.php`
- Modify: `provisioning/roles/inspector/tasks/main.yaml` (copy loop: add `bin`), `provisioning/roles/refresh/templates/ddp-refresh.sh.j2` (end of run), `provisioning/scripts/test-refresh-script.sh`
- Test: `tests/StudyTest.php` (runs the script via `php bin/warm-cache.php` with `DDP_INSPECTOR_CONFIG` set), refresh harness

**Interfaces:**
- `bin/warm-cache.php`: calls `study_participants()`, prints the donation count (`study_donation_count()`) as its last stdout line, exit 0; exit 1 with a message on error.
- Refresh: `N="$({{ inspector_php_bin | default('php') }} {{ inspector_install_dir }}/bin/warm-cache.php | tail -n 1)"`; on failure log "[refresh] cache warm-up failed" and fall back to `count_donations`. Template var `inspector_php_bin` lets the harness pass a shim.

- [ ] **Step 1: Write failing tests**: PHP — running the script in database mode creates the cache file and prints `3`; harness — a fake `php` shim records it was called and prints `7`; status `donations` becomes 7; a shim exiting 1 still yields phase `done` with the file count.
- [ ] **Step 2: Run** both → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php tests/run.php` and `provisioning/scripts/test-refresh-script.sh` → PASS; `tier1.sh` → PASS.
- [ ] **Step 5: Commit** `feat(refresh): warm the participant-list cache after each refresh`.

### Task 8: Study folder in every mode (bare donations)

**Files:** Modify `provisioning/roles/refresh/templates/ddp-refresh.sh.j2`, `provisioning/roles/refresh/templates/ddp-probe.sh.j2`, `src/Instance.php` (`inst_probe` local), `provisioning/docs/OPERATIONS.md` §2/§5; Test `provisioning/scripts/test-refresh-script.sh`, `tests/InstanceTest.php`

**Interfaces:**
- Shell function in the refresh script: `extract_export_zip <zip>` — today's yoda unzip/chmod/mirror block, reused by all modes.
- Donation resolution under the study folder, every mode: root holds `*.json` → root is the inbox (backward compatible); else `inbox/`; else newest `donations/*.zip`; else the existing "No donations were found" error.
  - local: inbox = symlink to root or `root/inbox`; zip → extract into a real inbox.
  - rd-link: `rclone lsf :webdav:` decides; `inbox/` → sync `inbox/` with `--include '*.json'`; `donations/` → `rclone copy` the newest zip to cache, then `extract_export_zip`; root json → today's root sync.

- [ ] **Step 1: Write failing harness cases**: local folder with `inbox/x.json` → donation counted; local folder with `donations/20260101_s.zip` → extracted; local folder with root json (existing case 7) unchanged; rd-link fake share with `donations/` zip → extracted; rd-link with root json (existing case 5) unchanged. Extend the fake rclone with `lsf` and `copy`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**; update probe local message to count `root` or `root/inbox`; OPERATIONS §2/§5 say "share/point at the study's folder".
- [ ] **Step 4: Run** harness + `php tests/run.php` + `tier1.sh` → PASS.
- [ ] **Step 5: Commit** `feat(refresh): resolve donations under a study folder in every mode`.

### Task 9: Fetch the analysis database (only when declared, only when changed)

**Files:** Modify `provisioning/roles/refresh/templates/ddp-refresh.sh.j2`, `provisioning/docs/OPERATIONS.md` (§2 sizing row, §4/§6 database mode); Test `provisioning/scripts/test-refresh-script.sh`

**Interfaces:**
- Declaration read from `instance.json`: `jq -r '.analyses.database | select(.enabled) | .path'`.
- Target: `${ROOT}/data/analyses/analysis.sqlite`; staging `${ROOT}/data/analyses/.analysis.sqlite.part` then `mv -f`.
- Change detection without trusting `--diff`: store the remote listing line in `${STATE}/analysis-db.remote`; fetch only when the current line differs or the target is missing. Yoda: `gc ls -l "i:${COLLECTION}/${DBPATH}"`; rd-link: `rclone lsl :webdav:"${DBPATH}"`; local: symlink `${ROOT}/data/analyses/analysis.sqlite` → `${LOCAL_PATH}/${DBPATH}` (no copy).
- When declared, the refresh skips donations and transcript tars entirely.
- Status messages: "Fetching the analysis database…", "The analysis database is up to date."; missing remote file → die "The analysis database was not found at <path> in the study folder. Check the path in Settings."

- [ ] **Step 1: Write failing harness cases** (extend fake gocmd: `ls -l` prints `<size> <mtime-epoch> <name>` from `stat`): yoda declared → only the DB fetched (no `donations`/`transcripts-tars` in the gocmd log), file in place, no `.part` left; second run with an unchanged remote → no `get` in the log, status "up to date"; touched remote (new mtime) → fetched again; declared path missing remotely → error status naming the path; local declared → symlink to the folder's file; rd-link declared → `rclone copyto`/`copy` of only that path.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** harness + `tier1.sh` → PASS.
- [ ] **Step 5: Live check (Danielle, on the workspace)**: after deploying the branch (OPERATIONS §8), declare the database in Setup, Check & fetch; confirm the participant list loads within ~10 s on first visit and instantly after; a second refresh logs "up to date". Then remove the old unpacked tree: `sudo rm -rf <storage_root>/ddp-inspector/data/analyses/transcripts`.
- [ ] **Step 6: Commit** `feat(refresh): fetch the analysis database only when declared and changed`.
