# DDP Inspector App Generalization Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalize the DDP Inspector PHP app from TikTok-only to any port-flow study (participant→platform→tables), add the researcher-facing setup wizard (flow-export upload, data-source config, refresh controls), and introduce the analyses seam with transcripts as its first module — per spec `docs/superpowers/specs/2026-07-29-ddp-inspector-catalog-component-design.md` §6–§8.

**Architecture:** Donation files are JSON lists of named tables (the port export shape); identity comes from `participant=`/`source=` filename segments. New `src/Instance.php` resolves the on-volume tree (`storage_root` mode) or legacy `ddp_dir` mode and owns all config/state file IO. New `src/Flows.php` parses uploaded flow exports (`documentation.txt`) and matches doc sections to donation tables by column-set + order. New `src/Analysis.php` is the linking seam (transcripts = first module). Pages render generically; `public/setup.php` is the wizard. Plan 2 (provisioning) consumes the pinned file contracts below and is out of scope here.

**Tech Stack:** Vanilla PHP ≥ 8.1, stdlib only (PharData for zips — no new extensions), no Composer, no framework, no build step, no client JS. Homegrown test harness (`php tests/run.php`).

## Global Constraints

- PHP ≥ 8.1, stdlib only, no Composer, no framework, no build step, no client JS required.
- Match existing conventions: procedural functions with `ddp_`/`stats_`/`sample_`-style prefixes (new prefixes: `inst_`, `flows_`, `analysis_`, `csrf_`); tests are sequential `check()`/`eq()` files registered in `tests/run.php`; pages tested via the `render_page()` include trick.
- Reverse-proxy-safe URLs: every link through `url()`/`base_path`.
- No user-visible 500s on bad data, ever. Researcher-facing copy in plain language (spec §2): errors say what to do next; never show the words gocmd/rclone/systemd/ticket to researchers ("access code" instead of ticket).
- All HTML output escaped through `h()`.
- Zip uploads: max 64 MiB file, max 64 entries; reject entry names containing `..` or starting with `/`.
- `source.json` written 0600, atomic (write temp + rename). Ticket/password never echoed back into HTML after save.
- Commit after every task (Conventional Commits). Run `php tests/run.php` before every commit; all tests green.

## Pinned shared contracts (Plan 2 consumes these exactly — do not rename)

