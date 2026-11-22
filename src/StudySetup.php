<?php
// The study setup: the RO-Crate zip a researcher exports from Next (study
// page, ⋮ → Export). It holds what participants saw: branding, the About and
// Helpdesk pages, the consent text, and the tasks in order, with the files
// those refer to. Uploaded in Setup, unpacked once into config/study-setup/
// under names the app chooses, shown on the Study page.
//
// Next streams its zips (sizes after the data), which PharData cannot read;
// ZipArchive (php-zip) can.

const SETUP_MAX_ZIP = 64 * 1024 * 1024;        // the upload itself
const SETUP_MAX_ENTRIES = 500;
const SETUP_MAX_UNPACKED = 256 * 1024 * 1024;  // all entries together
const SETUP_MAX_JSON = 4 * 1024 * 1024;        // each of the three JSON files
const SETUP_JSON_FILES = ['next-metadata.json', 'ro-crate-metadata.json', 'export-warnings.json'];

/** A packaged file's name as the app will store and serve it: one plain
 *  path segment. Next names them itself (logo.png, document-1.pdf,
 *  instruction-step-1-1-1.png), so nothing real is turned away. */
function setup_asset_name_ok(string $name): bool {
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $name) === 1;
}

/** Delete a directory tree the app itself wrote (never follows a link). */
function setup_rmtree(string $dir): void {
    if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) { @rmdir($f->getPathname()); } else { @unlink($f->getPathname()); }
    }
    @rmdir($dir);
}

/**
 * Read an uploaded study setup zip and store it under $destDir, replacing
 * what was there. A failure leaves the stored setup as it was.
 * @return array{ok:bool, message:string, name:?string, tasks:?int}
 */
