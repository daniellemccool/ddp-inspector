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

$loaded = ddp_load_dir($dir);
eq(array_keys($loaded['participants']), ['p1'], 'only conforming participant loaded');
eq(count($loaded['skipped']), 1, 'one skipped file reported');
eq($loaded['participants']['p1']['sections']['tiktok_comments'][1]['Comment'], 'second comment', 'comments merged in order');

$meta = ddp_file_meta('/x/assignment=406_task=954_participant=abc_source=instagram_key=1783300000002-instagram.json');
eq($meta['participant'], 'abc', 'meta participant');
eq($meta['source'], 'instagram', 'meta source');
eq($meta['key_millis'], 1783300000002, 'meta key millis');
$meta2 = ddp_file_meta('/x/no-segment.json');
eq($meta2['participant'], 'no-segment', 'meta falls back to stem');
eq($meta2['source'], 'unknown', 'meta source fallback');
eq($meta2['key_millis'], 0, 'meta millis fallback');