- On-volume tree: `<storage_root>/` containing `config/{instance.json,source.json,flows/<slug>/{documentation.txt,build-meta.json}}`, `data/{inbox/,analyses/transcripts/}`, `state/{refresh-requested,refresh-status.json,refresh.log}`, `cache/`. (`storage_root` in `config.php` already points at the `ddp-inspector/` namespace dir on the volume.)
- `config.php` keys: new `'storage_root'` (string|null), `'gocmd_bin'` (default `'gocmd'`), `'rclone_bin'` (default `'rclone'`); legacy `'ddp_dir'`/`'transcripts_dir'` keep working (dev mode); `'default_n'`, `'base_path'` unchanged.
- `instance.json`: `{"study_name": string, "source_mode": "yoda"|"rd-link"|"local", "local_path": string|null, "cadence": "off"|"daily", "default_n": int, "analysis_dirs": {"<name>": "/abs/path"}}` (`analysis_dirs` optional).
- `source.json`: `{"mode":"yoda","collection":string,"host":"fsw.data.uu.nl","zone":"nluu10p","ticket":string}` OR `{"mode":"rd-link","webdav_url":string,"share_token":string,"password":string}`. Local mode: no source.json. `webdav_url` arrives **fully resolved in either endpoint form** — modern Nextcloud `/public.php/dav/files/<TOKEN>/` (UU's uu.data.surf.nl serves only this; verified live 2026-07-29) or legacy `/public.php/webdav/` (other instances) — Plan 2 consumes it verbatim, never rewrites it.
- `refresh-status.json` (app READS only; Plan 2's script writes): `{"phase":"idle"|"probing"|"pulling"|"extracting"|"done"|"error","started_at":string|null,"finished_at":string|null,"donations":int|null,"message":string}`.
- `state/refresh-requested`: empty flag file; app touches it; Plan 2's service consumes/removes it.

---

### Task 1: Filename metadata — participant, source, key timestamp

**Files:**
- Modify: `src/Ddp.php`
- Test: `tests/DdpTest.php`

**Interfaces:**
- Produces: `ddp_file_meta(string $path): array{participant:string, source:string, key_millis:int}` — `source` defaults `'unknown'`, `key_millis` defaults `0`. Existing `ddp_participant_id_from_filename()` kept (delegates).

- [ ] **Step 1: Write the failing test** — append to `tests/DdpTest.php`:

```php
$meta = ddp_file_meta('/x/assignment=406_task=954_participant=abc_source=instagram_key=1783300000002-instagram.json');
eq($meta['participant'], 'abc', 'meta participant');
eq($meta['source'], 'instagram', 'meta source');
eq($meta['key_millis'], 1783300000002, 'meta key millis');
$meta2 = ddp_file_meta('/x/no-segment.json');
eq($meta2['participant'], 'no-segment', 'meta falls back to stem');
eq($meta2['source'], 'unknown', 'meta source fallback');
eq($meta2['key_millis'], 0, 'meta millis fallback');
```

- [ ] **Step 2: Run to verify it fails** — `php tests/run.php` — Expected: fatal `Call to undefined function ddp_file_meta()`.

- [ ] **Step 3: Implement** in `src/Ddp.php` (below `ddp_participant_id_from_filename`, which becomes a thin wrapper):

```php
/** @return array{participant:string, source:string, key_millis:int} */
function ddp_file_meta(string $path): array {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $out = ['participant' => $stem, 'source' => 'unknown', 'key_millis' => 0];
    foreach (explode('_', $stem) as $seg) {
        if (str_starts_with($seg, 'participant=')) { $out['participant'] = substr($seg, 12); }
        if (str_starts_with($seg, 'source='))      { $out['source'] = substr($seg, 7); }
        if (str_starts_with($seg, 'key=')) {
            if (preg_match('/^key=(\d+)/', $seg, $m)) { $out['key_millis'] = (int)$m[1]; }
        }
    }
    return $out;
}
```

and replace the body of `ddp_participant_id_from_filename()` with `return ddp_file_meta($path)['participant'];`.

- [ ] **Step 4: Run to verify pass** — `php tests/run.php` — all green.
- [ ] **Step 5: Commit** — `git add src/Ddp.php tests/DdpTest.php && git commit -m "feat(ddp): parse source and key timestamp from donation filenames"`

---

### Task 2: `ddp_parse_file` returns tables + deleted-row counts

**Files:**
- Modify: `src/Ddp.php`, `tests/DdpTest.php` (update two assertions — conscious signature change)

**Interfaces:**
- Produces: `ddp_parse_file(string $path): ?array{tables: array<string,list<array>>, deleted: array<string,int>}` — `null` on unreadable/non-list JSON; scalar-only entries skipped; `deleted[name]` from the `"deleted row count"` sibling of table `name` (0 if absent/non-numeric).

- [ ] **Step 1: Update/extend tests** in `tests/DdpTest.php` — replace the three `$sections[...]` assertions with:

```php
$parsed = ddp_parse_file($p1);
eq(is_array($parsed), true, 'parse returns array for conforming file');
eq(count($parsed['tables']['tiktok_watch_history']), 3, 'watch history rows');
eq($parsed['tables']['tiktok_watch_history'][0]['Link'], 'https://www.tiktokv.com/share/video/7654562293757250829/', 'watch link');
eq(isset($parsed['tables']['deleted row count']), false, 'scalar keys not tables');
eq($parsed['deleted']['tiktok_watch_history'], 0, 'deleted count captured (zero)');
```

and add a deleted-count fixture assertion using a new fixture file `tests/fixtures/ddp2/assignment=1_task=1_participant=p2_source=instagram_key=5-instagram.json`:

```json
[{"deleted row count": "2", "instagram_followers": [{"Account": "a", "Date": "2026-01-01T10:00:00", "URL": "https://instagram.com/a"}]}]
```

```php
$p2 = ddp_parse_file(__DIR__ . '/fixtures/ddp2/assignment=1_task=1_participant=p2_source=instagram_key=5-instagram.json');
eq($p2['deleted']['instagram_followers'], 2, 'nonzero deleted count captured');
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php` — Expected: FAILs on `tables` key lookups.

- [ ] **Step 3: Implement** — replace `ddp_parse_file` body:

```php
/** @return array{tables: array<string,list<array>>, deleted: array<string,int>}|null */
function ddp_parse_file(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_is_list($data)) { return null; }
    $tables = []; $deleted = [];
    foreach ($data as $element) {
        if (!is_array($element)) { continue; }
        $del = isset($element['deleted row count']) ? (int)$element['deleted row count'] : 0;
        foreach ($element as $key => $value) {
            if (!is_array($value) || !array_is_list($value)) { continue; }
            $rows = array_values(array_filter($value, 'is_array'));
            $tables[$key] = array_merge($tables[$key] ?? [], $rows);
            $deleted[$key] = ($deleted[$key] ?? 0) + $del;
        }
    }
    return ['tables' => $tables, 'deleted' => $deleted];
}
```

- [ ] **Step 4: Run to verify pass** — `php tests/run.php` (DdpTest green; StatsTest/PagesTest will still pass because they go through `ddp_load_dir`, updated next task — if they fail on `['sections']`, proceed to Task 3 before committing both together only if unavoidable; otherwise commit now).
- [ ] **Step 5: Commit** — `git add src/Ddp.php tests/DdpTest.php tests/fixtures/ddp2 && git commit -m "feat(ddp): capture per-table deleted-row counts in parse"`

> Note: Tasks 2 and 3 change linked signatures. If the suite cannot be green between them, do Steps 1–3 of both, then run, then make two commits in sequence anyway (test-file changes staged with their implementation).

---

### Task 3: `ddp_load_dir` — platform grouping + duplicate-donation rule

**Files:**
- Modify: `src/Ddp.php`, `tests/DdpTest.php`, `tests/StatsTest.php` (loader shape), `tests/fixtures/ddp2/` (new fixtures)

**Interfaces:**
- Produces: `ddp_load_dir(string $dir): array{participants: array<string,array>, skipped: list<array{path:string,reason:string}>}` where each participant is
  `['id'=>string, 'platforms'=>[slug => ['file'=>string, 'key_millis'=>int, 'superseded'=>list<string>, 'tables'=>array<string,list<array>>, 'deleted'=>array<string,int>]]]`.
  Duplicate rule: same participant+source → highest `key_millis` wins; losing files' basenames in `superseded`.

- [ ] **Step 1: Add fixtures** — in `tests/fixtures/ddp2/` add a duplicate pair for `p2` (same source, older key):

`tests/fixtures/ddp2/assignment=1_task=1_participant=p2_source=instagram_key=3-instagram.json`:

```json
[{"deleted row count": "0", "instagram_followers": [{"Account": "old", "Date": "2025-01-01T10:00:00", "URL": "https://instagram.com/old"}]}]
```

- [ ] **Step 2: Write failing tests** — append to `tests/DdpTest.php`:

```php
$loaded2 = ddp_load_dir(__DIR__ . '/fixtures/ddp2');
$p2p = $loaded2['participants']['p2']['platforms']['instagram'];
eq($p2p['key_millis'], 5, 'newest key wins for duplicate participant+source');
eq($p2p['tables']['instagram_followers'][0]['Account'], 'a', 'winning file rows used');
eq($p2p['superseded'], ['assignment=1_task=1_participant=p2_source=instagram_key=3-instagram.json'], 'older file listed as superseded');
$loaded = ddp_load_dir($dir);
eq(array_keys($loaded['participants']), ['p1'], 'only conforming participant loaded');
eq(count($loaded['skipped']), 1, 'one skipped file reported');
eq(count($loaded['participants']['p1']['platforms']['tiktok']['tables']['tiktok_comments']), 2, 'comments present under platform');
```

(remove the old `['sections']`-shaped assertions).

- [ ] **Step 3: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 4: Implement** — replace `ddp_load_dir`:

```php
function ddp_load_dir(string $dir): array {
    $participants = []; $skipped = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
        $parsed = ddp_parse_file($path);
        if ($parsed === null) {
            $skipped[] = ['path' => basename($path), 'reason' => 'not a donation file (skipped)'];
            continue;
        }
        $meta = ddp_file_meta($path);
        $id = $meta['participant']; $src = $meta['source'];
        $participants[$id] ??= ['id' => $id, 'platforms' => []];
        $cur = $participants[$id]['platforms'][$src] ?? null;
        $entry = ['file' => basename($path), 'key_millis' => $meta['key_millis'],
                  'superseded' => [], 'tables' => $parsed['tables'], 'deleted' => $parsed['deleted']];
        if ($cur === null) {
            $participants[$id]['platforms'][$src] = $entry;
        } elseif ($meta['key_millis'] > $cur['key_millis']) {
            $entry['superseded'] = array_merge($cur['superseded'], [$cur['file']]);
            $participants[$id]['platforms'][$src] = $entry;
        } else {
            $participants[$id]['platforms'][$src]['superseded'][] = basename($path);
        }
    }
    ksort($participants);
    foreach ($participants as &$p) { ksort($p['platforms']); }
    return ['participants' => $participants, 'skipped' => $skipped];
}
```

- [ ] **Step 5: Fix `tests/StatsTest.php` loader usages** — replace `$p1 = $loaded['participants']['p1'];` + `$p1['sections'][...]` with `$t1 = $loaded['participants']['p1']['platforms']['tiktok']['tables'];` and use `$t1['tiktok_watch_history']` etc. (Full StatsTest rewrite lands in Task 4; here only make it parse — expect Task 4 to finish the job. If `stats_participant_scope` calls now fatal, comment nothing out — proceed straight to Task 4 before running the whole suite, then commit Tasks 3+4 separately with the suite green at Task 4's end.)
- [ ] **Step 6: Run** — `php tests/run.php` (green if Step 5 sufficed; else finish Task 4 first).
- [ ] **Step 7: Commit** — `git add src/Ddp.php tests/ && git commit -m "feat(ddp): group donations by platform with newest-key duplicate rule"`

---

### Task 4: Generic stats — date detection + per-platform scope

**Files:**
- Modify: `src/Stats.php` (remove `DDP_SECTION_ORDER` consumers, `DDP_VIDEO_SECTIONS`, `stats_unique_video_count`, `stats_participant_scope`; keep `stats_parse_date`, `stats_canonical_video_id` moves in Task 5)
- Test: `tests/StatsTest.php` (rewrite)

**Interfaces:**
- Produces:
  - `stats_parse_date_any(string $s): ?int` — accepts `'Y-m-d H:i:s'` (+optional `' UTC'`) and ISO 8601 (`Y-m-d\TH:i:s`, optional fractional seconds and offset; no-offset = UTC).
  - `stats_row_date(array $row): ?int` — first parseable of columns `Date`, `Timestamp`, `Time`, `time`.
  - `stats_section_summary(list<array> $rows): array{count:int, earliest:?int, latest:?int}` (now uses `stats_row_date`).
  - `stats_platform_scope(array<string,list<array>> $tables, list<string> $order): array{tables: array<string,array>, total_rows:int, earliest:?int, latest:?int}` — `$order` is the display order (Task 7 computes it from FlowDoc; alphabetical fallback); every name in `$order` present even if absent from `$tables`.
- Consumes: loader shape from Task 3.

- [ ] **Step 1: Rewrite `tests/StatsTest.php`** (keep existing date assertions, drop tiktok-order/unique-video ones — those move to `tests/AnalysisTest.php` in Task 5):

```php
<?php
require_once __DIR__ . '/../src/Ddp.php';
require_once __DIR__ . '/../src/Stats.php';

eq(stats_parse_date('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'plain date');
eq(stats_parse_date('2026-05-28 01:36:28 UTC'), gmmktime(1,36,28,5,28,2026), 'UTC-suffixed date');
eq(stats_parse_date('garbage'), null, 'bad date null');

eq(stats_parse_date_any('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'any: legacy format');
eq(stats_parse_date_any('2026-01-01T10:00:00'), gmmktime(10,0,0,1,1,2026), 'any: ISO no offset = UTC');
eq(stats_parse_date_any('2026-01-01T10:00:00+02:00'), gmmktime(8,0,0,1,1,2026), 'any: ISO with offset');
eq(stats_parse_date_any('2026-01-01T10:00:00.123Z'), gmmktime(10,0,0,1,1,2026), 'any: ISO fractional Z');
eq(stats_parse_date_any('nope'), null, 'any: garbage null');

eq(stats_row_date(['Timestamp' => '2026-01-01T10:00:00']), gmmktime(10,0,0,1,1,2026), 'row date via Timestamp');
eq(stats_row_date(['time' => '2026-01-01T10:00:00', 'Date' => 'garbage']), gmmktime(10,0,0,1,1,2026), 'row date skips unparseable, tries next column');
eq(stats_row_date(['Other' => 'x']), null, 'row date none');

$loaded = ddp_load_dir(__DIR__ . '/fixtures/ddp');
$t1 = $loaded['participants']['p1']['platforms']['tiktok']['tables'];
$wh = stats_section_summary($t1['tiktok_watch_history']);
eq($wh['count'], 3, 'watch count');
eq($wh['earliest'], gmmktime(23,24,12,1,30,2026), 'watch earliest');
eq($wh['latest'], gmmktime(11,53,55,7,5,2026), 'watch latest');

$scope = stats_platform_scope($t1, array_keys($t1));
eq($scope['tables']['tiktok_comments']['count'], 2, 'scope comments count');
eq($scope['total_rows'] >= 5, true, 'scope totals rows');
$scope2 = stats_platform_scope($t1, ['tiktok_watch_history', 'absent_table']);
eq($scope2['tables']['absent_table']['count'], 0, 'ordered-but-absent table shown with 0');
eq(array_key_first($scope2['tables']), 'tiktok_watch_history', 'display order respected');
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement** in `src/Stats.php` — delete `DDP_VIDEO_SECTIONS`, `stats_unique_video_count`, `stats_participant_scope`; add:

```php
function stats_parse_date_any(string $s): ?int {
    $ts = stats_parse_date($s);
    if ($ts !== null) { return $ts; }
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $s)) { return null; }
    try { $dt = new DateTimeImmutable($s, new DateTimeZone('UTC')); } catch (Exception) { return null; }
    return $dt->getTimestamp();
}

function stats_row_date(array $row): ?int {
    foreach (['Date', 'Timestamp', 'Time', 'time'] as $col) {
        if (!isset($row[$col]) || !is_scalar($row[$col])) { continue; }
        $ts = stats_parse_date_any((string)$row[$col]);
        if ($ts !== null) { return $ts; }
    }
    return null;
}

/** @param array<string,list<array>> $tables @param list<string> $order */
function stats_platform_scope(array $tables, array $order): array {
    $out = []; $total = 0; $earliest = null; $latest = null;
    $names = array_values(array_unique(array_merge($order, array_keys($tables))));
    foreach ($names as $name) {
        $s = stats_section_summary($tables[$name] ?? []);
        $out[$name] = $s;
        $total += $s['count'];
        if ($s['earliest'] !== null && ($earliest === null || $s['earliest'] < $earliest)) { $earliest = $s['earliest']; }
        if ($s['latest'] !== null && ($latest === null || $s['latest'] > $latest)) { $latest = $s['latest']; }
    }
    return ['tables' => $out, 'total_rows' => $total, 'earliest' => $earliest, 'latest' => $latest];
}
```

and change `stats_section_summary`'s per-row line from `isset($row['Date']) ? stats_parse_date(...)` to `$ts = stats_row_date($row);`.
In `src/Ddp.php`, delete `DDP_SECTION_ORDER` (its only consumer was the deleted `stats_participant_scope`; pages get order from FlowDoc in Task 8).

- [ ] **Step 4: Run to verify pass** — `php tests/run.php` — StatsTest green. PagesTest now fails (pages still call deleted functions) — acceptable ONLY if Tasks 4–5 and the Task 9 page rewrite are executed in the same session; otherwise keep `stats_participant_scope` as a deprecated wrapper calling `stats_platform_scope($participant['platforms']['tiktok']['tables'] ?? [], [])` until Task 9. Prefer the wrapper: keep the suite green at every commit.
- [ ] **Step 5: Commit** — `git add src/Stats.php src/Ddp.php tests/StatsTest.php && git commit -m "feat(stats): generic date detection and per-platform scope"`

---

### Task 5: Analyses seam — `src/Analysis.php` with transcripts module

**Files:**
- Create: `src/Analysis.php`, `tests/AnalysisTest.php`
- Modify: `src/Stats.php` (move `stats_canonical_video_id` out), `src/bootstrap.php` (require Analysis.php), `tests/run.php` (register AnalysisTest), `tests/StatsTest.php` (drop moved assertions)

**Interfaces:**
- Produces:
  - `analysis_tiktok_video_id(string $url): ?string` — the exact regex previously in `stats_canonical_video_id` (function moves; old name deleted; no alias).
  - `analysis_modules(): array<string,array{platform:string, entity_id:callable(array):?string, label:string}>` — returns `['transcripts' => ['platform'=>'tiktok', 'entity_id'=><fn using Link column>, 'label'=>'transcript']]`.
  - `analysis_dir(string $name): ?string` — `instance.json`'s `analysis_dirs[$name]` if set (Task 6's `inst_load()`), else `<paths.analyses>/$name`, else legacy: for `transcripts`, `cfg('transcripts_dir')`; null if nothing configured.
  - `analysis_row_links(string $platform, array $row): list<array{label:string, url:string}>` — for each module matching `$platform`, derive entity id; non-null → link `url('transcript.php?vid=' . $id)`.
  - `analysis_transcript_paths(string $vid): array{txt:?string, json:?string}` — sharded `<dir>/<last-2>/<vid>.txt|.json`, null members when missing/dir unset.

- [ ] **Step 1: Write failing tests** — `tests/AnalysisTest.php`:

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';

eq(analysis_tiktok_video_id('https://www.tiktokv.com/share/video/7654562293757250829/'), '7654562293757250829', 'canonical tiktokv share');
eq(analysis_tiktok_video_id('https://www.tiktok.com/@user/video/7654562293757250829'), '7654562293757250829', 'canonical @user form');
eq(analysis_tiktok_video_id('https://vm.tiktok.com/abc123/'), null, 'short link not canonical');
eq(analysis_tiktok_video_id('not a url'), null, 'garbage not canonical');

$links = analysis_row_links('tiktok', ['Link' => 'https://www.tiktokv.com/share/video/7654562293757250829/']);
eq(count($links), 1, 'tiktok row with canonical link gets one analysis link');
check(str_contains($links[0]['url'], 'transcript.php?vid=7654562293757250829'), 'link targets transcript page');
eq(analysis_row_links('instagram', ['Link' => 'https://www.tiktokv.com/share/video/7654562293757250829/']), [], 'non-tiktok platform gets no transcript link');
eq(analysis_row_links('tiktok', ['Comment' => 'hi']), [], 'row without entity id gets no link');

// transcript path resolution via legacy transcripts_dir (config.test.php)
$tp = analysis_transcript_paths('7654562293757250829');
check($tp['txt'] !== null && str_ends_with($tp['txt'], '/29/7654562293757250829.txt'), 'sharded txt path resolved');
$missing = analysis_transcript_paths('1111111111111111111');
eq($missing['txt'], null, 'missing transcript -> null txt');
```

Register in `tests/run.php`: add `'AnalysisTest.php'` to the file list (after `SampleTest.php`).

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement** — `src/Analysis.php`:

```php
<?php
function analysis_tiktok_video_id(string $url): ?string {
    $re = '~^https?://(?:www\.)?(?:tiktokv|tiktok)\.com/(?:share/video|@[^/]+/video)/(\d{19})(?:/|\?|$)~';
    if (preg_match($re, $url, $m)) { return $m[1]; }
    return null;
}

/** @return array<string,array{platform:string, entity_id:callable, label:string}> */
function analysis_modules(): array {
    return [
        'transcripts' => [
            'platform'  => 'tiktok',
            'label'     => 'transcript',
            'entity_id' => fn(array $row): ?string =>
                isset($row['Link']) ? analysis_tiktok_video_id((string)$row['Link']) : null,
        ],
    ];
}

function analysis_dir(string $name): ?string {
    $inst = function_exists('inst_load') ? inst_load() : null;
    if (is_array($inst) && isset($inst['analysis_dirs'][$name])) { return rtrim((string)$inst['analysis_dirs'][$name], '/'); }
    if (function_exists('inst_paths')) {
        $paths = inst_paths();
        if ($paths['analyses'] !== null) { return $paths['analyses'] . '/' . $name; }
    }
    if ($name === 'transcripts' && cfg('transcripts_dir')) { return rtrim((string)cfg('transcripts_dir'), '/'); }
    return null;
}

/** @return list<array{label:string, url:string}> */
function analysis_row_links(string $platform, array $row): array {
    $links = [];
    foreach (analysis_modules() as $mod) {
        if ($mod['platform'] !== $platform) { continue; }
        $id = ($mod['entity_id'])($row);
        if ($id !== null) { $links[] = ['label' => $mod['label'], 'url' => url('transcript.php?vid=' . $id)]; }
    }
    return $links;
}

/** @return array{txt:?string, json:?string} */
function analysis_transcript_paths(string $vid): array {
    $dir = analysis_dir('transcripts');
    if ($dir === null) { return ['txt' => null, 'json' => null]; }
    $base = $dir . '/' . substr($vid, -2) . '/' . $vid;
    return ['txt' => is_file($base . '.txt') ? $base . '.txt' : null,
            'json' => is_file($base . '.json') ? $base . '.json' : null];
}
```

Until Task 6 exists, `inst_load`/`inst_paths` are absent — the `function_exists` guards make this file self-contained now. Delete `stats_canonical_video_id` from `src/Stats.php`; move its four StatsTest assertions out (they are now in AnalysisTest). Add `require_once __DIR__ . '/Analysis.php';` to `src/bootstrap.php` after Sample.php. Update `public/participant.php`/`public/transcript.php` references from `stats_canonical_video_id` to `analysis_tiktok_video_id` (page rewrites come later; this keeps them loading).

- [ ] **Step 4: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 5: Commit** — `git add src/Analysis.php src/Stats.php src/bootstrap.php tests/ public/ && git commit -m "feat(analysis): linking seam with transcripts as first module"`

---

### Task 6: Instance layer part 1 — storage tree, config IO, configured-check

**Files:**
- Create: `src/Instance.php`, `tests/InstanceTest.php`, `tests/config.storage.php`
- Modify: `src/bootstrap.php` (require Instance.php before Analysis.php), `tests/run.php` (register InstanceTest)

**Interfaces:**
- Produces:
  - `inst_root(): ?string` — `cfg('storage_root')` rtrim'd, or null.
  - `inst_paths(): array{config:?string, flows:?string, inbox:?string, analyses:?string, state:?string, cache:?string}` — all null in legacy mode.
  - `inst_effective_ddp_dir(): ?string` — storage mode: `<root>/data/inbox`; legacy: `cfg('ddp_dir')`.
  - `inst_configured(): bool` — storage mode: `instance.json` exists; legacy mode: `ddp_dir` set.
  - `inst_load(): ?array` / `inst_save(array $instance): bool` — instance.json, atomic.
  - `inst_source_save(array $source): bool` (atomic + chmod 0600) / `inst_source_exists(): bool` / `inst_source_load(): ?array`.
  - `inst_write_json_atomic(string $path, array $data, int $mode = 0644): bool` — mkdir -p parent, write `.tmp`, chmod, rename.
- Consumes: `cfg()` from bootstrap.

- [ ] **Step 1: Fixture config** — `tests/config.storage.php`:

```php
<?php
return [
    'storage_root' => getenv('DDP_TEST_STORAGE') ?: sys_get_temp_dir() . '/ddp-inspector-test',
    'default_n'    => 15,
    'base_path'    => '',
];
```

- [ ] **Step 2: Write failing tests** — `tests/InstanceTest.php` (register in `tests/run.php` after AnalysisTest):

```php
<?php
// Runs with the legacy test config already loaded by earlier test files.
require_once __DIR__ . '/../src/bootstrap.php';

eq(inst_root(), null, 'legacy config has no storage root');
eq(inst_effective_ddp_dir(), __DIR__ . '/fixtures/ddp', 'legacy effective ddp_dir');
eq(inst_configured(), true, 'legacy mode with ddp_dir counts as configured');

// Switch to storage mode against a scratch tree.
$scratch = sys_get_temp_dir() . '/ddp-inspector-test-' . getmypid();
exec('rm -rf ' . escapeshellarg($scratch));
$GLOBALS['__cfg_saved_inst'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $scratch, 'default_n' => 15, 'base_path' => ''];

eq(inst_root(), $scratch, 'storage root read from config');
eq(inst_paths()['inbox'], "$scratch/data/inbox", 'inbox path derived');
eq(inst_effective_ddp_dir(), "$scratch/data/inbox", 'storage effective ddp_dir');
eq(inst_configured(), false, 'no instance.json -> unconfigured');
eq(inst_load(), null, 'load returns null when missing');

$inst = ['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/tmp/x',
         'cadence' => 'off', 'default_n' => 15];
eq(inst_save($inst), true, 'instance saved');
eq(inst_configured(), true, 'instance.json -> configured');
eq(inst_load()['study_name'], 'Pilot', 'instance round-trips');

$src = ['mode' => 'yoda', 'collection' => '/nluu10p/home/x', 'host' => 'fsw.data.uu.nl',
        'zone' => 'nluu10p', 'ticket' => 'SECRET'];
eq(inst_source_save($src), true, 'source saved');
eq(inst_source_load()['ticket'], 'SECRET', 'source round-trips');
eq(substr(sprintf('%o', fileperms("$scratch/config/source.json")), -3), '600', 'source.json is 0600');
check(!file_exists("$scratch/config/source.json.tmp"), 'atomic write leaves no temp file');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_inst'];
```

- [ ] **Step 3: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 4: Implement** — `src/Instance.php`:

```php
<?php
function inst_root(): ?string {
    $r = cfg('storage_root');
    return (is_string($r) && $r !== '') ? rtrim($r, '/') : null;
}

/** @return array{config:?string, flows:?string, inbox:?string, analyses:?string, state:?string, cache:?string} */
function inst_paths(): array {
    $root = inst_root();
    if ($root === null) {
        return ['config' => null, 'flows' => null, 'inbox' => null,
                'analyses' => null, 'state' => null, 'cache' => null];
    }
    return ['config' => "$root/config", 'flows' => "$root/config/flows",
            'inbox' => "$root/data/inbox", 'analyses' => "$root/data/analyses",
            'state' => "$root/state", 'cache' => "$root/cache"];
}

function inst_effective_ddp_dir(): ?string {
    $p = inst_paths();
    if ($p['inbox'] !== null) { return $p['inbox']; }
    $d = cfg('ddp_dir');
    return (is_string($d) && $d !== '') ? $d : null;
}

function inst_configured(): bool {
    $p = inst_paths();
    if ($p['config'] !== null) { return is_file($p['config'] . '/instance.json'); }
    return inst_effective_ddp_dir() !== null;
}

function inst_write_json_atomic(string $path, array $data, int $mode = 0644): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return false; }
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . "\n") === false) { return false; }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

function inst_read_json(?string $path): ?array {
    if ($path === null || !is_file($path)) { return null; }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function inst_load(): ?array {
    $c = inst_paths()['config'];
    return $c === null ? null : inst_read_json($c . '/instance.json');
}
function inst_save(array $instance): bool {
    $c = inst_paths()['config'];
    return $c !== null && inst_write_json_atomic($c . '/instance.json', $instance);
}
function inst_source_load(): ?array {
    $c = inst_paths()['config'];
    return $c === null ? null : inst_read_json($c . '/source.json');
}
function inst_source_exists(): bool {
    $c = inst_paths()['config'];
    return $c !== null && is_file($c . '/source.json');
}
function inst_source_save(array $source): bool {
    $c = inst_paths()['config'];
    return $c !== null && inst_write_json_atomic($c . '/source.json', $source, 0600);
}
```

Add `require_once __DIR__ . '/Instance.php';` to `src/bootstrap.php` before Analysis.php (Analysis's `function_exists` guards now find it).

- [ ] **Step 5: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 6: Commit** — `git add src/Instance.php src/bootstrap.php tests/ && git commit -m "feat(instance): storage-tree resolution and atomic config IO"`

---

### Task 7: Instance layer part 2 — status, refresh flag, log tail, probes

**Files:**
- Modify: `src/Instance.php`, `tests/InstanceTest.php`

**Interfaces:**
- Produces:
  - `inst_status(): array` — refresh-status.json or `['phase'=>'idle','started_at'=>null,'finished_at'=>null,'donations'=>null,'message'=>'']`.
  - `inst_touch_refresh(): bool` — touch `state/refresh-requested` (mkdir -p state).
  - `inst_log_tail(int $lines = 40): string` — last N lines of `state/refresh.log`, `''` if absent.
  - `inst_donation_count(): int` — count of `*.json` in effective ddp dir.
  - `inst_probe(): array{ok:bool, message:string, count:?int}` — dispatch on `instance.json.source_mode`:
    - `local`: `local_path` readable → ok + donation glob count; else plain-language failure.
    - `yoda`: build temp gocmd config (below), run `[gocmd_bin] -c <tmp> ls <collection>` with 15 s timeout; missing binary → `['ok'=>false,'message'=>'Connection test unavailable on this machine — you can still save and refresh.','count'=>null]`.
    - `rd-link`: run `[rclone_bin] lsd :webdav: --webdav-url <url> --webdav-user <token> --webdav-pass <obscured>` 15 s timeout; same graceful missing-binary path. On failure of the stored `webdav_url`, retry ONCE with the legacy endpoint form `<scheme>://<host>/public.php/webdav/`; if the legacy retry succeeds, atomically rewrite `source.json` with the working legacy URL and return ok with the normal success message. Only when BOTH forms fail return the friendly error. (Modern Nextcloud instances — e.g. uu.data.surf.nl — serve only `/public.php/dav/files/<TOKEN>/`; legacy instances serve only `/public.php/webdav/`.)
  - `inst_run(array $argv, int $timeoutSec): array{code:int, out:string}` — `proc_open` wrapper, no shell.

- [ ] **Step 1: Write failing tests** — append to `tests/InstanceTest.php` (inside the storage-mode scratch block, before restoring `__cfg`):

```php
eq(inst_status()['phase'], 'idle', 'status defaults to idle');
inst_write_json_atomic("$scratch/state/refresh-status.json",
    ['phase' => 'done', 'started_at' => '2026-07-29T10:00:00Z', 'finished_at' => '2026-07-29T10:05:00Z',
     'donations' => 142, 'message' => 'ok']);
eq(inst_status()['donations'], 142, 'status file read');

eq(inst_touch_refresh(), true, 'refresh flag touched');
check(is_file("$scratch/state/refresh-requested"), 'flag file exists');

file_put_contents("$scratch/state/refresh.log", "a\nb\nc\n");
eq(inst_log_tail(2), "b\nc", 'log tail returns last lines');

@mkdir("$scratch/data/inbox", 0755, true);
file_put_contents("$scratch/data/inbox/assignment=1_task=1_participant=z_source=tiktok_key=1-tiktok.json", '[]');
eq(inst_donation_count(), 1, 'donation count');

inst_save(['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => "$scratch/data/inbox",
           'cadence' => 'off', 'default_n' => 15]);
$probe = inst_probe();
eq($probe['ok'], true, 'local probe ok');
eq($probe['count'], 1, 'local probe counts donations');

inst_save(['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/nonexistent/nope',
           'cadence' => 'off', 'default_n' => 15]);
$probe = inst_probe();
eq($probe['ok'], false, 'local probe fails on missing dir');
check(!str_contains(strtolower($probe['message']), 'rclone'), 'probe messages stay plain-language');

// yoda probe with a guaranteed-missing binary degrades gracefully
$GLOBALS['__cfg']['gocmd_bin'] = '/nonexistent/gocmd-binary';
inst_save(['study_name' => 'Pilot', 'source_mode' => 'yoda', 'local_path' => null,
           'cadence' => 'off', 'default_n' => 15]);
inst_source_save(['mode' => 'yoda', 'collection' => '/nluu10p/home/x',
                  'host' => 'fsw.data.uu.nl', 'zone' => 'nluu10p', 'ticket' => 'T']);
$probe = inst_probe();
eq($probe['ok'], false, 'missing binary -> not ok');
check(str_contains($probe['message'], 'unavailable'), 'missing binary -> unavailable message');

// rd-link probe: modern endpoint fails -> legacy fallback succeeds -> source.json rewritten
$fake = "$scratch/fake-rclone";
file_put_contents($fake,
    "#!/bin/sh\ncase \"\$*\" in\n  *obscure*) echo OBSCURED; exit 0;;\n  *'/public.php/dav/files/'*) exit 1;;\n  *) exit 0;;\nesac\n");
chmod($fake, 0755);
$GLOBALS['__cfg']['rclone_bin'] = $fake;
inst_save(['study_name' => 'RD', 'source_mode' => 'rd-link', 'local_path' => null,
           'cadence' => 'off', 'default_n' => 15]);
inst_source_save(['mode' => 'rd-link',
                  'webdav_url' => 'https://uu.data.surf.nl/public.php/dav/files/TOK/',
                  'share_token' => 'TOK', 'password' => 'pw']);
$probe = inst_probe();
eq($probe['ok'], true, 'rd-link probe falls back to legacy endpoint');
eq(inst_source_load()['webdav_url'], 'https://uu.data.surf.nl/public.php/webdav/',
   'source.json rewritten with working legacy url');

// both endpoint forms failing -> friendly error, source.json untouched
file_put_contents($fake, "#!/bin/sh\ncase \"\$*\" in *obscure*) echo OBSCURED; exit 0;; *) exit 1;; esac\n");
$probe = inst_probe();
eq($probe['ok'], false, 'rd-link probe fails when both endpoint forms fail');
eq(inst_source_load()['webdav_url'], 'https://uu.data.surf.nl/public.php/webdav/',
   'failed probe does not rewrite source.json');
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement** — append to `src/Instance.php`:

```php
function inst_status(): array {
    $s = inst_paths()['state'];
    $data = $s === null ? null : inst_read_json($s . '/refresh-status.json');
    return is_array($data) ? $data + ['phase' => 'idle', 'message' => '']
        : ['phase' => 'idle', 'started_at' => null, 'finished_at' => null, 'donations' => null, 'message' => ''];
}

function inst_touch_refresh(): bool {
    $s = inst_paths()['state'];
    if ($s === null) { return false; }
    if (!is_dir($s) && !@mkdir($s, 0755, true)) { return false; }
    return @touch($s . '/refresh-requested');
}

function inst_log_tail(int $lines = 40): string {
    $s = inst_paths()['state'];
    if ($s === null || !is_file($s . '/refresh.log')) { return ''; }
    $all = explode("\n", rtrim((string)@file_get_contents($s . '/refresh.log'), "\n"));
    return implode("\n", array_slice($all, -$lines));
}

function inst_donation_count(): int {
    $dir = inst_effective_ddp_dir();
    if ($dir === null || !is_dir($dir)) { return 0; }
    return count(glob(rtrim($dir, '/') . '/*.json') ?: []);
}

/** @return array{code:int, out:string} */
function inst_run(array $argv, int $timeoutSec): array {
    $bin = $argv[0];
    if (!is_file($bin) && !inst_which($bin)) { return ['code' => 127, 'out' => '']; }
    $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { return ['code' => 127, 'out' => '']; }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $deadline = time() + $timeoutSec;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = $st['exitcode']; break; }
        if (time() > $deadline) { proc_terminate($proc, 9); $code = -1; break; }
        usleep(100_000);
    }
    foreach ($pipes as $p) { @fclose($p); }
    @proc_close($proc);
    return ['code' => $code, 'out' => $out];
}

