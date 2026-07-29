<?php
require_once __DIR__ . '/../src/Ddp.php';
require_once __DIR__ . '/../src/Stats.php';

eq(stats_parse_date('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'plain date');
eq(stats_parse_date('2026-05-28 01:36:28 UTC'), gmmktime(1,36,28,5,28,2026), 'UTC-suffixed date');
eq(stats_parse_date('garbage'), null, 'bad date null');

eq(stats_parse_date_any('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'any: legacy format');
eq(stats_parse_date_any('2026-01-01T10:00:00'), gmmktime(10,0,0,1,1,2026), 'any: ISO no offset = UTC');
eq(stats_parse_date_any('2026-01-01T10:00:00+02:00'), gmmktime(8,0,0,1,1,2026), 'any: ISO with offset');
eq(stats_parse_date_any('2026-01-01T10:00:00.123Z'), gmmktime(10,0,0,1,1,2026), 'any: ISO fractional Z');
eq(stats_parse_date_any('nope'), null, 'any: garbage null');
eq(stats_parse_date_any('2026-02-30T10:00:00'), null, 'any: impossible ISO date rejected');
eq(stats_parse_date_any('2026-01-01T25:00:00'), null, 'any: impossible ISO time rejected');

eq(stats_row_date(['Timestamp' => '2026-01-01T10:00:00']), gmmktime(10,0,0,1,1,2026), 'row date via Timestamp');
eq(stats_row_date(['time' => '2026-01-01T10:00:00', 'Date' => 'garbage']), gmmktime(10,0,0,1,1,2026), 'row date skips unparseable, tries next column');
eq(stats_row_date(['Other' => 'x']), null, 'row date none');

$loaded = ddp_load_dir(__DIR__ . '/fixtures/ddp');
$t1 = $loaded['participants']['p1']['platforms']['tiktok']['tables'];
$wh = stats_section_summary($t1['tiktok_watch_history']);
eq($wh['count'], 3, 'watch count');
eq($wh['earliest'], gmmktime(23,24,12,1,30,2026), 'watch earliest');
eq($wh['latest'], gmmktime(11,53,55,7,5,2026), 'watch latest');

$scope = stats_platform_scope($t1, array_keys($t1));
eq($scope['tables']['tiktok_comments']['count'], 2, 'scope comments count');
eq($scope['total_rows'] >= 5, true, 'scope totals rows');
$scope2 = stats_platform_scope($t1, ['tiktok_watch_history', 'absent_table']);
eq($scope2['tables']['absent_table']['count'], 0, 'ordered-but-absent table shown with 0');
eq(array_key_first($scope2['tables']), 'tiktok_watch_history', 'display order respected');
