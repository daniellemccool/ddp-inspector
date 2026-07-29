<?php
putenv('DDP_INSPECTOR_CONFIG=' . __DIR__ . '/config.test.php');
require_once __DIR__ . '/../src/bootstrap.php';

eq(analysis_tiktok_video_id('https://www.tiktokv.com/share/video/7654562293757250829/'), '7654562293757250829', 'canonical tiktokv share');
eq(analysis_tiktok_video_id('https://www.tiktok.com/@user/video/7654562293757250829'), '7654562293757250829', 'canonical @user form');
eq(analysis_tiktok_video_id('https://vm.tiktok.com/abc123/'), null, 'short link not canonical');
eq(analysis_tiktok_video_id('not a url'), null, 'garbage not canonical');

$links = analysis_row_links('tiktok', ['Link' => 'https://www.tiktokv.com/share/video/7654562293757250829/']);
eq(count($links), 1, 'tiktok row with canonical link gets one analysis link');
check(str_contains($links[0]['url'], 'transcript.php?vid=7654562293757250829'), 'link targets transcript page');
eq(analysis_row_links('instagram', ['Link' => 'https://www.tiktokv.com/share/video/7654562293757250829/']), [], 'non-tiktok platform gets no transcript link');
eq(analysis_row_links('tiktok', ['Comment' => 'hi']), [], 'row without entity id gets no link');

// transcript path resolution via legacy transcripts_dir (config.test.php)
$tp = analysis_transcript_paths('7654562293757250829');
check($tp['txt'] !== null && str_ends_with($tp['txt'], '/29/7654562293757250829.txt'), 'sharded txt path resolved');
$missing = analysis_transcript_paths('1111111111111111111');
eq($missing['txt'], null, 'missing transcript -> null txt');
