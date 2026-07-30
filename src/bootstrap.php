<?php
require_once __DIR__ . '/Ddp.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Sample.php';
require_once __DIR__ . '/Instance.php';
require_once __DIR__ . '/Flows.php';
require_once __DIR__ . '/Analysis.php';

$__cfg_path = getenv('DDP_INSPECTOR_CONFIG') ?: (__DIR__ . '/../config.php');
$GLOBALS['__cfg'] = is_file($__cfg_path) ? require $__cfg_path : null;
if (!is_array($GLOBALS['__cfg'])) {
    error_log("DDP Inspector: config not found at $__cfg_path (copy config.php.example to config.php)");
}

function cfg_ready(): bool { return is_array($GLOBALS['__cfg'] ?? null); }
function cfg(string $key, $default = null) { return $GLOBALS['__cfg'][$key] ?? $default; }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $rel): string { $base = rtrim((string)cfg('base_path', ''), '/'); return $base === '' ? $rel : $base . '/' . ltrim($rel, '/'); }
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
