# DDP Inspector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a read-only, per-participant PHP viewer that renders a faithful view of raw TikTok DDP extracts so a researcher can verify pilot donations against expectations.

**Architecture:** Vanilla PHP (stdlib only, no Composer/framework), server-rendered pages. Pure logic (parse / stats / sample) lives in `src/` and is unit-tested in isolation; thin pages in `public/` render HTML and are tested in-process via output buffering. Deployed locally via `php -S` and, later, behind the SURF Research Cloud nginx + SRAM component.

**Tech Stack:** PHP 8+ (CLI built-in server for dev; php-fpm behind nginx for deployment), plain HTML/CSS, no client-side framework, a zero-dependency PHP assert harness for tests.

## Global Constraints

- **No external dependencies.** Stdlib PHP only — no Composer, no framework, no phpunit. (`json_decode`, `glob`, `preg_match`, `htmlspecialchars`.)
- **Read-only.** The tool never writes to the DDP data or transcripts. Only permitted writes are an optional stats cache under the tool's own dir (not built in this plan).
- **Reverse-proxy safe:** every internal link, form action, and asset path is relative, built via the `url()` helper from `base_path`. No absolute URLs.
- **Escape all output** with `htmlspecialchars` via the `h()` helper. Never echo raw DDP content.
- **Pages `return` on error paths, never `exit`** — so they remain `include`-able by the test runner.
- **No real donor data in the repo or tests.** Fixtures are synthetic.
- **Section names (canonical order):** `tiktok_watch_history`, `tiktok_favorite_videos`, `tiktok_like_list`, `tiktok_share_history`, `tiktok_comments`.
- **Video sections (for unique-video counting):** `tiktok_watch_history`, `tiktok_favorite_videos`, `tiktok_like_list` only (shares excluded, per spec).
- **Canonical video ID:** the 19-digit id from `https://(www.)?(tiktokv|tiktok).com/(share/video|@user/video)/{19 digits}`.
- **Date formats:** `Y-m-d H:i:s` and `Y-m-d H:i:s UTC` (comments use the UTC suffix; other sections do not).
- **Transcript sharding:** `<last-two-digits-of-id>/<id>.txt` + `.json` (ADR-0004 style).
- **Run tests with:** `php tests/run.php` (exit 0 = all pass).

---

### Task 1: Test harness + DDP parsing (`src/Ddp.php`)

**Files:**
- Create: `src/Ddp.php`
- Create: `tests/run.php`
- Create: `tests/DdpTest.php`
- Create: `tests/fixtures/ddp/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json`
- Create: `tests/fixtures/ddp/assignment=1_task=1_participant=preview_source=tiktok_key=2-tiktok.json`

**Interfaces:**
- Produces:
  - `const DDP_SECTION_ORDER = ['tiktok_watch_history','tiktok_favorite_videos','tiktok_like_list','tiktok_share_history','tiktok_comments'];`
  - `ddp_participant_id_from_filename(string $path): string` — the `participant=` segment, else the filename stem.
  - `ddp_parse_file(string $path): ?array` — `section_name => list<assoc row>` merged across the file's array elements; `null` if the file is not a top-level JSON array.
  - `ddp_load_dir(string $dir): array` — `['participants' => [id => ['id'=>string,'files'=>string[],'sections'=>array]], 'skipped' => [['path'=>string,'reason'=>string]]]`, participants sorted by id, sections merged across files.
  - Test helpers `check(bool,$msg)`, `eq($got,$want,$msg)` (from `tests/run.php`).

- [ ] **Step 1: Create the two fixtures**

`tests/fixtures/ddp/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json`:
```json
[
  {"deleted row count": 0, "tiktok_watch_history": [
    {"Date": "2026-07-05 11:53:55", "Link": "https://www.tiktokv.com/share/video/7654562293757250829/"},
    {"Date": "2026-06-30 09:12:00", "Link": "https://www.tiktokv.com/share/video/7640857640611925279/"},
    {"Date": "2026-01-30 23:24:12", "Link": "https://www.tiktokv.com/share/video/7588657359338196254/"}
  ]},
  {"deleted row count": 0, "tiktok_favorite_videos": [
    {"Date": "2026-07-05 11:41:49", "Link": "https://www.tiktokv.com/share/video/7658513515249929480/"}
  ]},
  {"deleted row count": 0, "tiktok_like_list": [
    {"Date": "2026-06-27 19:16:39", "Link": "https://www.tiktokv.com/share/video/7654562293757250829/"}
  ]},
  {"deleted row count": 0, "tiktok_share_history": [
    {"Date": "2026-01-07 00:28:13", "SharedContent": "share_video", "Link": "https://www.tiktokv.com/share/video/7588657359338196254/", "Method": "sms"}
  ]},
  {"deleted row count": 0, "tiktok_comments": [
    {"Date": "2026-05-28 01:36:28 UTC", "Comment": "Igual estoy yo"},
    {"Date": "2026-05-29 02:00:00 UTC", "Comment": "second comment"}
  ]}
]
```

