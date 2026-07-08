<?php
require_once __DIR__ . '/../src/Ddp.php';

$dir = __DIR__ . '/fixtures/ddp';
$p1  = $dir . '/assignment=1_task=1_participant=p1_source=tiktok_key=1-tiktok.json';

eq(ddp_participant_id_from_filename($p1), 'p1', 'participant id from filename');
eq(ddp_participant_id_from_filename('/x/no-segment.json'), 'no-segment', 'participant id falls back to stem');

$sections = ddp_parse_file($p1);
eq(is_array($sections), true, 'parse returns array for conforming file');
eq(count($sections['tiktok_watch_history']), 3, 'watch history rows');
eq($sections['tiktok_watch_history'][0]['Link'], 'https://www.tiktokv.com/share/video/7654562293757250829/', 'watch link');
eq(isset($sections['deleted row count']), false, 'non-array keys ignored');

eq(ddp_parse_file($dir . '/assignment=1_task=1_participant=preview_source=tiktok_key=2-tiktok.json'), null, 'preview stub is skipped (null)');

$loaded = ddp_load_dir($dir);
eq(array_keys($loaded['participants']), ['p1'], 'only conforming participant loaded');
eq(count($loaded['skipped']), 1, 'one skipped file reported');
eq($loaded['participants']['p1']['sections']['tiktok_comments'][1]['Comment'], 'second comment', 'comments merged in order');