function setup_ingest_upload(string $zipPath, string $destDir, string $origName = ''): array {
    $fail = fn(string $msg) => ['ok' => false, 'message' => $msg, 'name' => null, 'tasks' => null];
    $notSetup = "That doesn't look like a study setup from Next. Upload the zip exactly as Next downloaded it (⋮ → Export).";
    $tooLarge = 'That zip is too large to be a study setup (more than ' . SETUP_MAX_ENTRIES . ' files, or more than '
              . intdiv(SETUP_MAX_UNPACKED, 1024 * 1024) . ' MB unpacked).';
    $cantSave = 'Could not save the study setup — the storage volume may be full or read-only.';
    if (!class_exists('ZipArchive')) {
        return $fail('This workspace is missing a component (php-zip) — contact whoever set up this workspace.');
    }
    if (!is_file($zipPath)) { return $fail('No file arrived — please choose the zip Next downloaded and try again.'); }
    if (filesize($zipPath) > SETUP_MAX_ZIP) {
        return $fail('That file is larger than ' . intdiv(SETUP_MAX_ZIP, 1024 * 1024) . ' MB — too large for a study setup here. Is it the right zip?');
    }
    $za = new ZipArchive();
    if ($za->open($zipPath, ZipArchive::RDONLY) !== true) { return $fail($notSetup); }
    $new = $destDir . '.new';
    try {
        if ($za->numFiles > SETUP_MAX_ENTRIES) { return $fail($tooLarge); }
        // One pass over the listing: everything sits in one top folder; the
        // three JSON files and assets/<plain name> are taken, the rest is
        // left out and counted. Nothing is written under a name from the zip
        // that has not passed setup_asset_name_ok().
        $tops = []; $json = []; $assets = []; $leftOut = 0; $total = 0; $rootFiles = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $st = $za->statIndex($i);
            if ($st === false) { return $fail($notSetup); }
            $name = (string)$st['name'];
            if (str_ends_with($name, '/')) { continue; }          // a folder entry
            $total += (int)$st['size'];
            $slash = strpos($name, '/');
            if ($slash === false) { $rootFiles[] = $name; continue; }
            $tops[substr($name, 0, $slash)] = true;
            $rest = substr($name, $slash + 1);
            if (in_array($rest, SETUP_JSON_FILES, true)) { $json[$rest] = $st; continue; }
            if (str_starts_with($rest, 'assets/') && setup_asset_name_ok(substr($rest, 7))) { $assets[substr($rest, 7)] = $st; continue; }
            $leftOut++;
        }
        if (in_array('documentation.txt', $rootFiles, true)) {
            return $fail('That is a donation flow from the script selector — upload it under “Donation flows”.');
        }
        if ($rootFiles !== [] || count($tops) !== 1 || !isset($json['next-metadata.json'])) { return $fail($notSetup); }
        if ($total > SETUP_MAX_UNPACKED) { return $fail($tooLarge); }

        // An entry's bytes, never more than it declared (a zip can lie).
        $read = function (array $st, int $cap) use ($za): ?string {
            if ((int)$st['size'] > $cap) { return null; }
            $h = $za->getStream((string)$st['name']);
            if ($h === false) { return null; }
            $data = stream_get_contents($h, (int)$st['size'] + 1);
            fclose($h);
            return ($data === false || strlen($data) > (int)$st['size']) ? null : $data;
        };
        $metaText = $read($json['next-metadata.json'], SETUP_MAX_JSON);
        $meta = $metaText === null ? null : json_decode($metaText, true);
        if (!is_array($meta) || !is_int($meta['format_version'] ?? null)) { return $fail($notSetup); }

        setup_rmtree($new);
        if (!@mkdir("$new/assets", 0755, true)) { return $fail($cantSave); }
        if (@file_put_contents("$new/next-metadata.json", $metaText) === false) { return $fail($cantSave); }
        // The crate and the warnings are optional, and kept only if readable.
        foreach (['ro-crate-metadata.json', 'export-warnings.json'] as $opt) {
            if (!isset($json[$opt])) { continue; }
            $text = $read($json[$opt], SETUP_MAX_JSON);
            if ($text === null || !is_array(json_decode($text, true))) { continue; }
            if (@file_put_contents("$new/$opt", $text) === false) { return $fail($cantSave); }
        }
        foreach ($assets as $name => $st) {
            $in = $za->getStream((string)$st['name']);
            $out = $in === false ? false : @fopen("$new/assets/$name", 'wb');
            if ($in === false || $out === false) { if ($in !== false) { fclose($in); } return $fail($cantSave); }
            $copied = stream_copy_to_stream($in, $out, (int)$st['size'] + 1);
            fclose($in); fclose($out);
            if ($copied === false) { return $fail($cantSave); }
            if ($copied > (int)$st['size']) { return $fail($tooLarge); }
        }
        $zipName = substr((string)preg_replace('/[^\PC]/u', '', basename($origName)) ?: '', 0, 200);
        if (!inst_write_json_atomic("$new/upload-meta.json", ['uploaded_at' => gmdate('c'), 'zip_name' => $zipName])) {
            return $fail($cantSave);
        }
        // Swap the finished directory in; the old one goes only once the new one is in place.
        $old = $destDir . '.old';
        setup_rmtree($old);
        if (is_dir($destDir) && !@rename($destDir, $old)) { return $fail($cantSave); }
        if (!@rename($new, $destDir)) { if (is_dir($old)) { @rename($old, $destDir); } return $fail($cantSave); }
        setup_rmtree($old);
    } finally {
        $za->close();
        setup_rmtree($new);
    }
    $name = is_string($meta['assignment']['name'] ?? null) ? trim($meta['assignment']['name']) : '';
    $taskList = $meta['workflow']['tasks'] ?? null;
    $tasks = is_array($taskList) ? count($taskList) : 0;
    $message = ($name !== '' ? $name : 'Study setup') . " — $tasks task" . ($tasks === 1 ? '' : 's') . ' ✓';
    if ($leftOut > 0) { $message .= " $leftOut file" . ($leftOut === 1 ? '' : 's') . ' in the zip were not recognised and were left out.'; }
    return ['ok' => true, 'message' => $message, 'name' => $name !== '' ? $name : null, 'tasks' => $tasks];
}

