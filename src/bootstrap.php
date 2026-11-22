<?php
require_once __DIR__ . '/Ddp.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Sample.php';
require_once __DIR__ . '/Instance.php';
require_once __DIR__ . '/Flows.php';
require_once __DIR__ . '/StudySetup.php';
require_once __DIR__ . '/Analysis.php';
require_once __DIR__ . '/Study.php';
require_once __DIR__ . '/StudyDb.php';

$__cfg_path = getenv('DDP_INSPECTOR_CONFIG') ?: (__DIR__ . '/../config.php');
$GLOBALS['__cfg'] = is_file($__cfg_path) ? require $__cfg_path : null;
if (!is_array($GLOBALS['__cfg'])) {
    error_log("DDP Inspector: config not found at $__cfg_path (copy config.php.example to config.php)");
}

function cfg_ready(): bool { return is_array($GLOBALS['__cfg'] ?? null); }
function cfg(string $key, $default = null) { return $GLOBALS['__cfg'][$key] ?? $default; }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $rel): string { $base = rtrim((string)cfg('base_path', ''), '/'); return $base === '' ? $rel : $base . '/' . ltrim($rel, '/'); }
/** Static asset URL with an mtime version tag, so browsers revalidate on deploy. */
function asset_url(string $rel): string {
    $v = @filemtime(__DIR__ . '/../public/' . $rel) ?: 0;
    return url($rel) . '?v=' . $v;
}

/**
 * The document head, the site header and the title band every page shares,
 * up to the start of the page's own content. $section is the nav link to
 * mark current: 'participants' or 'settings'. $heading is the page's <h1>
 * content, as HTML that is already escaped. The header follows
 * datadonation.eu's: the name over a subtitle (here the study's name), the
 * links on the right. Close the page with page_bottom().
 */
function page_top(string $title, string $section, string $heading): void {
    $storage = inst_root() !== null;
    $study = $storage ? trim((string)(inst_load()['study_name'] ?? '')) : '';
    $links = ['participants' => ['index.php', 'Participants']];
    if ($storage) { $links['settings'] = ['setup.php', 'Settings']; }
    ?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDP Inspector — <?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(asset_url('assets/tokens.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('assets/style.css')) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="<?= h(url('index.php')) ?>">
      <span class="site-header__name">DDP Inspector</span>
      <span class="site-header__subtitle"><?= h($study !== '' ? $study : 'Data donations and transcripts') ?></span>
    </a>
    <nav class="site-nav" aria-label="Main">
      <ul class="site-nav__list">
      <?php foreach ($links as $key => [$href, $label]): ?>
        <li class="site-nav__item<?= $key === $section ? ' is-active' : '' ?>"><a href="<?= h(url($href)) ?>"<?= $key === $section ? ' aria-current="true"' : '' ?>><?= h($label) ?></a></li>
      <?php endforeach; ?>
      </ul>
    </nav>
  </div>
</header>
<main id="main">
<?php // The front page's band in small: the title on a line between two nodes,
      // a few of its pieces on that line, and the stepped lower edge. All of the
      // drawing is decoration. Whatever a page marks .niche sits in the white
      // niche under the band's shallow side, where the front page puts its note. ?>
<div class="band">
  <i class="band__deep" aria-hidden="true"></i>
  <div class="band__row">
    <i class="band__lead" aria-hidden="true"></i>
    <div class="band__main">
    <h1><?= $heading ?></h1>
    <div class="band__thread" aria-hidden="true">
      <i class="band__line band__line--title"></i>
      <svg class="band__drop" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 0 C50 0 50 100 100 100" vector-effect="non-scaling-stroke"/></svg>
      <i class="band__line band__line--deep"></i>
      <img class="band__piece band__piece--phone" src="<?= h(asset_url('assets/network/phone.svg')) ?>" alt="" width="36" height="36">
      <img class="band__piece band__piece--badge" src="<?= h(asset_url('assets/network/consent-badge.svg')) ?>" alt="" width="52" height="52">
      <img class="band__piece band__piece--globe" src="<?= h(asset_url('assets/network/globe.svg')) ?>" alt="" width="40" height="40">
    </div>
    </div>
  </div>
  <div class="band__step" aria-hidden="true">
    <svg viewBox="0 0 100 100" preserveAspectRatio="none">
      <path class="band__step-sky" d="M0 -2 H102 V100 H100 C50 100 50 0 0 0 Z"/>
      <path class="band__edge" d="M-100000 0 H0 C50 0 50 100 100 100 H100000" vector-effect="non-scaling-stroke"/>
    </svg>
  </div>
</div>
<div class="wrap">
<?php
}

/** Closes what page_top() opened. */
function page_bottom(): void {
    echo "</div>\n</main>\n";
}

/**
 * The end of an action: a short dotted line and the arrow disc, as on
 * datadonation.eu's "More" links. Goes last inside a button or link.
 */
function go_mark(): string {
    return '<i class="more__dots"></i><svg class="go" width="24" height="24" viewBox="-18 -18 36 36" aria-hidden="true">'
        . '<circle r="18"/><path d="M-8 0 h16 M1.5 -6.5 l6.5 6.5 l-6.5 6.5"/></svg>';
}

function fmt_ts(?int $ts): string { return $ts === null ? '—' : gmdate('Y-m-d H:i', $ts); }

/** Human-scale counts: 3,181 · 11.4M · 1.1B (full value belongs in a title attr). */
function fmt_compact(int $n): string {
    if ($n < 10000) { return number_format($n); }
    foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$div, $suffix]) {
        if ($n >= $div) {
            $v = $n / $div;
            return ($v >= 100 ? number_format($v) : number_format($v, 1)) . $suffix;
        }
    }
    return number_format($n);
}

/** ISO-8601 → "19 Apr 2026, 10:28" (UTC); unparseable input passes through. */
function fmt_date_iso(?string $s): string {
    if ($s === null || trim($s) === '') { return '—'; }
    $ts = stats_parse_date_any($s);
    return $ts === null ? $s : gmdate('j M Y, H:i', $ts);
}

function lang_name(?string $code): ?string {
    if ($code === null || $code === '') { return null; }
    $names = ['en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French',
              'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'ar' => 'Arabic',
              'tr' => 'Turkish', 'ru' => 'Russian', 'zh' => 'Chinese', 'ja' => 'Japanese',
              'ko' => 'Korean', 'id' => 'Indonesian', 'hi' => 'Hindi', 'pl' => 'Polish'];
    return $names[strtolower($code)] ?? strtoupper($code);
}

function csrf_token(): string {
    if (!isset($_COOKIE['ddpi_csrf']) || strlen((string)$_COOKIE['ddpi_csrf']) < 32) {
        $_COOKIE['ddpi_csrf'] = bin2hex(random_bytes(16));
        if (!headers_sent()) { setcookie('ddpi_csrf', $_COOKIE['ddpi_csrf'], ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']); }
    }
    return (string)$_COOKIE['ddpi_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(?array $post = null): bool {
    $post ??= $_POST;
    return isset($post['csrf']) && hash_equals(csrf_token(), (string)$post['csrf']);
}

function guard_configured(): bool {
    if (inst_configured()) { return true; }
    if (!headers_sent()) { header('Location: ' . url('setup.php')); }
    echo '<!doctype html><meta charset="utf-8"><p>This inspector is not set up yet — <a href="'
        . h(url('setup.php')) . '">set it up</a>.</p>';
    return false;
}