`tests/fixtures/ddp/assignment=1_task=1_participant=preview_source=tiktok_key=2-tiktok.json`:
```json
{"status": "data_submission declined"}
```

- [ ] **Step 2: Write the test runner harness**

`tests/run.php`:
```php
<?php
$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];
function check(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['__t']['pass']++; return; }
    $GLOBALS['__t']['fail']++;
    fwrite(STDERR, "FAIL: $msg\n");
}
function eq($got, $want, string $msg): void {
    check($got === $want, "$msg (got " . var_export($got, true) . ", want " . var_export($want, true) . ")");
}
foreach (['DdpTest.php', 'StatsTest.php', 'SampleTest.php', 'PagesTest.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p)) { require $p; }
}
$t = $GLOBALS['__t'];
echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
exit($t['fail'] === 0 ? 0 : 1);
```

- [ ] **Step 3: Write the failing test**

`tests/DdpTest.php`:
```php
<?php
require_once __DIR__ . '/../src/Ddp.php';

$dir = __DIR__ . '/fixtures/ddp';
$p1  = $dir . '/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json';

eq(ddp_participant_id_from_filename($p1), 'p1', 'participant id from filename');
eq(ddp_participant_id_from_filename('/x/no-segment.json'), 'no-segment', 'participant id falls back to stem');

$sections = ddp_parse_file($p1);
eq(is_array($sections), true, 'parse returns array for conforming file');
eq(count($sections['tiktok_watch_history']), 3, 'watch history rows');
eq($sections['tiktok_watch_history'][0]['Link'], 'https://www.tiktokv.com/share/video/7654562293757250829/', 'watch link');
eq(isset($sections['deleted row count']), false, 'non-array keys ignored');

eq(ddp_parse_file($dir . '/assignment=1_task=1_participant=preview_source=tiktok_key=2-tiktok.json'), null, 'preview stub is skipped (null)');

$loaded = ddp_load_dir($dir);
eq(array_keys($loaded['participants']), ['p1'], 'only conforming participant loaded');
eq(count($loaded['skipped']), 1, 'one skipped file reported');
eq($loaded['participants']['p1']['sections']['tiktok_comments'][1]['Comment'], 'second comment', 'comments merged in order');
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `require` of `src/Ddp.php` errors (file does not exist) / undefined function.

- [ ] **Step 5: Implement `src/Ddp.php`**

```php
<?php
const DDP_SECTION_ORDER = [
    'tiktok_watch_history',
    'tiktok_favorite_videos',
    'tiktok_like_list',
    'tiktok_share_history',
    'tiktok_comments',
];

function ddp_participant_id_from_filename(string $path): string {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    foreach (explode('_', $stem) as $seg) {
        if (str_starts_with($seg, 'participant=')) {
            return substr($seg, strlen('participant='));
        }
    }
    return $stem;
}

/** @return array<string,list<array>>|null */
function ddp_parse_file(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_is_list($data)) { return null; }
    $out = [];
    foreach ($data as $element) {
        if (!is_array($element)) { continue; }
        foreach ($element as $key => $value) {
            if (!is_array($value)) { continue; } // skips "deleted row count" and scalars
            $out[$key] = array_merge($out[$key] ?? [], array_values($value));
        }
    }
    return $out;
}

function ddp_load_dir(string $dir): array {
    $participants = [];
    $skipped = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
        $sections = ddp_parse_file($path);
        if ($sections === null) {
            $skipped[] = ['path' => basename($path), 'reason' => 'not a DDP array (skipped)'];
            continue;
        }
        $id = ddp_participant_id_from_filename($path);
        if (!isset($participants[$id])) {
            $participants[$id] = ['id' => $id, 'files' => [], 'sections' => []];
        }
        $participants[$id]['files'][] = basename($path);
        foreach ($sections as $name => $rows) {
            $participants[$id]['sections'][$name] =
                array_merge($participants[$id]['sections'][$name] ?? [], $rows);
        }
    }
    ksort($participants);
    return ['participants' => $participants, 'skipped' => $skipped];
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS — `N passed, 0 failed`.

- [ ] **Step 7: Commit**

```bash
git add src/Ddp.php tests/
git commit -m "feat: DDP parsing + test harness (skip non-conforming, merge per participant)"
```

---

### Task 2: Stats (`src/Stats.php`)

