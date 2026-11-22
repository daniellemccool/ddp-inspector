<?php
// A file packaged in the study setup (a PDF, a screenshot, the logo), by its
// stored name: asset.php?f=document-1.pdf. The files live on the storage
// volume, outside the web root, and came from an upload: only a plain name
// that is actually stored is served, never as a page that could run script.
require_once __DIR__ . '/../src/bootstrap.php';

$name = cfg_ready() && is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
$path = $name === '' ? null : setup_asset_file($name);
if ($path === null) {
    http_response_code(404);
    if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8'); }
    echo 'Not found';
    return;
}
$kind = setup_asset_type($name);
if (!headers_sent()) {
    header('Content-Type: ' . $kind['type']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($kind['inline'] ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Cache-Control: private, max-age=3600');
    // An SVG opened on its own is a document: keep script in it from running.
    if ($kind['type'] === 'image/svg+xml') {
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    }
}
readfile($path);
