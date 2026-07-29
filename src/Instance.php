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
    if ($json === false) { return false; }
    if (@touch($tmp) === false) { return false; }
    if (!@chmod($tmp, $mode)) { @unlink($tmp); return false; }
    if (@file_put_contents($tmp, $json . "\n") === false) { @unlink($tmp); return false; }
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
