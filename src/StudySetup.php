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

/** Delete a directory tree the app itself wrote (never follows a link).
 *  A tree it cannot read is left where it is; the caller's next write fails
 *  and is reported. */
function setup_rmtree(string $dir): void {
    if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
    if (!is_dir($dir)) { return; }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) { @rmdir($f->getPathname()); } else { @unlink($f->getPathname()); }
        }
    } catch (UnexpectedValueException) {
        return;
    }
    @rmdir($dir);
}

/**
 * Run $fn while holding the study setup's lock, so that two requests (a
 * double click on Upload, two researchers) never share the staging
 * directory. Waits up to $waitSeconds for another request to finish;
 * returns null when it did not get the lock.
 */
function setup_locked(string $destDir, float $waitSeconds, callable $fn): mixed {
    $parent = dirname($destDir);
    if (!is_dir($parent)) { @mkdir($parent, 0755, true); }
    $lock = @fopen($destDir . '.lock', 'c');
    if ($lock === false) { return null; }
    try {
        $deadline = microtime(true) + $waitSeconds;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) { return null; }
            usleep(50_000);
        }
        try { return $fn(); } finally { flock($lock, LOCK_UN); }
    } finally {
        fclose($lock);
    }
}

/**
 * Read an uploaded study setup zip and store it under $destDir, replacing
 * what was there. A failure leaves the stored setup as it was.
 * $limits (for tests): 'unpacked' => the cap on all entries together, in
 * bytes; 'lock_wait' => seconds to wait for another upload to finish.
 * @return array{ok:bool, message:string, name:?string, tasks:?int}
 */
function setup_ingest_upload(string $zipPath, string $destDir, string $origName = '', array $limits = []): array {
    $fail = fn(string $msg) => ['ok' => false, 'message' => $msg, 'name' => null, 'tasks' => null];
    if (!class_exists('ZipArchive')) {
        return $fail('This workspace is missing a component (php-zip) — contact whoever set up this workspace.');
    }
    if (!is_file($zipPath)) { return $fail('No file arrived — please choose the zip Next downloaded and try again.'); }
    if (filesize($zipPath) > SETUP_MAX_ZIP) {
        return $fail('That file is larger than ' . intdiv(SETUP_MAX_ZIP, 1024 * 1024) . ' MB — too large for a study setup here. Is it the right zip?');
    }
    $maxUnpacked = (int)($limits['unpacked'] ?? SETUP_MAX_UNPACKED);
    $result = setup_locked($destDir, (float)($limits['lock_wait'] ?? 20.0),
        fn() => setup_ingest_locked($zipPath, $destDir, $origName, $maxUnpacked));
    return $result ?? $fail('There is another upload of the study setup running — wait a moment and try again.');
}