function inst_which(string $bin): bool {
    if (str_contains($bin, '/')) { return is_file($bin) && is_executable($bin); }
    foreach (explode(':', (string)getenv('PATH')) as $d) {
        if ($d !== '' && is_executable("$d/$bin")) { return true; }
    }
    return false;
}

/** @return array{ok:bool, message:string, count:?int} */
function inst_probe(): array {
    $inst = inst_load();
    $mode = $inst['source_mode'] ?? null;
    if ($mode === 'local') {
        $dir = (string)($inst['local_path'] ?? '');
        if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
            return ['ok' => false, 'count' => null,
                    'message' => 'That folder cannot be read. Check the path with whoever set up this workspace.'];
        }
        $n = count(glob(rtrim($dir, '/') . '/*.json') ?: []);
        return ['ok' => true, 'count' => $n, 'message' => "Found $n donation file(s) ✓"];
    }
    $src = inst_source_load();
    if ($src === null) { return ['ok' => false, 'count' => null, 'message' => 'No connection details saved yet — complete step 2 first.']; }
    if ($mode === 'yoda') {
        $bin = (string)cfg('gocmd_bin', 'gocmd');
        if (!inst_which($bin)) {
            return ['ok' => false, 'count' => null,
                    'message' => 'Connection test unavailable on this machine — you can still save and refresh.'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gocfg');
        file_put_contents($tmp, inst_gocmd_config($src)); @chmod($tmp, 0600);
        $r = inst_run([$bin, '-c', $tmp, 'ls', (string)$src['collection']], 15);
        @unlink($tmp);
        if ($r['code'] === 0) { return ['ok' => true, 'count' => null, 'message' => 'Connected ✓ — your access code works.']; }
        return ['ok' => false, 'count' => null,
                'message' => 'Could not connect. Your access code may have expired — ask your data manager for a new one.'];
    }
    if ($mode === 'rd-link') {
        $bin = (string)cfg('rclone_bin', 'rclone');
        if (!inst_which($bin)) {
            return ['ok' => false, 'count' => null,
                    'message' => 'Connection test unavailable on this machine — you can still save and refresh.'];
        }
        $obscured = inst_run([$bin, 'obscure', (string)$src['password']], 15);
        $try = function (string $url) use ($bin, $src, $obscured): bool {
            $r = inst_run([$bin, 'lsd', ':webdav:', '--webdav-url', $url,
                           '--webdav-user', (string)$src['share_token'],
                           '--webdav-pass', trim($obscured['out']), '--contimeout', '10s'], 15);
            return $r['code'] === 0;
        };
        $ok = ['ok' => true, 'count' => null, 'message' => 'Connected ✓ — the share link works.'];
        if ($try((string)$src['webdav_url'])) { return $ok; }
        // Endpoint-form fallback: modern Nextcloud serves /public.php/dav/files/<TOKEN>/,
        // legacy instances serve /public.php/webdav/ — the wild has both.
        $parts = parse_url((string)$src['webdav_url']);
        if ($parts !== false && isset($parts['scheme'], $parts['host'])) {
            $legacy = $parts['scheme'] . '://' . $parts['host'] . '/public.php/webdav/';
            if ($legacy !== (string)$src['webdav_url'] && $try($legacy)) {
                inst_source_save(['mode' => 'rd-link', 'webdav_url' => $legacy,
                    'share_token' => (string)$src['share_token'],
                    'password' => (string)$src['password']]);
                return $ok;
            }
        }
        return ['ok' => false, 'count' => null,
                'message' => 'That link doesn\'t work — the share may have expired or the password is wrong. Create a new share link and paste it here.'];
    }
    return ['ok' => false, 'count' => null, 'message' => 'No data source configured yet.'];
}

function inst_gocmd_config(array $src): string {
    // Credential-free anonymous read-ticket config (validated pattern 2026-07-13;
    // field names must match the transcribe repo's ticket config — verify once
    // against d3i-infra/researchcloud-ddp-transcribe when wiring Plan 2's refresh
    // script, and adjust ONLY here if they differ).
    return "irods_host: {$src['host']}\n"
         . "irods_port: 1247\n"
         . "irods_zone_name: {$src['zone']}\n"
         . "irods_user_name: anonymous\n"
         . "irods_ticket: {$src['ticket']}\n";
}
```

- [ ] **Step 4: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 5: Commit** — `git add src/Instance.php tests/InstanceTest.php && git commit -m "feat(instance): refresh status, flag, log tail, and source probes"`

---

### Task 8: FlowDoc — parse `documentation.txt`, match tables, ordering

**Files:**
- Create: `src/Flows.php`, `tests/FlowsTest.php`, fixtures `tests/fixtures/flows/{tiktok,facebook}/documentation.txt`
- Modify: `src/bootstrap.php` (require Flows.php), `tests/run.php` (register FlowsTest)

**Interfaces:**
- Produces:
  - `flows_parse_doc(string $text): ?array{platform_title:string, commit:?string, sections: list<array{title:string, description:string, vars:list<string>, var_desc:array<string,string>}>}` — `## Build information` excluded from sections; null when no `# ` heading.
  - `flows_load_all(): array<string, array>` — parsed docs per platform slug from `<flows dir>/<slug>/documentation.txt` (storage mode) — empty array in legacy mode or when dir absent.
  - `flows_match(array<string,list<array>> $tables, ?array $doc): array<string,?int>` — table name → section index; column-set equality (table columns = union of row keys); ambiguity resolved by claiming doc sections in document order against tables in donation-file order; unmatched → null.
  - `flows_table_order(array<string,?int> $match): list<string>` — matched tables in doc-section order first, then unmatched alphabetically.
  - `flows_prettify(string $key): string` — `ucfirst(str_replace('_', ' ', $key))`.

- [ ] **Step 1: Fixtures** — `tests/fixtures/flows/facebook/documentation.txt` (near-duplicate column sets, mirrors the real Facebook export):

```markdown
# Facebook

This document describes the data tables and variables included in the data donation flow for Facebook.

## Facebook items you recently viewed

This table shows the Facebook posts, videos, and other items you have recently viewed.

| Variable | Description |
| -------- | ----------- |
| `Category` | Content category (e.g. Videos, Marketplace). |
| `Date` | ISO 8601 timestamp of when the item was viewed. |
| `Link` | URL of the viewed item. |
| `Name` | Name or title of the viewed item. |

## Profiles you visited recently

This table lists the Facebook profiles you have visited most recently.

| Variable | Description |
| -------- | ----------- |
| `Category` | Category of the visited item. |
| `Date` | ISO 8601 timestamp of when the visit occurred. |
| `Link` | URL of the visited profile or page. |
| `Name` | Name or title of the visited profile or page. |

## Your search history

This table contains a record of your search queries on Facebook.

| Variable | Description |
| -------- | ----------- |
| `Date` | ISO 8601 timestamp of when the search was made. |
| `Search term` | The search query entered by the participant. |

---

## Build information

This flow was generated from commit `356f6e10dbb38dbf1eb69eea220c68a5c0f47ff3` of the data-donation-task repository.
```

`tests/fixtures/flows/tiktok/documentation.txt`:

```markdown
# TikTok

This document describes the data tables and variables included in the data donation flow for TikTok.

## Videos you watched

Watch history from your TikTok account.

| Variable | Description |
| -------- | ----------- |
| `Date` | Timestamp of the view. |
| `Link` | URL of the watched video. |

## Comments you posted

Your comments.

| Variable | Description |
| -------- | ----------- |
| `Date` | Timestamp of the comment. |
| `Comment` | Text of the comment. |
```

- [ ] **Step 2: Write failing tests** — `tests/FlowsTest.php` (register in `tests/run.php`):

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';

$doc = flows_parse_doc((string)file_get_contents(__DIR__ . '/fixtures/flows/facebook/documentation.txt'));
eq($doc['platform_title'], 'Facebook', 'doc platform title');
eq($doc['commit'], '356f6e10dbb38dbf1eb69eea220c68a5c0f47ff3', 'doc commit parsed');
eq(count($doc['sections']), 3, 'build information excluded from sections');
eq($doc['sections'][2]['title'], 'Your search history', 'section title');
eq($doc['sections'][2]['vars'], ['Date', 'Search term'], 'section variables');
check(str_contains($doc['sections'][0]['description'], 'recently viewed'), 'section description captured');
eq($doc['sections'][2]['var_desc']['Search term'], 'The search query entered by the participant.', 'variable description');
eq(flows_parse_doc('no heading here'), null, 'unparseable doc -> null');

// Matching: two tables with identical column sets resolve by order.
$tables = [
    'facebook_recently_viewed' => [['Category' => 'Videos', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_recently_visited' => [['Category' => 'Profiles', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_search_history' => [['Date' => 'd', 'Search term' => 'q']],
    'facebook_mystery' => [['Zzz' => 1]],
];
$match = flows_match($tables, $doc);
eq($match['facebook_recently_viewed'], 0, 'first ambiguous table claims first doc section');
eq($match['facebook_recently_visited'], 1, 'second ambiguous table claims second doc section');
eq($match['facebook_search_history'], 2, 'unique column set matches');
eq($match['facebook_mystery'], null, 'unmatched table -> null');
eq(flows_table_order($match),
   ['facebook_recently_viewed', 'facebook_recently_visited', 'facebook_search_history', 'facebook_mystery'],
   'order: doc order then alphabetical unmatched');
eq(flows_prettify('tiktok_watch_history'), 'Tiktok watch history', 'prettified key');
eq(flows_match($tables, null), ['facebook_recently_viewed' => null, 'facebook_recently_visited' => null,
   'facebook_search_history' => null, 'facebook_mystery' => null], 'no doc -> all unmatched');
```

- [ ] **Step 3: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 4: Implement** — `src/Flows.php`:

```php
<?php
function flows_parse_doc(string $text): ?array {
    $lines = preg_split('/\r?\n/', $text);
    $title = null; $commit = null; $sections = []; $cur = null;
    foreach ($lines as $line) {
        if ($title === null && preg_match('/^# (.+)$/', $line, $m)) { $title = trim($m[1]); continue; }
        if (preg_match('/^## (.+)$/', $line, $m)) {
            if ($cur !== null) { $sections[] = $cur; }
            $head = trim($m[1]);
            $cur = ($head === 'Build information') ? ['__build' => true, 'title' => $head, 'description' => '', 'vars' => [], 'var_desc' => []]
                                                   : ['title' => $head, 'description' => '', 'vars' => [], 'var_desc' => []];
            continue;
        }
        if ($cur === null) { continue; }
        if (preg_match('/^\|\s*`([^`]+)`\s*\|\s*(.*?)\s*\|$/', $line, $m)) {
            $cur['vars'][] = $m[1]; $cur['var_desc'][$m[1]] = $m[2]; continue;
        }
        if (preg_match('/commit `([0-9a-f]{7,40})`/', $line, $m)) { $commit = $m[1]; }
        if (!str_starts_with($line, '|') && trim($line) !== '' && trim($line) !== '---' && $cur['vars'] === []) {
            $cur['description'] .= (($cur['description'] === '') ? '' : ' ') . trim($line);
        }
    }
    if ($cur !== null) { $sections[] = $cur; }
    if ($title === null) { return null; }
    $sections = array_values(array_filter($sections, fn($s) => !isset($s['__build'])));
    return ['platform_title' => $title, 'commit' => $commit, 'sections' => $sections];
}

/** @return array<string, array> parsed doc per platform slug */
function flows_load_all(): array {
    $flows = inst_paths()['flows'];
    if ($flows === null || !is_dir($flows)) { return []; }
    $out = [];
    foreach (glob("$flows/*/documentation.txt") ?: [] as $docPath) {
        $slug = basename(dirname($docPath));
        $doc = flows_parse_doc((string)@file_get_contents($docPath));
        if ($doc !== null) { $out[$slug] = $doc; }
    }
    ksort($out);
    return $out;
}

/** @param array<string,list<array>> $tables @return array<string,?int> */
function flows_match(array $tables, ?array $doc): array {
    $match = array_fill_keys(array_keys($tables), null);
    if ($doc === null) { return $match; }
    $claimed = [];
    foreach ($tables as $name => $rows) {
        $cols = [];
        foreach ($rows as $row) { foreach (array_keys($row) as $k) { $cols[$k] = true; } }
        $cols = array_keys($cols); sort($cols);
        foreach ($doc['sections'] as $i => $sec) {
            if (isset($claimed[$i])) { continue; }
            $vars = $sec['vars']; sort($vars);
            if ($vars === $cols && $vars !== []) { $match[$name] = $i; $claimed[$i] = true; break; }
        }
    }
    return $match;
}

/** @param array<string,?int> $match @return list<string> */
function flows_table_order(array $match): array {
    $matched = array_filter($match, fn($i) => $i !== null);
    asort($matched);
    $unmatched = array_keys(array_filter($match, fn($i) => $i === null));
    sort($unmatched);
    return array_merge(array_keys($matched), $unmatched);
}

function flows_prettify(string $key): string {
    return ucfirst(str_replace('_', ' ', $key));
}
```

Add `require_once __DIR__ . '/Flows.php';` to `src/bootstrap.php` (after Instance.php).

- [ ] **Step 5: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 6: Commit** — `git add src/Flows.php src/bootstrap.php tests/ && git commit -m "feat(flows): parse flow documentation and match donation tables"`

---

### Task 9: Flow-export upload ingestion (PharData + security limits)

**Files:**
- Modify: `src/Flows.php`, `tests/FlowsTest.php`

**Interfaces:**
- Produces:
  - `flows_ingest_upload(string $zipPath, string $flowsDir): array{ok:bool, message:string, slug:?string, table_count:?int}` — validates and installs one export zip into `<flowsDir>/<slug>/{documentation.txt,build-meta.json}` (replacing any existing slug dir contents).
  - `flows_slug_from_build_name(string $name): ?string` — `build_p_izzeui_instagram_development_2026-07-29_11-05-27.zip` → `instagram` (4th-from-last `_`-separated part before the two date/time parts and after env; concretely: split basename-without-`.zip` on `_`, slug = `parts[count-4]`; null unless `^[a-z0-9]+$` and parts ≥ 5 and parts[0] === 'build').
  - Limits enforced: zip ≤ 64 MiB, ≤ 64 entries, entry names must not contain `..` or start with `/`; must contain `documentation.txt` and exactly one `build_*.zip`; `documentation.txt` must parse (`flows_parse_doc`). All failures return plain-language `message`, never throw.

- [ ] **Step 1: Write failing tests** — append to `tests/FlowsTest.php`:

```php
// Build a valid export zip programmatically (PharData writes zips fine).
$tmpdir = sys_get_temp_dir() . '/ddp-flows-test-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmpdir)); mkdir($tmpdir, 0755, true);
$zipPath = "$tmpdir/build_1785315943.zip";
$phar = new PharData($zipPath);
$phar->addFromString('documentation.txt', (string)file_get_contents(__DIR__ . '/fixtures/flows/facebook/documentation.txt'));
$phar->addFromString('build_znptwlaf_facebook_development_2026-07-29_11-03-36.zip', 'dummy-inner-zip-bytes');
unset($phar);

eq(flows_slug_from_build_name('build_p_izzeui_instagram_development_2026-07-29_11-05-27.zip'), 'instagram', 'slug from long build name');
eq(flows_slug_from_build_name('build_pismrnul_chatgpt_development_2026-07-29_11-01-52.zip'), 'chatgpt', 'slug from short build name');
eq(flows_slug_from_build_name('random.zip'), null, 'non-build name -> null');

$flowsDir = "$tmpdir/flows";
$r = flows_ingest_upload($zipPath, $flowsDir);
eq($r['ok'], true, 'valid export ingested');
eq($r['slug'], 'facebook', 'slug detected');
eq($r['table_count'], 3, 'table count reported');
check(is_file("$flowsDir/facebook/documentation.txt"), 'documentation installed');
$meta = json_decode((string)file_get_contents("$flowsDir/facebook/build-meta.json"), true);
eq($meta['commit'], '356f6e10dbb38dbf1eb69eea220c68a5c0f47ff3', 'build-meta commit');
eq($meta['build_zip_name'], 'build_znptwlaf_facebook_development_2026-07-29_11-03-36.zip', 'build-meta zip name');
check(isset($meta['uploaded_at']), 'build-meta timestamp present');

// Rejections stay friendly.
$bad = "$tmpdir/notazip.zip"; file_put_contents($bad, 'not a zip at all');
$r = flows_ingest_upload($bad, $flowsDir);
eq($r['ok'], false, 'garbage rejected');
check(!str_contains($r['message'], 'Phar'), 'rejection message plain-language');

$noDoc = "$tmpdir/nodoc.zip";
$phar = new PharData($noDoc);
$phar->addFromString('build_x_y_tiktok_development_2026-01-01_00-00-00.zip', 'x');
unset($phar);
$r = flows_ingest_upload($noDoc, $flowsDir);
eq($r['ok'], false, 'missing documentation rejected');
check(str_contains($r['message'], 'documentation'), 'missing-doc message names the problem');
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement** — append to `src/Flows.php`:

```php
function flows_slug_from_build_name(string $name): ?string {
    $stem = preg_replace('/\.zip$/i', '', basename($name));
    $parts = explode('_', $stem);
    if (count($parts) < 5 || $parts[0] !== 'build') { return null; }
    $slug = $parts[count($parts) - 4];
    return preg_match('/^[a-z0-9]+$/', $slug) ? $slug : null;
}

/** @return array{ok:bool, message:string, slug:?string, table_count:?int} */
function flows_ingest_upload(string $zipPath, string $flowsDir): array {
    $fail = fn(string $msg) => ['ok' => false, 'message' => $msg, 'slug' => null, 'table_count' => null];
    if (!is_file($zipPath)) { return $fail('No file arrived — please choose the zip you downloaded from the flow builder and try again.'); }
    if (filesize($zipPath) > 64 * 1024 * 1024) { return $fail('That file is larger than 64 MB — flow exports are much smaller. Is it the right zip?'); }
    $reading = $zipPath;
    if (!preg_match('/\.zip$/i', $zipPath)) { $reading = $zipPath . '.zip'; if (!@copy($zipPath, $reading)) { return $fail('Could not read the uploaded file — please try again.'); } }
    try { $phar = new PharData($reading); } catch (Throwable) {
        return $fail("That doesn't look like a flow export zip. Upload the zip exactly as downloaded from the flow builder.");
    }
    $entries = []; $n = 0;
    foreach (new RecursiveIteratorIterator($phar) as $file) {
        if (++$n > 64) { return $fail('That zip contains too many files to be a flow export.'); }
        $rel = substr($file->getPathname(), strpos($file->getPathname(), '.zip') + 5);
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) { return $fail('That zip contains unsafe file paths and was not accepted.'); }
        $entries[$rel] = $file->getPathname();
    }
    if (!isset($entries['documentation.txt'])) { return $fail("The zip is missing its documentation file (documentation.txt) — upload the export exactly as downloaded."); }
    $builds = array_values(array_filter(array_keys($entries), fn($e) => preg_match('/^build_.*\.zip$/', $e)));
    if (count($builds) !== 1) { return $fail('The zip should contain exactly one build_… .zip file — upload the export exactly as downloaded.'); }
    $slug = flows_slug_from_build_name($builds[0]);
    if ($slug === null) { return $fail('Could not tell which platform this flow is for — the inner build file has an unexpected name.'); }
    $docText = (string)file_get_contents($entries['documentation.txt']);
    $doc = flows_parse_doc($docText);
    if ($doc === null) { return $fail('The documentation file inside the zip could not be read — upload the export exactly as downloaded.'); }
    $dest = rtrim($flowsDir, '/') . '/' . $slug;
    if (is_dir($dest)) { foreach (glob("$dest/*") ?: [] as $old) { @unlink($old); } }
    elseif (!@mkdir($dest, 0755, true)) { return $fail('Could not save the flow — the storage volume may be full or read-only.'); }
    if (@file_put_contents("$dest/documentation.txt", $docText) === false) { return $fail('Could not save the flow — the storage volume may be full or read-only.'); }
    inst_write_json_atomic("$dest/build-meta.json", [
        'commit' => $doc['commit'], 'build_zip_name' => $builds[0],
        'uploaded_at' => gmdate('c'),
    ]);
    if ($reading !== $zipPath) { @unlink($reading); }
    $count = count($doc['sections']);
    return ['ok' => true, 'slug' => $slug, 'table_count' => $count,
            'message' => $doc['platform_title'] . " — $count data tables ✓"];
}
```

- [ ] **Step 4: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 5: Commit** — `git add src/Flows.php tests/FlowsTest.php && git commit -m "feat(flows): validate and ingest flow export uploads"`

---

### Task 10: CSRF helpers + unconfigured-guard in bootstrap

**Files:**
- Modify: `src/bootstrap.php`, `tests/InstanceTest.php`

**Interfaces:**
- Produces:
  - `csrf_token(): string` — random token persisted in a `ddpi_csrf` cookie (set when absent); in CLI/tests uses `$_COOKIE` directly.
  - `csrf_field(): string` — `<input type="hidden" name="csrf" value="...">`.
  - `csrf_ok(): bool` — POST value matches cookie.
  - `guard_configured(): bool` — true when `inst_configured()`; otherwise emits the redirect (`header('Location: ' . url('setup.php'))`) plus a fallback line `<p>This inspector is not set up yet — <a href="...">set it up</a>.</p>` and returns false. Pages call `if (!guard_configured()) { return; }`.

- [ ] **Step 1: Write failing tests** — append to `tests/InstanceTest.php` (before the config restore):

```php
$_COOKIE['ddpi_csrf'] = '';
$tok = csrf_token();
check(strlen($tok) >= 32, 'csrf token generated');
eq(csrf_token(), $tok, 'csrf token stable within request');
$_POST['csrf'] = $tok;
eq(csrf_ok(), true, 'matching token accepted');
$_POST['csrf'] = 'wrong';
eq(csrf_ok(), false, 'wrong token rejected');
check(str_contains(csrf_field(), $tok), 'csrf field embeds token');
$_POST = [];

// guard: unconfigured storage mode -> setup pointer, no crash
inst_save(['study_name' => '', 'source_mode' => 'local', 'local_path' => null, 'cadence' => 'off', 'default_n' => 15]);
@unlink("$scratch/config/instance.json");
ob_start(); $ok = guard_configured(); $out = ob_get_clean();
eq($ok, false, 'guard blocks unconfigured instance');
check(str_contains($out, 'setup.php'), 'guard points at setup');
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement** — append to `src/bootstrap.php`:

```php
function csrf_token(): string {
    if (!isset($_COOKIE['ddpi_csrf']) || strlen((string)$_COOKIE['ddpi_csrf']) < 32) {
        $_COOKIE['ddpi_csrf'] = bin2hex(random_bytes(16));
        if (!headers_sent()) { setcookie('ddpi_csrf', $_COOKIE['ddpi_csrf'], ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']); }
    }
    return (string)$_COOKIE['ddpi_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(): bool { return isset($_POST['csrf']) && hash_equals(csrf_token(), (string)$_POST['csrf']); }

function guard_configured(): bool {
    if (inst_configured()) { return true; }
    if (!headers_sent()) { header('Location: ' . url('setup.php')); }
    echo '<!doctype html><meta charset="utf-8"><p>This inspector is not set up yet — <a href="'
        . h(url('setup.php')) . '">set it up</a>.</p>';
    return false;
}
```

- [ ] **Step 4: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 5: Commit** — `git add src/bootstrap.php tests/InstanceTest.php && git commit -m "feat(bootstrap): csrf helpers and unconfigured-instance guard"`

---

### Task 11: Setup wizard — `public/setup.php` + POST handlers

**Files:**
- Create: `public/setup.php`, `tests/SetupTest.php`
- Modify: `src/Instance.php` (one handler function), `tests/run.php` (register SetupTest)

**Interfaces:**
- Produces: `inst_handle_setup_post(array $post, array $files): array{flash:list<array{kind:string, text:string}>}` in `src/Instance.php` — dispatch on `$post['action']`:
  - `upload_flow`: requires csrf; `$files['flow_zip']['tmp_name']` → `flows_ingest_upload(..., inst_paths()['flows'])`; flash ok/error with its message.
  - `save_source`: requires csrf; `$post['source_mode']` ∈ yoda|rd-link|local; builds+saves `instance.json` (`study_name`, `source_mode`, `local_path` (local only), `cadence` = `'daily'` if `$post['cadence']==='daily'` else `'off'`, `default_n` = existing or `cfg('default_n', 15)`) and, for yoda/rd-link, `source.json` per the pinned contract (yoda: collection + ticket from `$post['collection']`/`$post['access_code']`, host `fsw.data.uu.nl`, zone `nluu10p` unless `$post['host']`/`$post['zone']` non-empty; rd-link: derive `share_token` = last path segment of the pasted share link `$post['share_link']`, `webdav_url` in the **modern Nextcloud form** = scheme + host + `'/public.php/dav/files/' . rawurlencode($token) . '/'` (UU's uu.data.surf.nl serves only this form; the Task 7 probe falls back to the legacy `/public.php/webdav/` form and rewrites source.json if only that works), `password` from `$post['link_password']`). Empty ticket/password/link → friendly flash error, nothing saved.
  - `check_fetch`: requires csrf; run `inst_probe()`; on ok also `inst_touch_refresh()`; flash the probe message (+ "Fetching your donations now — this page shows progress." when refresh started).
  - `refresh_now`: requires csrf; `inst_touch_refresh()`; flash "Checking for new donations…".
  - Bad/missing csrf → single flash error "That form has expired — please try again."
- `public/setup.php` renders: flash list; Step 1 flows (list of `flows_load_all()` with per-platform "TITLE — N data tables ✓" + build-meta upload date + upload form); Step 2 source form (three radio modes with plain-language labels per spec §6, inline 3-step RD share-link instructions, saved state shown as "saved ✓" — ticket/password inputs always rendered EMPTY); Step 3 Check & fetch button + current `inst_status()` rendering + `<details><summary>Technical log</summary><pre>inst_log_tail()</pre></details>`. In legacy mode (no storage_root) it renders a notice "This instance is configured by files on disk (developer mode)." and no forms.

- [ ] **Step 1: Write failing tests** — `tests/SetupTest.php` (register after PagesTest in `tests/run.php`; reuse `render_page()` — it is defined in PagesTest which runs earlier):

```php
<?php
// Storage-mode scratch instance for wizard tests.
$scratch2 = sys_get_temp_dir() . '/ddp-inspector-setup-' . getmypid();
exec('rm -rf ' . escapeshellarg($scratch2));
$GLOBALS['__cfg_saved_setup'] = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $scratch2, 'default_n' => 15, 'base_path' => ''];

// Handlers directly (upload simulated by pre-placed file).
$_COOKIE['ddpi_csrf'] = str_repeat('a', 32);
$zip = $scratch2 . '-flow.zip';
$phar = new PharData($zip);
$phar->addFromString('documentation.txt', (string)file_get_contents(__DIR__ . '/fixtures/flows/tiktok/documentation.txt'));
$phar->addFromString('build_x_y_tiktok_development_2026-01-01_00-00-00.zip', 'x');
unset($phar);
$r = inst_handle_setup_post(['action' => 'upload_flow', 'csrf' => csrf_token()],
                            ['flow_zip' => ['tmp_name' => $zip, 'error' => 0]]);
eq($r['flash'][0]['kind'], 'ok', 'flow upload accepted');
check(str_contains($r['flash'][0]['text'], 'TikTok'), 'upload confirmation names platform');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'yoda', 'study_name' => 'Crime study',
    'collection' => '/nluu10p/home/research-x', 'access_code' => 'TICKET123'], []);
eq($r['flash'][0]['kind'], 'ok', 'yoda source saved');
eq(inst_load()['source_mode'], 'yoda', 'instance saved');
eq(inst_source_load()['ticket'], 'TICKET123', 'ticket stored');

$r = inst_handle_setup_post(['action' => 'save_source', 'csrf' => csrf_token(),
    'source_mode' => 'rd-link', 'study_name' => 'RD study',
    'share_link' => 'https://researchdrive.surf.nl/index.php/s/AbCdEf123', 'link_password' => 'pw'], []);
eq(inst_source_load()['share_token'], 'AbCdEf123', 'share token from link');
eq(inst_source_load()['webdav_url'], 'https://researchdrive.surf.nl/public.php/dav/files/AbCdEf123/', 'webdav url derived (modern form)');

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => csrf_token()], []);
check(is_file("$scratch2/state/refresh-requested"), 'refresh flag touched by handler');

$r = inst_handle_setup_post(['action' => 'refresh_now', 'csrf' => 'nope'], []);
eq($r['flash'][0]['kind'], 'error', 'bad csrf rejected');

// Page renders: no secret echo, flows listed, status shown.
inst_write_json_atomic("$scratch2/state/refresh-status.json",
    ['phase' => 'error', 'started_at' => null, 'finished_at' => null, 'donations' => null,
     'message' => 'Your access code has expired — ask your data manager for a new one.']);
$_POST = [];
$html = render_page('setup.php', []);
check(!str_contains($html, 'TICKET123'), 'ticket never echoed');
check(!str_contains($html, '>pw<'), 'password never echoed');
check(str_contains($html, 'TikTok'), 'uploaded flow listed');
check(str_contains($html, 'access code has expired'), 'status message rendered');
check(str_contains($html, 'Technical log'), 'log details present');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_setup'];
```

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Implement handler** — append `inst_handle_setup_post` to `src/Instance.php`:

```php
/** @return array{flash: list<array{kind:string, text:string}>} */
function inst_handle_setup_post(array $post, array $files): array {
    $flash = fn(string $kind, string $text) => ['flash' => [['kind' => $kind, 'text' => $text]]];
    $action = (string)($post['action'] ?? '');
    if (!isset($post['csrf']) || !hash_equals(csrf_token(), (string)$post['csrf'])) {
        return $flash('error', 'That form has expired — please go back and try again.');
    }
    if ($action === 'upload_flow') {
        $f = $files['flow_zip'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== 0 || !is_string($f['tmp_name'] ?? null)) {
            return $flash('error', 'The upload did not arrive — please choose the zip and try again.');
        }
        $flows = inst_paths()['flows'];
        if ($flows === null) { return $flash('error', 'This instance has no storage volume configured — contact whoever set up this workspace.'); }
        $r = flows_ingest_upload($f['tmp_name'], $flows);
        return $flash($r['ok'] ? 'ok' : 'error', $r['message']);
    }
    if ($action === 'save_source') {
        $mode = (string)($post['source_mode'] ?? '');
        if (!in_array($mode, ['yoda', 'rd-link', 'local'], true)) { return $flash('error', 'Please choose where your donations are stored.'); }
        $inst = inst_load() ?? [];
        $instance = [
            'study_name'  => trim((string)($post['study_name'] ?? ($inst['study_name'] ?? ''))),
            'source_mode' => $mode,
            'local_path'  => $mode === 'local' ? trim((string)($post['local_path'] ?? '')) : null,
            'cadence'     => (($post['cadence'] ?? '') === 'daily') ? 'daily' : 'off',
            'default_n'   => (int)($inst['default_n'] ?? cfg('default_n', 15)),
        ];
        if (isset($inst['analysis_dirs'])) { $instance['analysis_dirs'] = $inst['analysis_dirs']; }
        if ($mode === 'yoda') {
            $collection = trim((string)($post['collection'] ?? ''));
            $ticket = trim((string)($post['access_code'] ?? ''));
            if ($collection === '' || $ticket === '') { return $flash('error', 'Please fill in both the folder path and the access code your data manager gave you.'); }
            if (!inst_source_save(['mode' => 'yoda', 'collection' => $collection,
                    'host' => trim((string)($post['host'] ?? '')) ?: 'fsw.data.uu.nl',
                    'zone' => trim((string)($post['zone'] ?? '')) ?: 'nluu10p', 'ticket' => $ticket])) {
                return $flash('error', 'Could not save — the storage volume may be full or read-only.');
            }
        } elseif ($mode === 'rd-link') {
            $link = trim((string)($post['share_link'] ?? ''));
            $pw = (string)($post['link_password'] ?? '');
            $parts = parse_url($link);
            $token = ($parts !== false && isset($parts['path'])) ? basename(rtrim($parts['path'], '/')) : '';
            if ($link === '' || $pw === '' || $token === '' || !isset($parts['scheme'], $parts['host'])) {
                return $flash('error', 'Please paste the full share link and its password.');
            }
            if (!inst_source_save(['mode' => 'rd-link',
                    'webdav_url' => $parts['scheme'] . '://' . $parts['host'] . '/public.php/dav/files/' . rawurlencode($token) . '/',
                    'share_token' => $token, 'password' => $pw])) {
                return $flash('error', 'Could not save — the storage volume may be full or read-only.');
            }
        } else {
            if ($instance['local_path'] === '') { return $flash('error', 'Please fill in the folder path.'); }
        }
        if (!inst_save($instance)) { return $flash('error', 'Could not save — the storage volume may be full or read-only.'); }
        return $flash('ok', 'Saved ✓ — now run "Check & fetch" below.');
    }
    if ($action === 'check_fetch') {
        $probe = inst_probe();
        if (!$probe['ok']) { return $flash('error', $probe['message']); }
        inst_touch_refresh();
        return $flash('ok', $probe['message'] . ' Fetching your donations now — refresh this page to see progress.');
    }
    if ($action === 'refresh_now') {
        inst_touch_refresh();
        return $flash('ok', 'Checking for new donations — refresh this page in a minute.');
    }
    return $flash('error', 'Unknown action.');
}
```

- [ ] **Step 4: Implement page** — `public/setup.php`:

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';

$flash = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && inst_root() !== null) {
    $flash = inst_handle_setup_post($_POST, $_FILES)['flash'];
}
$storageMode = inst_root() !== null;
$flows = $storageMode ? flows_load_all() : [];
$inst = $storageMode ? inst_load() : null;
$status = inst_status();
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — set up</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← donations</a></p>
  <h1>Set up this inspector</h1>
  <?php foreach ($flash as $f): ?>
    <p class="<?= $f['kind'] === 'ok' ? 'notice' : 'skipped' ?>"><?= h($f['text']) ?></p>
  <?php endforeach; ?>
  <?php if (!$storageMode): ?>
    <p class="notice">This instance is configured by files on disk (developer mode).</p>
  <?php else: ?>

  <h2>1 · Add your study's donation flow(s)</h2>
  <p>Upload the same zip file(s) you downloaded from the flow builder — one per platform.</p>
  <?php foreach ($flows as $slug => $doc): ?>
    <p class="notice"><?= h($doc['platform_title']) ?> — <?= count($doc['sections']) ?> data tables ✓</p>
  <?php endforeach; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload_flow">
    <input type="file" name="flow_zip" accept=".zip" required>
    <button>Upload flow</button>
  </form>

  <h2>2 · Where are your donations stored?</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_source">
    <p><label>Study name <input name="study_name" value="<?= h((string)($inst['study_name'] ?? '')) ?>"></label></p>
    <p><label><input type="radio" name="source_mode" value="rd-link" <?= ($inst['source_mode'] ?? '') === 'rd-link' ? 'checked' : '' ?>>
      SURF Research Drive</label><br>
      In Research Drive: ① right-click the folder with donations and choose “Share link”,
      ② set it to <em>read only</em> and add a password, ③ paste the link and password here.<br>
      <label>Share link <input name="share_link" placeholder="https://researchdrive…/s/…"></label>
      <label>Password <input type="password" name="link_password" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label></p>
    <p><label><input type="radio" name="source_mode" value="yoda" <?= ($inst['source_mode'] ?? '') === 'yoda' ? 'checked' : '' ?>>
      My data manager gave me an access code</label><br>
      <label>Folder path <input name="collection" value="<?= h((string)(inst_source_load()['collection'] ?? '')) ?>"></label>
      <label>Access code <input type="password" name="access_code" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label></p>
    <p><label><input type="radio" name="source_mode" value="local" <?= ($inst['source_mode'] ?? '') === 'local' ? 'checked' : '' ?>>
      Advanced: a folder on this workspace</label><br>
      <label>Folder <input name="local_path" value="<?= h((string)($inst['local_path'] ?? '')) ?>"></label></p>
    <p><label><input type="checkbox" name="cadence" value="daily" <?= ($inst['cadence'] ?? '') === 'daily' ? 'checked' : '' ?>>
      Check for new donations automatically every day</label></p>
    <button>Save</button>
  </form>

  <h2>3 · Check &amp; fetch</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="check_fetch">
    <button>Check connection and fetch donations</button>
  </form>
  <?php if ($status['phase'] !== 'idle'): ?>
    <p class="<?= $status['phase'] === 'error' ? 'skipped' : 'notice' ?>">
      Status: <?= h($status['phase']) ?><?= $status['message'] !== '' ? ' — ' . h($status['message']) : '' ?>
      <?php if ($status['donations'] !== null): ?> · <?= (int)$status['donations'] ?> donations<?php endif; ?></p>
  <?php endif; ?>
  <details><summary>Technical log</summary><pre><?= h(inst_log_tail()) ?></pre></details>
  <?php endif; ?>
</main>
```

Note: the yoda ticket and RD password inputs are rendered with empty `value` always (only a `saved ✓` placeholder) — secrets never round-trip into HTML. The collection path is not a secret and may round-trip.

- [ ] **Step 5: Run to verify pass** — `php tests/run.php`.
- [ ] **Step 6: Commit** — `git add public/setup.php src/Instance.php tests/ && git commit -m "feat(setup): researcher-facing setup wizard with flows, source, and fetch"`

---

### Task 12: Generalize the three pages

**Files:**
- Modify: `public/index.php`, `public/participant.php`, `public/transcript.php`, `tests/PagesTest.php`

**Interfaces:**
- Consumes: `ddp_load_dir` (Task 3 shape), `stats_platform_scope`/`stats_row_date` (Task 4), `flows_load_all`/`flows_match`/`flows_table_order`/`flows_prettify` (Task 8), `analysis_row_links`/`analysis_transcript_paths` (Task 5), `guard_configured`/`csrf_field` (Task 10), `inst_status`/`inst_donation_count`/`inst_touch_refresh` (Task 7).
- Produces: no new functions — page markup only. Key markup contracts for tests: index shows `participant.php?id=`, platform names, "Last updated"/donation count line when status has `finished_at`, "Check for new donations" button (storage mode only), friendly empty state; participant shows per-platform `<h2>`, per-table sections in `flows_table_order`, generic `<th>` columns, "participant removed N rows before donating" when `deleted > 0`, superseded-file note, analysis links; transcript unchanged behavior but paths via `analysis_transcript_paths`.

- [ ] **Step 1: Update `tests/PagesTest.php`** — keep every existing check that still applies; replace/extend:

```php
$html = render_page('index.php', []);
check(str_contains($html, 'p1'), 'index lists participant p1');
check(str_contains($html, 'participant.php?id=p1'), 'index links to participant page');
check(str_contains($html, 'tiktok'), 'index shows platform');
check(str_contains($html, 'skipped'), 'index shows skipped-file notice');
check(!str_contains($html, 'participant.php?id=preview'), 'index does not list skipped preview participant');

$html = render_page('participant.php', ['id' => 'p1', 'seed' => '1', 'n' => '1']);
check(str_contains($html, 'tiktok_watch_history') || str_contains($html, 'Tiktok watch history'), 'participant shows watch history table');
check(str_contains($html, 'Igual estoy yo') || str_contains($html, 'second comment'), 'comment text rendered generically');
check(str_contains($html, 'transcript.php?vid=7654562293757250829'), 'video row links to transcript via analysis module');
check(str_contains($html, 'seed=2'), 'reshuffle link bumps seed');
check(str_contains($html, '<h2>') && str_contains(strtolower($html), 'tiktok'), 'platform heading present');

$missing = render_page('participant.php', ['id' => 'nope']);
check(str_contains($missing, 'not found') || str_contains($missing, '404'), 'unknown participant -> not found');
```

(transcript.php checks unchanged; config-missing block: replace with a guard check — with `__cfg = null`, `render_page('index.php')` must contain `'not set up yet'` or `'Configuration missing'` and no `<table`).

- [ ] **Step 2: Run to verify failure** — `php tests/run.php`.

- [ ] **Step 3: Rewrite `public/index.php`:**

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$loaded = ddp_load_dir((string)inst_effective_ddp_dir());
$status = inst_status();
$storageMode = inst_root() !== null;
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — participants</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>DDP Inspector</h1>
  <?php if ($storageMode): ?>
    <p class="meta">
      <?php if (!empty($status['finished_at'])): ?>Last updated <?= h((string)$status['finished_at']) ?> ·<?php endif; ?>
      <?= inst_donation_count() ?> donation file(s)
      <?php if ($status['phase'] === 'error'): ?><span class="skipped"><?= h($status['message']) ?></span><?php endif; ?>
    </p>
    <form method="post" action="<?= h(url('setup.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="refresh_now">
      <button>Check for new donations</button>
    </form>
    <p class="meta"><a href="<?= h(url('setup.php')) ?>">Settings</a></p>
  <?php endif; ?>
  <?php if ($loaded['skipped']): ?>
    <p class="skipped">⚠ <?= count($loaded['skipped']) ?> file(s) skipped (non-conforming):
      <?= h(implode(', ', array_column($loaded['skipped'], 'path'))) ?></p>
  <?php endif; ?>
  <?php if (!$loaded['participants']): ?>
    <p class="notice">No donations yet. Once participants donate (and a fetch has run), they appear here.</p>
  <?php else: ?>
  <table class="scope">
    <thead><tr><th>participant</th><th>platforms</th><th>total rows</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($loaded['participants'] as $p):
        $total = 0; $earliest = null; $latest = null; $plats = [];
        foreach ($p['platforms'] as $slug => $entry) {
            $scope = stats_platform_scope($entry['tables'], []);
            $plats[] = $slug;
            $total += $scope['total_rows'];
            if ($scope['earliest'] !== null && ($earliest === null || $scope['earliest'] < $earliest)) { $earliest = $scope['earliest']; }
            if ($scope['latest'] !== null && ($latest === null || $scope['latest'] > $latest)) { $latest = $scope['latest']; }
        } ?>
      <tr>
        <td><a href="<?= h(url('participant.php?id=' . rawurlencode($p['id']))) ?>"><?= h($p['id']) ?></a></td>
        <td><?= h(implode(', ', $plats)) ?></td>
        <td class="num"><?= number_format($total) ?></td>
        <td><?= h(fmt_ts($earliest)) ?></td>
        <td><?= h(fmt_ts($latest)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>
```

- [ ] **Step 4: Rewrite `public/participant.php`:**

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$id   = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$seed = max(1, (int)(is_scalar($_GET['seed'] ?? null) ? $_GET['seed'] : 1));
$n    = max(1, (int)(is_scalar($_GET['n'] ?? null) ? $_GET['n'] : cfg('default_n', 15)));

$loaded = ddp_load_dir((string)inst_effective_ddp_dir());
$participant = $loaded['participants'][$id] ?? null;
if ($participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$docs = flows_load_all();
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← all participants</a></p>
  <h1>participant <?= h($id) ?></h1>
  <p class="samplesize">sample size:
    <?php foreach ([10, 15, 20, 50] as $opt): ?>
      <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $opt . '&seed=' . $seed)) ?>"<?= $opt === $n ? ' class="cur"' : '' ?>><?= $opt ?></a>
    <?php endforeach; ?>
  </p>

  <?php foreach ($participant['platforms'] as $slug => $entry):
      $doc = $docs[$slug] ?? null;
      $match = flows_match($entry['tables'], $doc);
      $order = flows_table_order($match);
      $scope = stats_platform_scope($entry['tables'], $order); ?>
    <h2><?= h($doc['platform_title'] ?? ucfirst($slug)) ?></h2>
    <?php if ($entry['superseded']): ?>
      <p class="skipped">Note: this participant donated more than once for this platform;
        showing the most recent donation (<?= count($entry['superseded']) ?> older file(s) ignored).</p>
    <?php endif; ?>
    <table class="scope">
      <thead><tr><th>table</th><th>rows</th><th>earliest</th><th>latest</th></tr></thead>
      <tbody>
      <?php foreach ($scope['tables'] as $name => $s):
          $title = ($match[$name] ?? null) !== null ? $doc['sections'][$match[$name]]['title'] : flows_prettify($name); ?>
        <tr><td><?= h($title) ?></td><td class="num"><?= number_format($s['count']) ?></td>
            <td><?= h(fmt_ts($s['earliest'])) ?></td><td><?= h(fmt_ts($s['latest'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php foreach ($order as $name):
        $rows = $entry['tables'][$name] ?? [];
        if (!$rows) { continue; }
        $secIdx = $match[$name] ?? null;
        $title = $secIdx !== null ? $doc['sections'][$secIdx]['title'] : flows_prettify($name);
        $desc = $secIdx !== null ? $doc['sections'][$secIdx]['description'] : '';
        $cols = $secIdx !== null ? $doc['sections'][$secIdx]['vars'] : array_keys($rows[0]);
        $sample = sample_rows($rows, $n, $seed, $name);
        $del = (int)($entry['deleted'][$name] ?? 0);
        $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1)); ?>
      <section>
        <h3><?= h($title) ?> <span class="count"><?= number_format(count($rows)) ?> rows</span>
          <?php if (count($rows) > count($sample)): ?><a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a><?php endif; ?>
        </h3>
        <?php if ($desc !== ''): ?><p class="meta"><?= h($desc) ?></p><?php endif; ?>
        <?php if ($del > 0): ?><p class="meta">Participant removed <?= $del ?> row(s) before donating.</p><?php endif; ?>
        <table class="rows">
          <thead><tr><?php foreach ($cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($sample as $row): ?>
            <tr>
              <?php foreach ($cols as $c): ?><td><?= h(is_scalar($row[$c] ?? null) ? (string)$row[$c] : '') ?></td><?php endforeach; ?>
              <td><?php foreach (analysis_row_links($slug, $row) as $link): ?>
                    <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
                  <?php endforeach; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>
</main>
```

- [ ] **Step 5: Update `public/transcript.php`** — only the path-resolution block changes:

```php
$paths = analysis_transcript_paths($vid);
$txt = $paths['txt'] !== null ? file_get_contents($paths['txt']) : null;
$meta = $paths['json'] !== null ? json_decode((string)file_get_contents($paths['json']), true) : null;
```

replacing the `cfg('transcripts_dir')` block; add `if (!guard_configured()) { return; }` after the `cfg_ready()` check; everything else (vid validation, `$seg_info`, rendering) unchanged.

- [ ] **Step 6: Run to verify pass** — `php tests/run.php` — full suite green. Also remove the deprecated `stats_participant_scope` wrapper (Task 4 note) now that no page calls it, and re-run.
- [ ] **Step 7: Manual smoke** — `DDP_INSPECTOR_CONFIG=examples/config.examples.php ./run-dev.sh` → browse `http://127.0.0.1:8110/`: participant list renders with `tiktok` platform, participant page shows five tables with the same row counts as before this plan (synthetic ground-truth: watch 400, favorites 12, likes 80, shares 5, comments 8 per `examples/generate.py`), transcript links work.
- [ ] **Step 8: Commit** — `git add public/ tests/PagesTest.php src/Stats.php && git commit -m "feat(pages): generic per-platform rendering with flow-doc enrichment"`

---

### Task 13: Ground-truth guard, config example, README, final sweep

**Files:**
- Modify: `tests/PagesTest.php` (ground-truth block), `config.php.example`, `README.md`

**Interfaces:**
- Consumes: everything above. Produces: none (documentation + regression lock).

- [ ] **Step 1: Add ground-truth regression test** — append to `tests/PagesTest.php`:

```php
// §7 ground-truth guard: the generic path must reproduce exact per-table row
// counts. Uses the synthetic examples/ dataset when present (regenerable via
// examples/generate.py; counts fixed by its seed).
$examples = __DIR__ . '/../examples/ddp';
if (is_dir($examples)) {
    $ex = ddp_load_dir($examples);
    $one = $ex['participants']['1bf78505c54e3c4ce201abd7']['platforms']['tiktok']['tables'] ?? null;
    check($one !== null, 'examples participant loads under tiktok platform');
    if ($one !== null) {
        eq(count($one['tiktok_watch_history']), 400, 'ground truth: watch rows');
        eq(count($one['tiktok_favorite_videos']), 12, 'ground truth: favorites rows');
        eq(count($one['tiktok_like_list']), 80, 'ground truth: likes rows');
        eq(count($one['tiktok_share_history']), 5, 'ground truth: shares rows');
        eq(count($one['tiktok_comments']), 8, 'ground truth: comments rows');
    }
}
```

- [ ] **Step 2: Run** — `php tests/run.php` — green (verify the counts against `examples/generate.py` output if the fixture participant id differs; adjust the id, never the mechanism).

- [ ] **Step 3: Update `config.php.example`:**

```php
<?php
return [
    // Provisioned mode: the inspector's namespace dir on the storage volume
    // (e.g. '/data/<volume>/ddp-inspector'). When set, all data/config/state
    // live under it and the setup wizard manages the study. null for dev mode.
    'storage_root'    => null,
    // Dev mode only (ignored when storage_root is set): donation JSON dir.
    'ddp_dir'         => '/absolute/path/to/ddp/files',
    // Dev mode only: sharded transcript tree (<last-2>/<id>.txt|.json), or null.
    'transcripts_dir' => null,
    // Default sample size per table.
    'default_n'       => 15,
    // URL prefix behind a reverse-proxy subpath (e.g. '/inspector'); '' at root.
    'base_path'       => '',
    // Binaries used by the setup wizard's connection test (PATH lookup).
    'gocmd_bin'       => 'gocmd',
    'rclone_bin'      => 'rclone',
];
```

- [ ] **Step 4: README** — update the feature list: multi-platform donations (participant → platform → tables), flow-export-driven table titles/descriptions, setup wizard at `/setup.php` (storage mode), analyses seam with transcripts module, dev mode unchanged (`./run-dev.sh` + `examples/`). Keep the PHP ≥ 8.1 requirement line.
- [ ] **Step 5: Full suite + smoke** — `php tests/run.php`; `DDP_INSPECTOR_CONFIG=examples/config.examples.php ./run-dev.sh` renders as in Task 12 Step 7.
- [ ] **Step 6: Commit** — `git add tests/PagesTest.php config.php.example README.md && git commit -m "docs+test: ground-truth guard, config example, README for generalized app"`

---

## Self-review (performed)

1. **Spec coverage:** §6 wizard (Task 11), redirect-when-unconfigured (Tasks 10+12), refresh controls (Tasks 7, 11, 12), §7 generic model (Tasks 1–4), doc enrichment + matching (Task 8), upload (Task 9), analyses seam + transcripts (Tasks 5, 12), duplicate rule + deleted counts (Tasks 2–3), RD endpoint-form handling (modern-form derivation Task 11; legacy fallback + source.json rewrite Task 7 — uu.data.surf.nl verified modern-only 2026-07-29), error posture (friendly messages throughout; no-500 paths in pages), §8 app tests (every task), ground truth (Task 13). Not covered by design: real gocmd/rclone integration runs (Plan 2's hermetic harness + live ladder); the live §7 counts (live validation, spec §8).
2. **Placeholder scan:** none; the one deliberate verification note (gocmd config field names, Task 7) is confined to a single function with instructions to verify against the transcribe repo during Plan 2 wiring.
3. **Type consistency:** loader shape (`platforms`→`tables`/`deleted`/`superseded`/`key_millis`/`file`) consistent across Tasks 3, 4, 12, 13; `flows_match` returns name→`?int` consumed by `flows_table_order`/participant.php; `inst_paths()` keys used identically in Tasks 6–11; probe/handler return shapes match their consumers in setup.php.

**Deferred consciously:** per-table pagination/virtual scrolling (perf envelope already proven at 90k rows), CSRF beyond double-submit cookie, upload of multiple zips in one request (one at a time is fine), wordcloud-style visualizations mentioned in flow docs (out of scope — inspector renders tables only).
