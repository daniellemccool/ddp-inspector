<?php
require_once __DIR__ . '/../src/Ddp.php';
require_once __DIR__ . '/../src/Stats.php';

eq(stats_canonical_video_id('https://www.tiktokv.com/share/video/7654562293757250829/'), '7654562293757250829', 'canonical tiktokv share');
eq(stats_canonical_video_id('https://www.tiktok.com/@user/video/7654562293757250829'), '7654562293757250829', 'canonical @user form');
eq(stats_canonical_video_id('https://vm.tiktok.com/abc123/'), null, 'short link not canonical');
eq(stats_canonical_video_id('not a url'), null, 'garbage not canonical');

eq(stats_parse_date('2026-07-05 11:53:55'), gmmktime(11,53,55,7,5,2026), 'plain date');
eq(stats_parse_date('2026-05-28 01:36:28 UTC'), gmmktime(1,36,28,5,28,2026), 'UTC-suffixed date');
eq(stats_parse_date('garbage'), null, 'bad date null');

$loaded = ddp_load_dir(__DIR__ . '/fixtures/ddp');
$t1 = $loaded['participants']['p1']['platforms']['tiktok']['tables'];

$wh = stats_section_summary($t1['tiktok_watch_history']);
eq($wh['count'], 3, 'watch count');
eq($wh['earliest'], gmmktime(23,24,12,1,30,2026), 'watch earliest');
eq($wh['latest'], gmmktime(11,53,55,7,5,2026), 'watch latest');

// video 7654562293757250829 appears in both watch and like -> deduped
eq(stats_unique_video_count($t1), 4, 'unique videos across watch/fav/like deduped');

// stats_participant_scope still expects the old {sections: ...} shape (deprecated;
// Task 4 rewrites Stats.php to consume platforms/tables directly).
$scope = stats_participant_scope(['sections' => $t1]);
eq($scope['sections']['tiktok_comments']['count'], 2, 'scope comments count');
eq(array_key_first($scope['sections']), 'tiktok_watch_history', 'scope ordered by DDP_SECTION_ORDER');
eq($scope['unique_videos'], 4, 'scope unique videos');

$noComments = ['sections' => ['tiktok_watch_history' => [['Date' => '2026-07-05 11:53:55', 'Link' => 'https://www.tiktokv.com/share/video/7654562293757250829/']]]];
$scNo = stats_participant_scope($noComments);
eq($scNo['sections']['tiktok_comments']['count'], 0, 'absent section still shown with count 0');
eq(count(array_intersect(DDP_SECTION_ORDER, array_keys($scNo['sections']))), 5, 'all five canonical sections present even when absent from data');