/** The work of setup_ingest_upload(), with the lock held. */
function setup_ingest_locked(string $zipPath, string $destDir, string $origName, int $maxUnpacked): array {
    $fail = fn(string $msg) => ['ok' => false, 'message' => $msg, 'name' => null, 'tasks' => null];
    $notSetup = "That doesn't look like a study setup from Next. Upload the zip exactly as Next downloaded it (⋮ → Export).";
    $tooLarge = 'That zip is too large to be a study setup (more than ' . SETUP_MAX_ENTRIES . ' files, or more than '
              . intdiv(SETUP_MAX_UNPACKED, 1024 * 1024) . ' MB unpacked).';
    $cantSave = 'Could not save the study setup — the storage volume may be full or read-only.';
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
            // A zip64 entry can declare a size that reads back negative: summed
            // with the others it would slip the total under the cap.
            if ((int)$st['size'] < 0) { return $fail($notSetup); }
            $total += (int)$st['size'];
            if ($total > $maxUnpacked) { return $fail($tooLarge); }
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

        // An entry's bytes, exactly as many as it declared (a zip can lie),
        // read in pieces and handed to $sink. 'ok', or what went wrong:
        // 'bad' (the zip: unreadable, a size that is not true, a failed
        // checksum) or 'write' (the sink refused, i.e. the volume).
        $copy = function (array $st, callable $sink) use ($za): string {
            $h = @$za->getStream((string)$st['name']);
            if ($h === false) { return 'bad'; }
            $size = (int)$st['size']; $n = 0; $state = 'ok';
            $crc = hash_init('crc32b');
            while ($state === 'ok' && !feof($h)) {
                $chunk = @fread($h, 65536);
                if ($chunk === false) { $state = 'bad'; break; }
                if ($chunk === '') { break; }
                $n += strlen($chunk);
                if ($n > $size) { $state = 'bad'; break; }
                hash_update($crc, $chunk);
                if (!$sink($chunk)) { $state = 'write'; }
            }
            // The checksum is checked here: reading a stream to its end does
            // not report a mismatch, and a damaged download must not be stored.
            if ($state === 'ok' && ($n !== $size || hexdec(hash_final($crc)) !== (int)$st['crc'])) { $state = 'bad'; }
            fclose($h);
            return $state;
        };
        $readText = function (array $st) use ($copy): ?string {
            if ((int)$st['size'] > SETUP_MAX_JSON) { return null; }
            $text = '';
            return $copy($st, function (string $c) use (&$text) { $text .= $c; return true; }) === 'ok' ? $text : null;
        };
        $metaText = $readText($json['next-metadata.json']);
        $meta = $metaText === null ? null : json_decode($metaText, true);
        if (!is_array($meta) || !is_int($meta['format_version'] ?? null)) { return $fail($notSetup); }

        setup_rmtree($new);
        if (!@mkdir("$new/assets", 0755, true)) { return $fail($cantSave); }
        if (@file_put_contents("$new/next-metadata.json", $metaText) === false) { return $fail($cantSave); }
        // The crate and the warnings are optional, and kept only if readable.
        foreach (['ro-crate-metadata.json', 'export-warnings.json'] as $opt) {
            if (!isset($json[$opt])) { continue; }
            $text = $readText($json[$opt]);
            if ($text === null || !is_array(json_decode($text, true))) { continue; }
            if (@file_put_contents("$new/$opt", $text) === false) { return $fail($cantSave); }
        }
        $written = 0;
        foreach ($assets as $name => $st) {
            $out = @fopen("$new/assets/$name", 'wb');
            if ($out === false) { return $fail($cantSave); }
            $state = $copy($st, function (string $c) use ($out, &$written, $maxUnpacked) {
                $written += strlen($c);
                return $written <= $maxUnpacked && @fwrite($out, $c) === strlen($c);
            });
            fclose($out);
            if ($written > $maxUnpacked) { return $fail($tooLarge); }
            if ($state !== 'ok') { return $fail($state === 'write' ? $cantSave : $notSetup); }
        }
        // The uploaded file's name, for the record: no control characters, cut
        // on a character boundary, and dropped if it is not UTF-8 at all.
        $zipName = (string)preg_replace('/\p{C}/u', '', basename($origName));
        $zipName = preg_match('/^.{0,200}/us', $zipName, $m) === 1 ? $m[0] : '';
        if (!inst_write_json_atomic("$new/upload-meta.json", ['uploaded_at' => gmdate('c'), 'zip_name' => $zipName])) {
            return $fail($cantSave);
        }
        // Swap the finished directory in; the old one goes only once the new one is in place.
        $old = $destDir . '.old';
        setup_rmtree($old);
        if (is_dir($old)) { return $fail($cantSave); }
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

/** Remove the stored study setup. True when there was one and it is gone
 *  (false also when an upload is running and did not finish in time). */
function setup_remove(string $destDir, float $lockWait = 20.0): bool {
    return setup_locked($destDir, $lockWait, function () use ($destDir): bool {
        if (!is_dir($destDir)) { return false; }
        setup_rmtree($destDir);
        return !is_dir($destDir);
    }) ?? false;
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

/** The stored file behind asset.php?f=<name>: its path, or null unless the
 *  name is a plain one and that file is among the study setup's assets. */
function setup_asset_file(string $name): ?string {
    $dir = inst_paths()['setup'];
    if ($dir === null || !setup_asset_name_ok($name)) { return null; }
    $path = "$dir/assets/$name";
    return is_file($path) && !is_link($path) ? $path : null;
}

/** How a packaged file is sent. PDFs and images open in the browser; any
 *  other type is a download, so nothing uploaded is ever rendered as a page.
 *  @return array{type:string, inline:bool} */
function setup_asset_type(string $name): array {
    $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
              'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return isset($types[$ext]) ? ['type' => $types[$ext], 'inline' => true]
                               : ['type' => 'application/octet-stream', 'inline' => false];
}