/** Remove the stored study setup. True when there was one. */
function setup_remove(string $destDir): bool {
    if (!is_dir($destDir)) { return false; }
    setup_rmtree($destDir);
    return !is_dir($destDir);
}

/**
 * The stored study setup, or null when there is none.
 *   meta      next-metadata.json
 *   crate     ro-crate-metadata.json, or null
 *   warnings  files Next could not include (export-warnings.json), as the
 *             metadata refers to them: "assets/<name>"
 *   uploaded  upload-meta.json: uploaded_at, zip_name
 *   assets    the stored files' names, sorted
 * @return ?array{meta:array, crate:?array, warnings:list<string>, uploaded:array, assets:list<string>}
 */
function setup_load(): ?array {
    $dir = inst_paths()['setup'];
    $meta = $dir === null ? null : inst_read_json("$dir/next-metadata.json");
    if ($meta === null) { return null; }
    $warnings = [];
    foreach ((array)(inst_read_json("$dir/export-warnings.json")['skipped'] ?? []) as $item) {
        $p = is_array($item) ? ($item['path'] ?? null) : null;
        if (!is_string($p) || $p === '') { continue; }
        $slash = strpos($p, '/');
        $warnings[] = $slash === false ? $p : substr($p, $slash + 1);   // without the top folder
    }
    $assets = [];
    foreach (@scandir("$dir/assets") ?: [] as $f) {
        if (setup_asset_name_ok($f) && is_file("$dir/assets/$f")) { $assets[] = $f; }
    }
    sort($assets);
    return ['meta' => $meta, 'crate' => inst_read_json("$dir/ro-crate-metadata.json"),
            'warnings' => $warnings, 'uploaded' => inst_read_json("$dir/upload-meta.json") ?? [], 'assets' => $assets];
}

/** Does this zip hold a next-metadata.json one folder down? Used to tell a
 *  researcher who put a study setup in the donation-flows upload where it goes. */
function setup_zip_is_study_setup(string $zipPath): bool {
    if (!class_exists('ZipArchive') || !is_file($zipPath)) { return false; }
    $za = new ZipArchive();
    if ($za->open($zipPath, ZipArchive::RDONLY) !== true) { return false; }
    $found = false;
    for ($i = 0; $i < min($za->numFiles, SETUP_MAX_ENTRIES) && !$found; $i++) {
        $found = preg_match('~^[^/]+/next-metadata\.json$~', (string)$za->getNameIndex($i)) === 1;
    }
    $za->close();
    return $found;
}

/** "21 Nov 2026" for an ISO date; '' when there is none or it does not parse. */
function setup_day(mixed $iso): string {
    $ts = is_string($iso) && trim($iso) !== '' ? stats_parse_date_any($iso) : null;
    return $ts === null ? '' : gmdate('j M Y', $ts);
}

/** What Setup lists for the stored study setup: its name, and when it was
 *  exported (the crate's date) or, when the zip had no crate, uploaded.
 *  @return array{name:string, when:string} */
function setup_summary(array $setup): array {
    $name = $setup['meta']['assignment']['name'] ?? null;
    $name = is_string($name) && trim($name) !== '' ? trim($name) : 'Study setup';
    $exported = '';
    foreach ((array)($setup['crate']['@graph'] ?? []) as $node) {
        if (is_array($node) && ($node['@id'] ?? null) === './') { $exported = setup_day($node['datePublished'] ?? null); }
    }
    if ($exported !== '') { return ['name' => $name, 'when' => "exported $exported"]; }
    $uploaded = setup_day($setup['uploaded']['uploaded_at'] ?? null);
    return ['name' => $name, 'when' => $uploaded !== '' ? "uploaded $uploaded" : ''];
}
