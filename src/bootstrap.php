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
