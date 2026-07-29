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
