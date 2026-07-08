# DDP Inspector — Design Spec

- **Date:** 2026-07-08
- **Status:** Approved (design), pending implementation plan
- **Repo:** `~/src/ddp-inspector` (new, standalone — deliberately *not* inside `ddp-transcribe`/`uu-tiktok`, which is the transcription pipeline only)

## 1. Problem

A researcher (crime/policing TikTok study) needs to confirm that the **pilot DDP donations align with her expectations** before the study scales up. She needs to see, per participant, what actually arrived in the data-donation extracts.

A one-off transcript browser was built on 3 July 2026 (`~/ddp-demo/artifact-browser.py`) for a live demo. It is **transcript-centric and participant-blind**: it walks the sharded transcript output (`<shard>/<video_id>.txt|.json`) keyed by unique video ID and has no concept of a participant. Two structural gaps make it unfit for validation:

1. **Deduplication breaks the 1:1 relationship.** Transcripts are keyed by unique video ID (86,732 for the pilot), but a participant is a *set of watch events* (94,289 rows across both pilot donors) plus four other DDP sections the transcript tree never sees.
2. **It omits the rest of the DDP.** The export carries five sections; the transcript browser surfaces none of the DDP metadata.

This tool fills that gap: a **per-participant, DDP-first inspector**.

## 2. Goals / Non-goals

