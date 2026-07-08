<?php
require_once __DIR__ . '/Ddp.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Sample.php';

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