**Files:**
- Create: `src/Stats.php`
- Create: `tests/StatsTest.php`

**Interfaces:**
- Consumes: `DDP_SECTION_ORDER`, participant `sections` shape from Task 1.
- Produces:
  - `const DDP_VIDEO_SECTIONS = ['tiktok_watch_history','tiktok_favorite_videos','tiktok_like_list'];`
  - `stats_canonical_video_id(string $url): ?string`
  - `stats_parse_date(string $s): ?int` — unix timestamp (UTC) or null.
  - `stats_section_summary(list<array> $rows): array` — `['count'=>int,'earliest'=>?int,'latest'=>?int]` (dates from each row's `Date`).
  - `stats_unique_video_count(array $sections): int`
  - `stats_participant_scope(array $participant): array` — `['sections'=>[name=>summary...],'unique_videos'=>int,'total_rows'=>int,'earliest'=>?int,'latest'=>?int]`; sections ordered by `DDP_SECTION_ORDER` then any extras.

- [ ] **Step 1: Write the failing test**

`tests/StatsTest.php`:
```php
<?php
require_once __DIR__ . '/../src/Ddp.php';
require_once __DIR__ . '/../src/Stats.php';

eq(stats_canonical_video_id('https://www.tiktokv.com/share/video/7654562293757250829/'), '7654562293757250829', 'canonical tiktokv share');
eq(stats_canonical_video_id('https://www.tiktok.com/@user/video/7654562293757250829'), '7654562293757250829', 'canonical @user form');
eq(stats_canonical_video_id('https://vm.tiktok.com/abc123/'), null, 'short link not canonical');
eq(stats_canonical_video_id('not a url'), null, 'garbage not canonical');

eq(stats_parse_date('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'plain date');
eq(stats_parse_date('2026-05-28 01:36:28 UTC'), gmmktime(1,36,28,5,28,2026), 'UTC-suffixed date');
eq(stats_parse_date('garbage'), null, 'bad date null');

$loaded = ddp_load_dir(__DIR__ . '/fixtures/ddp');
$p1 = $loaded['participants']['p1'];

$wh = stats_section_summary($p1['sections']['tiktok_watch_history']);
eq($wh['count'], 3, 'watch count');
eq($wh['earliest'], gmmktime(23,24,12,1,30,2026), 'watch earliest');
eq($wh['latest'], gmmktime(11,53,55,7,5,2026), 'watch latest');

// video 7654562293757250829 appears in both watch and like -> deduped
eq(stats_unique_video_count($p1['sections']), 4, 'unique videos across watch/fav/like deduped');

$scope = stats_participant_scope($p1);
eq($scope['sections']['tiktok_comments']['count'], 2, 'scope comments count');
eq(array_key_first($scope['sections']), 'tiktok_watch_history', 'scope ordered by DDP_SECTION_ORDER');
eq($scope['unique_videos'], 4, 'scope unique videos');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — undefined function `stats_canonical_video_id`.

- [ ] **Step 3: Implement `src/Stats.php`**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Stats.php tests/StatsTest.php
git commit -m "feat: stats — canonical id, dual date parsing, per-section + participant scope"
```

---

### Task 3: Deterministic sampling (`src/Sample.php`)

**Files:**
- Create: `src/Sample.php`
- Create: `tests/SampleTest.php`

**Interfaces:**
- Produces: `sample_rows(list<array> $rows, int $n, int $seed, string $salt): list<array>` — a deterministic subset of size `min($n, count)`; identical for identical `(rows, n, seed, salt)`; returns all rows unchanged when `$n >= count`.

- [ ] **Step 1: Write the failing test**

`tests/SampleTest.php`:
```php
<?php
require_once __DIR__ . '/../src/Sample.php';

$rows = [];
for ($i = 0; $i < 100; $i++) { $rows[] = ['i' => $i]; }

$a = sample_rows($rows, 10, 1, 'watch');
$b = sample_rows($rows, 10, 1, 'watch');
eq($a, $b, 'same seed+salt is deterministic');
eq(count($a), 10, 'sample size honored');

$c = sample_rows($rows, 10, 2, 'watch');
check($a !== $c, 'different seed yields different sample');

$d = sample_rows($rows, 10, 1, 'comments');
check($a !== $d, 'different salt yields different sample');

$all = sample_rows($rows, 500, 1, 'watch');
eq($all, $rows, 'n >= count returns all rows unchanged');

eq(sample_rows([], 10, 1, 'x'), [], 'empty input returns empty');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — undefined function `sample_rows`.

- [ ] **Step 3: Implement `src/Sample.php`**

```php
<?php
/**
 * Deterministic sample: a stable pseudo-random subset chosen by hashing each
 * row's index with the seed and salt, sorting by the hash, and taking the first n.
 * @param list<array> $rows
 * @return list<array>
 */
function sample_rows(array $rows, int $n, int $seed, string $salt): array {
    $count = count($rows);
    if ($n >= $count) { return $rows; }
    $keys = [];
    foreach ($rows as $i => $_) {
        $keys[$i] = hash('crc32b', $salt . '|' . $seed . '|' . $i);
    }
    asort($keys);
    $chosen = array_slice(array_keys($keys), 0, $n, true);
    $out = [];
    foreach ($chosen as $i) { $out[] = $rows[$i]; }
    return $out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Sample.php tests/SampleTest.php
git commit -m "feat: deterministic seeded row sampling"
```

---

### Task 4: App bootstrap, config, helpers, dev runner

**Files:**
- Create: `src/bootstrap.php`
- Create: `config.php.example`
- Create: `tests/config.test.php`
- Create: `run-dev.sh`
- Create: `public/assets/style.css` (minimal stub; fleshed out in Task 8)

**Interfaces:**
- Produces:
  - `cfg(string $key, $default = null): mixed` — config accessor.
  - `h(?string $s): string` — `htmlspecialchars` wrapper.
  - `url(string $rel): string` — prefixes `base_path`, keeps links relative-safe.
  - `fmt_ts(?int $ts): string` — `Y-m-d H:i` or `—`.
  - Bootstrap loads config from `getenv('DDP_INSPECTOR_CONFIG')` else `<root>/config.php`; requires `Ddp.php`, `Stats.php`, `Sample.php`.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`'s loaded set by creating `tests/PagesTest.php` now with just the bootstrap checks (pages added in later tasks):
```php
<?php
putenv('DDP_INSPECTOR_CONFIG=' . __DIR__ . '/config.test.php');
require_once __DIR__ . '/../src/bootstrap.php';

eq(cfg('default_n'), 15, 'config default_n loaded');
eq(h('<a>&"'), '&lt;a&gt;&amp;&quot;', 'h() escapes');
eq(url('participant.php?id=x'), 'participant.php?id=x', 'url() with empty base_path');
eq(fmt_ts(null), '—', 'fmt_ts null');
eq(fmt_ts(gmmktime(11,53,55,7,5,2026)), '2026-07-05 11:53', 'fmt_ts formats UTC');
```

`tests/config.test.php`:
```php
<?php
return [
    'ddp_dir'         => __DIR__ . '/fixtures/ddp',
    'transcripts_dir' => __DIR__ . '/fixtures/transcripts',
    'default_n'       => 15,
    'base_path'       => '',
];
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `src/bootstrap.php` missing / undefined `cfg`.

- [ ] **Step 3: Implement `src/bootstrap.php`**

```php
<?php
require_once __DIR__ . '/Ddp.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Sample.php';

$__cfg_path = getenv('DDP_INSPECTOR_CONFIG') ?: (__DIR__ . '/../config.php');
if (!is_file($__cfg_path)) {
    http_response_code(500);
    fwrite(STDERR, "Config not found: $__cfg_path (copy config.php.example to config.php)\n");
    echo "Configuration missing. Copy config.php.example to config.php.";
    return;
}
$GLOBALS['__cfg'] = require $__cfg_path;

function cfg(string $key, $default = null) {
    return $GLOBALS['__cfg'][$key] ?? $default;
}
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function url(string $rel): string {
    $base = rtrim((string)cfg('base_path', ''), '/');
    return $base === '' ? $rel : $base . '/' . ltrim($rel, '/');
}
function fmt_ts(?int $ts): string {
    return $ts === null ? '—' : gmdate('Y-m-d H:i', $ts);
}
```

- [ ] **Step 4: Implement `config.php.example`, `run-dev.sh`, CSS stub**

`config.php.example`:
```php
<?php
return [
    // Absolute path to the directory of TikTok DDP JSON files (read-only).
    'ddp_dir'         => '/absolute/path/to/tiktok/ddp/files',
    // Optional: sharded transcript tree (<last-2-digits>/<id>.txt|.json), or null.
    'transcripts_dir' => null,
    // Default sample size per section.
    'default_n'       => 15,
    // URL prefix when served behind a reverse-proxy subpath (e.g. '/inspector'); '' at root.
    'base_path'       => '',
];
```

`run-dev.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -f config.php ]; then
  echo "No config.php — copy config.php.example to config.php and set ddp_dir." >&2
  exit 1
fi
exec php -S 127.0.0.1:8110 -t public
```

`public/assets/style.css`:
```css
/* Minimal stub; expanded in Task 8. */
body { font-family: Georgia, 'Charter', serif; margin: 0; color: #1a1a1a; }
```

- [ ] **Step 5: Make the runner executable, run tests**

Run:
```bash
chmod +x run-dev.sh
php tests/run.php
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/bootstrap.php config.php.example tests/config.test.php tests/PagesTest.php run-dev.sh public/assets/style.css
git commit -m "feat: app bootstrap, config loader, helpers, dev runner"
```

---

### Task 5: Participant list page (`public/index.php`)

**Files:**
- Create: `public/index.php`
- Modify: `tests/PagesTest.php` (append)

**Interfaces:**
- Consumes: `ddp_load_dir`, `stats_participant_scope`, helpers, `DDP_SECTION_ORDER`.
- Produces: HTML at `/` — a participants table (id, total rows, unique videos, overall date range) linking to `participant.php?id=<id>`, and a "skipped files" banner when any.

- [ ] **Step 1: Write the failing test (append to `tests/PagesTest.php`)**

```php
// --- index.php ---
function render_page(string $script, array $get): string {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include __DIR__ . '/../public/' . $script;
    return ob_get_clean();
}

$html = render_page('index.php', []);
check(str_contains($html, 'p1'), 'index lists participant p1');
check(str_contains($html, 'participant.php?id=p1'), 'index links to participant page');
check(str_contains($html, '1 file') || str_contains($html, 'skipped'), 'index shows skipped-file notice');
check(!str_contains($html, 'preview'), 'index does not list the skipped preview participant');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `public/index.php` missing.

- [ ] **Step 3: Implement `public/index.php`**

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';
$loaded = ddp_load_dir((string)cfg('ddp_dir'));
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — participants</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>DDP Inspector</h1>
  <?php if ($loaded['skipped']): ?>
    <p class="skipped">⚠ <?= count($loaded['skipped']) ?> file(s) skipped (non-conforming):
      <?= h(implode(', ', array_column($loaded['skipped'], 'path'))) ?></p>
  <?php endif; ?>
  <table class="scope">
    <thead><tr><th>participant</th><th>total rows</th><th>unique videos</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($loaded['participants'] as $p):
        $scope = stats_participant_scope($p); ?>
      <tr>
        <td><a href="<?= h(url('participant.php?id=' . rawurlencode($p['id']))) ?>"><?= h($p['id']) ?></a></td>
        <td class="num"><?= number_format($scope['total_rows']) ?></td>
        <td class="num"><?= number_format($scope['unique_videos']) ?></td>
        <td><?= h(fmt_ts($scope['earliest'])) ?></td>
        <td><?= h(fmt_ts($scope['latest'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/index.php tests/PagesTest.php
git commit -m "feat: participant list page with scope summary and skipped-file banner"
```

---

### Task 6: Per-participant page (`public/participant.php`)

**Files:**
- Create: `public/participant.php`
- Modify: `tests/PagesTest.php` (append)

**Interfaces:**
- Consumes: `ddp_load_dir`, `stats_participant_scope`, `sample_rows`, `stats_canonical_video_id`, helpers, `DDP_SECTION_ORDER`.
- Produces: HTML at `participant.php?id=&seed=&n=` — scope table + each section (true count/date-range header, sampled rows, reshuffle link). Video rows link to `transcript.php?vid=<id>`. Comments standalone. `return`s a 404 body if id unknown.

- [ ] **Step 1: Write the failing test (append)**

```php
// --- participant.php ---
$html = render_page('participant.php', ['id' => 'p1', 'seed' => '1']);
check(str_contains($html, 'tiktok_watch_history'), 'participant shows watch history section');
check(str_contains($html, 'tiktok_comments'), 'participant shows comments section');
check(str_contains($html, 'Igual estoy yo') || str_contains($html, 'second comment'), 'comment text rendered');
check(str_contains($html, 'transcript.php?vid=7654562293757250829'), 'video row links to transcript');
check(str_contains($html, 'seed=2'), 'reshuffle link bumps seed');

$missing = render_page('participant.php', ['id' => 'nope']);
check(str_contains($missing, 'not found') || str_contains($missing, '404'), 'unknown participant -> not found');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `public/participant.php` missing.

- [ ] **Step 3: Implement `public/participant.php`**

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';

$id   = (string)($_GET['id'] ?? '');
$seed = max(1, (int)($_GET['seed'] ?? 1));
$n    = max(1, (int)($_GET['n'] ?? cfg('default_n', 15)));

$loaded = ddp_load_dir((string)cfg('ddp_dir'));
$participant = $loaded['participants'][$id] ?? null;
if ($participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$scope = stats_participant_scope($participant);
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← all participants</a></p>
  <h1>participant <?= h($id) ?></h1>
  <p class="meta"><?= count($participant['files']) ?> file(s); unique videos:
     <strong><?= number_format($scope['unique_videos']) ?></strong></p>

  <h2>Scope</h2>
  <table class="scope">
    <thead><tr><th>section</th><th>rows</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($scope['sections'] as $name => $s): ?>
      <tr><td><?= h($name) ?></td><td class="num"><?= number_format($s['count']) ?></td>
          <td><?= h(fmt_ts($s['earliest'])) ?></td><td><?= h(fmt_ts($s['latest'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php foreach ($scope['sections'] as $name => $s):
      $rows = $participant['sections'][$name];
      $sample = sample_rows($rows, $n, $seed, $name);
      $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1)); ?>
    <section>
      <h3><?= h($name) ?> <span class="count"><?= number_format($s['count']) ?> rows</span>
        <?php if ($s['count'] > count($sample)): ?>
          <a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a>
        <?php endif; ?>
      </h3>
      <table class="rows">
        <?php foreach ($sample as $row):
            $date = h((string)($row['Date'] ?? ''));
            if ($name === 'tiktok_comments'): ?>
              <tr><td class="date"><?= $date ?></td><td class="comment"><?= h((string)($row['Comment'] ?? '')) ?></td></tr>
            <?php else:
              $vid = isset($row['Link']) ? stats_canonical_video_id((string)$row['Link']) : null; ?>
              <tr>
                <td class="date"><?= $date ?></td>
                <td class="vid">
                  <?php if ($vid !== null): ?>
                    <a href="<?= h(url('transcript.php?vid=' . $vid)) ?>"><?= h($vid) ?></a>
                  <?php else: ?>
                    <?= h((string)($row['Link'] ?? '')) ?>
                  <?php endif; ?>
                </td>
                <?php if ($name === 'tiktok_share_history'): ?>
                  <td class="method"><?= h((string)($row['Method'] ?? '')) ?> · <?= h((string)($row['SharedContent'] ?? '')) ?></td>
                <?php endif; ?>
              </tr>
            <?php endif; ?>
        <?php endforeach; ?>
      </table>
    </section>
  <?php endforeach; ?>
</main>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/participant.php tests/PagesTest.php
git commit -m "feat: per-participant page — scope table, sampled sections, reshuffle, transcript links"
```

---

### Task 7: Transcript page (`public/transcript.php`)

**Files:**
- Create: `public/transcript.php`
- Create: `tests/fixtures/transcripts/29/7654562293757250829.txt`
- Create: `tests/fixtures/transcripts/29/7654562293757250829.json`
- Modify: `tests/PagesTest.php` (append)

**Interfaces:**
- Consumes: helpers, `cfg('transcripts_dir')`.
- Produces: HTML at `transcript.php?vid=<19-digit>` — transcript text + per-segment avg-confidence table (low-p flagged) + collapsible raw JSON; "not transcribed yet" when absent/unconfigured; 400 body when `vid` fails `^\d{19}$`.

- [ ] **Step 1: Create transcript fixtures**

`tests/fixtures/transcripts/29/7654562293757250829.txt`:
```
Hello world this is a test transcript.
```

`tests/fixtures/transcripts/29/7654562293757250829.json`:
```json
{
  "model": "small",
  "language": "en",
  "transcribed_at": "2026-07-05T12:00:00Z",
  "raw_signals": {
    "schema_version": 1,
    "segments": [
      {"text": "Hello world", "tokens": [{"text": "Hello", "p": 0.98}, {"text": "world", "p": 0.91}]},
      {"text": "this is a test", "tokens": [{"text": "this", "p": 0.42}, {"text": "[_TT_]", "p": 0.99}]}
    ]
  }
}
```

- [ ] **Step 2: Write the failing test (append)**

```php
// --- transcript.php ---
$ok = render_page('transcript.php', ['vid' => '7654562293757250829']);
check(str_contains($ok, 'Hello world this is a test'), 'transcript text rendered');
check(str_contains($ok, '0.42') || str_contains($ok, 'low'), 'low-confidence segment surfaced');

$none = render_page('transcript.php', ['vid' => '1111111111111111111']);
check(str_contains($none, 'not transcribed'), 'missing transcript -> not transcribed');

$bad = render_page('transcript.php', ['vid' => '../etc/passwd']);
check(str_contains($bad, '400') || str_contains($bad, 'invalid'), 'bad vid rejected');
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `public/transcript.php` missing.

- [ ] **Step 4: Implement `public/transcript.php`**

```php
<?php
require_once __DIR__ . '/../src/bootstrap.php';

$vid = (string)($_GET['vid'] ?? '');
if (!preg_match('/^\d{19}$/', $vid)) {
    http_response_code(400);
    echo '<!doctype html><title>Bad request</title><p>Invalid video id (400).</p>';
    return;
}

$dir = cfg('transcripts_dir');
$txt = null; $meta = null;
if ($dir) {
    $base = rtrim((string)$dir, '/') . '/' . substr($vid, -2) . '/' . $vid;
    if (is_file($base . '.txt'))  { $txt  = file_get_contents($base . '.txt'); }
    if (is_file($base . '.json')) { $meta = json_decode((string)file_get_contents($base . '.json'), true); }
}

/** Average token confidence for a segment, ignoring special [_...] tokens. */
$seg_avg = function (array $seg): ?float {
    $ps = [];
    foreach ($seg['tokens'] ?? [] as $t) {
        $txt = (string)($t['text'] ?? '');
        if ($txt !== '' && $txt[0] === '[') { continue; }
        if (isset($t['p'])) { $ps[] = (float)$t['p']; }
    }
    return $ps ? array_sum($ps) / count($ps) : null;
};
?>
<!doctype html>
<meta charset="utf-8">
<title>transcript <?= h($vid) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>transcript <?= h($vid) ?></h1>
  <?php if ($txt === null && $meta === null): ?>
    <p class="notice">Not transcribed yet.</p>
  <?php else: ?>
    <pre class="transcript"><?= h((string)$txt) ?></pre>
    <?php $segs = $meta['raw_signals']['segments'] ?? null; if (is_array($segs)): ?>
      <h2>Segment confidence</h2>
      <table class="rows">
        <thead><tr><th>avg p</th><th>segment</th></tr></thead>
        <tbody>
        <?php foreach ($segs as $seg): $avg = $seg_avg($seg); ?>
          <tr><td class="num <?= ($avg !== null && $avg < 0.5) ? 'low' : '' ?>">
                <?= $avg === null ? '—' : number_format($avg, 2) ?></td>
              <td><?= h((string)($seg['text'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($meta !== null): ?>
      <p class="notice">No raw_signals in this artifact (pre-Epic-1 schema).</p>
    <?php endif; ?>
    <?php if ($meta !== null): ?>
      <details><summary>raw JSON</summary><pre><?= h(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
    <?php endif; ?>
  <?php endif; ?>
</main>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add public/transcript.php tests/fixtures/transcripts tests/PagesTest.php
git commit -m "feat: transcript page — text + segment confidence, graceful not-transcribed, vid validation"
```

---

### Task 8: Styling, deploy docs, README

**Files:**
- Modify: `public/assets/style.css`
- Create: `deploy/nginx-location.conf.example`
- Create: `deploy/PROVISION.md`
- Create: `README.md`

**Interfaces:**
- Consumes: nothing new. Documentation + presentation only.

- [ ] **Step 1: Flesh out `public/assets/style.css`**

```css
:root { --ink:#1a1a1a; --paper:#f9f9f7; --rule:#d8d8d2; --accent:#8a1a1a; --low:#d03b3b; }
body { font-family: Georgia,'Charter',serif; background:var(--paper); color:var(--ink); margin:0; line-height:1.45; }
.wrap { max-width:60rem; margin:0 auto; padding:1.5rem; }
h1 { font-size:1.5rem; } h2 { font-size:1.1rem; margin-top:1.6rem; } h3 { font-size:1rem; }
a { color:var(--accent); }
table { border-collapse:collapse; width:100%; margin:.5rem 0 1rem; }
th,td { text-align:left; padding:.25rem .5rem; border-bottom:1px solid var(--rule); vertical-align:top; }
th { font-size:.8rem; text-transform:uppercase; letter-spacing:.03em; color:#666; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
.date,.vid,.transcript,pre { font-family:'DejaVu Sans Mono',monospace; font-size:.85rem; }
.count { color:#666; font-weight:normal; font-size:.85rem; }
.reshuffle { font-size:.8rem; margin-left:.5rem; }
.skipped,.notice { background:#fff6e6; border:1px solid #e5cf9a; padding:.5rem .75rem; border-radius:4px; }
.low { color:var(--low); font-weight:bold; }
.transcript { white-space:pre-wrap; background:#fff; border:1px solid var(--rule); padding:.75rem; }
details summary { cursor:pointer; color:#666; }
```

- [ ] **Step 2: Write `deploy/nginx-location.conf.example`**

```nginx
# DDP Inspector behind the SURF Research Cloud nginx + SRAM component.
# Add to /etc/nginx/app-location-conf.d/authentication.conf and reload nginx.
# Assumes php-fpm listening on 127.0.0.1:9000 and docroot at /opt/ddp-inspector/public.
location /inspector/ {
    error_page 401 = @custom_401;
    auth_request /validate;                       # SRAM login, CO members only
    auth_request_set $username $upstream_http_username;

    alias /opt/ddp-inspector/public/;
    index index.php;

    location ~ ^/inspector/(.+\.php)$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME /opt/ddp-inspector/public/$1;
    }
}
# Set 'base_path' => '/inspector' in config.php so links resolve behind the subpath.
```

- [ ] **Step 3: Write `deploy/PROVISION.md`**

```markdown
# Provisioning DDP Inspector in a SURF Research Cloud workspace

## Prerequisites (Ansible playbook additions)
- Install PHP: `package: { name: [php-fpm, php-cli] }`
- Enable php-fpm: `service: { name: php-fpm, state: started, enabled: true }`

## Deploy
1. Copy the repo to `/opt/ddp-inspector` (exclude `config.php`, tests optional).
2. `cp config.php.example config.php`; set:
   - `ddp_dir` → read-only path to the workspace's TikTok DDP files.
   - `transcripts_dir` → sharded transcript tree, or `null`.
   - `base_path` → `/inspector`.
3. Add `deploy/nginx-location.conf.example` content to
   `/etc/nginx/app-location-conf.d/authentication.conf`; adjust paths/fpm socket.
4. `systemctl reload nginx`.
5. Browse `https://<hostname>.<co>.src.surf-hosted.nl/inspector/` (SRAM login required).

## Local dev alternative
`cp config.php.example config.php` (set `ddp_dir`), then `./run-dev.sh` → http://127.0.0.1:8110
```

- [ ] **Step 4: Write `README.md`**

```markdown
# DDP Inspector

A read-only, per-participant viewer for TikTok DDP (Data Donation Package) extracts.
Renders each participant's five DDP sections faithfully — with scope (counts, date
ranges, unique videos), sampled content, and an optional transcript view — so a
researcher can verify that donations match expectations.

Companion to the `ddp-transcribe` pipeline; **not** part of it. See the design spec
in `docs/superpowers/specs/` and the plan in `docs/superpowers/plans/`.

## Run locally
```bash
cp config.php.example config.php   # set ddp_dir to your DDP JSON directory
./run-dev.sh                       # http://127.0.0.1:8110
```

## Test
```bash
php tests/run.php
```

## Deploy (SURF Research Cloud, behind nginx + SRAM)
See `deploy/PROVISION.md`.

## Notes
- Reads raw DDP JSON files directly (not the pipeline's state DB).
- Comments are shown standalone — the export carries no comment↔video link.
- Transcripts are optional; unset `transcripts_dir` and the tool still works fully.
```

- [ ] **Step 5: Run the full suite once more, then commit**

Run: `php tests/run.php`
Expected: PASS.

```bash
git add public/assets/style.css deploy README.md
git commit -m "docs+style: styling, SRC deploy notes, README"
```

---

## Self-Review

**Spec coverage:**
- §3 DDP format (5 sections, participant-from-filename, dual dates, canonical id) → Tasks 1–2. ✓
- §4 architecture (src/ pure vs public/ pages, read-only) → all tasks. ✓
- §5 reverse-proxy relative URLs → `url()` (Task 4), used everywhere. ✓
- §6 index / participant / transcript pages, scope table, seeded sample + reshuffle, standalone comments → Tasks 5–7. ✓
- §7 optional graceful transcripts + confidence → Task 7. ✓
- §8 error handling (skip non-conforming, malformed dates, vid `^\d{19}$`, multi-file merge, empty sections) → Tasks 1 (skip/merge), 2 (dates), 7 (vid). ✓
- §9 deployment (php -S dev; php-fpm + nginx SRAM) → Tasks 4, 8. ✓
- §10 testing (zero-dep harness, synthetic fixtures) → Task 1 harness, fixtures throughout. ✓
- §11 repo structure → produced across tasks. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. ✓

**Type consistency:** `ddp_load_dir` shape (`participants`/`skipped`) consistent across Tasks 1/5/6; `stats_participant_scope` `sections`/`unique_videos`/`total_rows`/`earliest`/`latest` consistent Tasks 2/5/6; `sample_rows($rows,$n,$seed,$salt)` consistent Tasks 3/6; helpers `cfg/h/url/fmt_ts` defined Task 4, used Tasks 5–7. ✓

**Note for implementer:** `tests/PagesTest.php` is created in Task 4 (bootstrap checks) and appended to in Tasks 5–7. The `render_page()` helper is defined once in Task 5's append and reused in Tasks 6–7.
