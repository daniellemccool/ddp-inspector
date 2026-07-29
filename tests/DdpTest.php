<?php
require_once __DIR__ . '/../src/Ddp.php';

$dir = __DIR__ . '/fixtures/ddp';
$p1  = $dir . '/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json';

eq(ddp_participant_id_from_filename($p1), 'p1', 'participant id from filename');
eq(ddp_participant_id_from_filename('/x/no-segment.json'), 'no-segment', 'participant id falls back to stem');

$parsed = ddp_parse_file($p1);
eq(is_array($parsed), true, 'parse returns array for conforming file');
eq(count($parsed['tables']['tiktok_watch_history']), 3, 'watch history rows');
eq($parsed['tables']['tiktok_watch_history'][0]['Link'], 'https://www.tiktokv.com/share/video/7654562293757250829/', 'watch link');
eq(isset($parsed['tables']['deleted row count']), false, 'scalar keys not tables');
eq($parsed['deleted']['tiktok_watch_history'], 0, 'deleted count captured (zero)');

eq(ddp_parse_file($dir . '/assignment=1_task=1_participant=preview_source=tiktok_key=2-tiktok.json'), null, 'preview stub is skipped (null)');

$p2 = ddp_parse_file(__DIR__ . '/fixtures/ddp2/assignment=1_task=1_participant=p2_source=instagram_key=5-instagram.json');
eq($p2['deleted']['instagram_followers'], 2, 'nonzero deleted count captured');

$p3 = ddp_parse_file(__DIR__ . '/fixtures/ddp2/assignment=1_task=1_participant=p3_source=x_key=7-x.json');
eq($p3['deleted']['x_a'], 0, 'multi-table entry: ambiguous deleted count refused for x_a');
eq($p3['deleted']['x_b'], 0, 'multi-table entry: ambiguous deleted count refused for x_b');
eq(count($p3['tables']['x_a']), 1, 'multi-table entry: x_a rows still parsed');
eq(count($p3['tables']['x_b']), 1, 'multi-table entry: x_b rows still parsed');

$meta = ddp_file_meta('/x/assignment=406_task=954_participant=abc_source=instagram_key=1783300000002-instagram.json');
eq($meta['participant'], 'abc', 'meta participant');
eq($meta['source'], 'instagram', 'meta source');
eq($meta['key_millis'], 1783300000002, 'meta key millis');
$meta2 = ddp_file_meta('/x/no-segment.json');
eq($meta2['participant'], 'no-segment', 'meta falls back to stem');
eq($meta2['source'], 'unknown', 'meta source fallback');
eq($meta2['key_millis'], 0, 'meta millis fallback');

$loaded2 = ddp_load_dir(__DIR__ . '/fixtures/ddp2');
$p2p = $loaded2['participants']['p2']['platforms']['instagram'];
eq($p2p['key_millis'], 5, 'newest key wins for duplicate participant+source');
eq($p2p['tables']['instagram_followers'][0]['Account'], 'a', 'winning file rows used');
eq($p2p['superseded'], ['assignment=1_task=1_participant=p2_source=instagram_key=3-instagram.json'], 'older file listed as superseded');
$loaded = ddp_load_dir($dir);
eq(array_keys($loaded['participants']), ['p1'], 'only conforming participant loaded');
eq(count($loaded['skipped']), 1, 'one skipped file reported');
eq(count($loaded['participants']['p1']['platforms']['tiktok']['tables']['tiktok_comments']), 2, 'comments present under platform');