**Goals (in the researcher's stated priority order):**
1. **Faithful representation** of what arrived in the DDP extracts, per participant — all five sections, shown truthfully.
2. **Scope / volume / completeness** — per-section counts, date ranges, unique-video totals; make the shape (and anomalies) visible.
3. **Content impressions** — a sample of comments and, where available, transcripts, including a sense of transcript quality.

**Non-goals:**
- Editing or mutating any data (strictly read-only).
- Matching comments to their videos — the export does not carry the association (see §3), so we do not fabricate it.
- Full-text search across a participant's history.
- Running transcription, or reading the pipeline's SQLite state DB (the raw DDP files are the faithful source).

## 3. Input data — the TikTok DDP extract

Each participant's donation is one JSON file whose name follows the platform's convention:

```
assignment={N}_task={N}_participant={ID}_source=tiktok_key={N}-tiktok.json
```

The **participant ID is derived from the `participant=` filename segment**, not the file body. The body is a JSON **array of section objects**. Five sections are present in the pilot data:

| Section | Fields | Notes |
|---|---|---|
| `tiktok_watch_history` | `Date`, `Link` | video link → canonical 19-digit ID |
| `tiktok_favorite_videos` | `Date`, `Link` | video link |
| `tiktok_like_list` | `Date`, `Link` | video link |
| `tiktok_share_history` | `Date`, `Link`, `Method`, `SharedContent` | e.g. `Method="sms"`, `SharedContent="share_video"` |
| `tiktok_comments` | `Comment`, `Date` | **no video reference of any kind** |

Each section object also carries a `deleted row count` key, which is ignored.

**Key data facts that shape the design:**
- **Comments cannot be linked to videos.** A comment entry is `{Comment, Date}` only; there is no link or video ID. (338 of the pilot comments merely *mention* "http"/"tiktok" in free text; none contain a `/video/` reference.) → Comments are shown **standalone**.
- **Date formats differ by section:** comments use `YYYY-MM-DD HH:MM:SS UTC`; the other four use `YYYY-MM-DD HH:MM:SS` (no suffix). Date handling must tolerate both.
- **Canonical video URLs** are `https://www.tiktokv.com/share/video/{19-digit-id}/` (and the `@user/video/{id}` form); the video ID is the 19 digits.
- **Participants are wildly asymmetric.** The two pilot donors illustrate this and serve as the working example:

| Participant | watch | favorites | likes | shares | comments |
|---|---|---|---|---|---|
| `69f39df5…` | 90,466 | 5,384 | 2,973 | 55 | 30 |
| `6942a793…` | 3,823 | 410 | 6,000 | 100 | 6,074 |

This asymmetry is itself a validation signal, and it is why per-section **sampling with true totals always shown** is central.

## 4. Architecture

**PHP, server-rendered, vanilla (stdlib only — no Composer, no framework).** This is a "read files → render a page" viewer deployed behind the SURF Research Cloud (SRC) workspace's nginx + SRAM component; that is PHP's native shape and deployment model.

Logic is split from presentation so the tricky parts are unit-testable without a running server or real donor data:

- **`src/Ddp.php`** — pure parsing. Read a DDP file → the five sections; derive participant ID from the filename; **skip non-conforming files** (preview stubs, non-array JSON) without a fatal error. Merge sections when a participant has more than one file.
- **`src/Stats.php`** — per-section count, earliest/latest date (tolerating the `UTC`/plain split), and unique canonical 19-digit video IDs across the link-bearing sections.
- **`src/Sample.php`** — deterministic seeded sampling: the sample is a pure function of `(participant, section, seed, n)`, so it is reproducible and "reshuffle" is just a new seed.
- **`public/`** — the docroot (pages, §6).

Everything is **read-only**. No data is written back; the only writes are (optionally) a stats cache under the tool's own directory (§8).

## 5. Reverse-proxy & auth model

Authentication is **entirely nginx's job**, not the app's. The SRC nginx component gates a `location` block with `auth_request /validate` (SRAM login limited to the workspace's collaboration/CO). The inspector therefore has **no login, session, or user code**. Its only obligation is to be reverse-proxy-friendly:

- **No absolute URLs.** Every internal link, form action, and asset reference is **relative** (or built from a configurable `base_path`), so the same code runs identically under local `php -S` at `/` and behind nginx at `/inspector` (or wherever the `location` mounts it).

## 6. Pages (server-rendered, query-param routed)

Plain page scripts with query strings — no rewrite rules required.

- **`public/index.php`** — participant list with a scope summary per participant (counts per section, overall date range, unique-video count). Links to each participant page. Shows a "N files skipped" banner if any input files were non-conforming.
- **`public/participant.php?id=<pid>&seed=<s>&n=<15>`** — the per-participant page:
  1. **Scope table** (the centerpiece): one row per section with count + earliest/latest date, plus a summary line "unique videos: X across watch/fav/like".
  2. **Each section**, in turn: a header stating the *true* count and date range, then a **seeded random sample** of `n` rows (default 15; selectable 10/20/50) with a **reshuffle** link (bumps `seed`). Link-bearing rows show the date, the canonical video ID/URL, and (shares) `Method`/`SharedContent`. Comments show date + comment text. All values HTML-escaped.
  3. Each video row links to its transcript view (§7) — a plain hyperlink, no JS required.
- **`public/transcript.php?vid=<19-digit-id>`** — a standalone server-rendered page showing the transcript text + JSON annotation (per-segment average token confidence, low-confidence flagged) for a video, reusing the demo's confidence logic; or a "not transcribed yet" state when no transcript tree is configured or the file is absent. (Progressive enhancement — inlining this into the participant page via a small `fetch` — is optional and explicitly not required for the baseline.)

## 7. Transcript integration (optional, graceful)

The 86,732 pilot videos are **not transcribed yet**, so transcripts are a secondary, opt-in feature:

- `config.php` may point `transcripts_dir` at a sharded transcript tree (`<shard>/<video_id>.txt|.json`, last-two-digits sharding).
- For a given 19-digit video ID, look up `<last-two-digits>/<id>.txt` and `.json`. If present, render transcript text + a per-segment confidence table (avg token `p`, low-`p` segments flagged), plus a collapsible raw-JSON view — mirroring the 3 July browser.
- If `transcripts_dir` is unset or the file is absent, show **"not transcribed yet."** The tool is fully useful with no transcripts configured.
- The view is reached by a normal hyperlink from a video row and rendered server-side; no client JS is required.

## 8. Error handling & edge cases

- **Non-conforming input files** (preview `{"status":"data_submission declined"}` stubs, non-array JSON): skipped, counted, and surfaced as a banner — **never fatal** (unlike the ingest tool, which aborts on parse error).
- **Malformed / unparseable dates:** shown raw; excluded from date-range computation; never crash.
- **Path-traversal safety:** the `vid` parameter is validated `^\d{19}$` before any filesystem lookup.
- **Multiple files per participant:** sections merged (rows concatenated) under one participant ID.
- **Large sections:** only a sample is rendered; the full file is read to compute counts/date ranges (acceptable at pilot scale — a handful of participants). If participant count grows, an optional prebuilt stats cache (a small JSON sidecar under the tool dir, regenerated on input mtime change) can back the index page; on-demand parsing is the default.
- **Empty sections:** shown with count 0, no sample.

## 9. Security & deployment

- **Read-only**, single-purpose, no data mutation.
- **Phase 1 (local dev / first look):** `run-dev.sh` → `php -S 127.0.0.1:8110 -t public`. Requires installing `php` locally (approved). Localhost-only, mirroring the demo's posture.
- **Phase 2 (the goal — show the researcher):** served at the workspace FQDN behind nginx + SRAM, restricted to the CO. Provision `php-fpm` via the workspace Ansible playbook; add an nginx `location` block modelled on the SRC demo's `auth_request` SRAM guard with `fastcgi_pass` to php-fpm (a `deploy/nginx-location.conf.example` + `deploy/PROVISION.md` document this). `config.php` carries the read-only DDP directory path, optional `transcripts_dir`, default sample size, and `base_path`.
- **PHP availability:** not present by default in the target env → provisioning is an explicit prerequisite (local: `pacman -S php`; workspace: playbook adds `php-fpm`).
- **No donor data in the repo.** Tests use synthetic fixtures only.

## 10. Testing

A **dependency-free PHP assert harness** (`tests/run.php` including per-unit test files; no phpunit/Composer) exercising the pure `src/` units against tiny synthetic fixtures:

- `Ddp`: section extraction; participant-ID-from-filename; skip-not-crash on a preview stub and on non-array JSON; multi-file merge.
- `Stats`: counts; date ranges across the `UTC`/plain split; unique canonical-ID dedup; empty section.
- `Sample`: determinism for a fixed seed; different seed → different sample; `n` larger than the section returns all rows without error.
- Path-safety: `vid` validation rejects non-19-digit / traversal input.

Fixtures: two small synthetic DDP files (covering all five sections and both date formats) plus one preview-stub file. No real pilot data.

## 11. Repo structure

```
ddp-inspector/
├── public/
│   ├── index.php              # participant list + scope summary
│   ├── participant.php        # per-participant page (scope table + sampled sections)
│   ├── transcript.php         # optional transcript fragment
│   └── assets/style.css       # paper/serif styling (echoes the demo look)
├── src/
│   ├── Ddp.php                # pure: parse, sections, participant-id, skip-bad, merge
│   ├── Stats.php              # counts, date ranges, unique canonical video IDs
│   └── Sample.php             # deterministic seeded sampling
├── tests/
│   ├── fixtures/              # tiny synthetic DDPs + one preview stub
│   ├── run.php                # zero-dep assert harness
│   ├── DdpTest.php
│   ├── StatsTest.php
│   └── SampleTest.php
├── deploy/
│   ├── nginx-location.conf.example
│   └── PROVISION.md           # SRC playbook + php-fpm provisioning notes
├── config.php.example         # ddp_dir, transcripts_dir, default_n, base_path
├── run-dev.sh                 # php -S 127.0.0.1:8110 -t public
└── README.md
```

## 12. Open questions / deferred

- **Comment language detection** — the pilot comments are multilingual (e.g. Spanish); showing a detected language was considered but **dropped** to stay dependency-free and "not fancy." Revisit only if the researcher asks.
- **Stats cache** — deferred; on-demand parsing suffices at pilot scale. Add if participant count makes the index page slow.
- **Static "bake" export** — a per-participant offline HTML export was considered (Approach B during design) and set aside for the security footprint; can be added later as a strict subset of the render logic if offline copies are ever needed.
- **Comment↔video temporal heuristic** — explicitly declined; the association is not in the data and a timestamp guess would be misleading.

## Deviations from spec (accepted during implementation)

- The index page shows summary counts (total rows, unique videos, overall date
  range) rather than per-section counts; per-section counts live on the
  participant page. Accepted by decision to keep the index lean.
