<?php
putenv('DDP_INSPECTOR_CONFIG=' . __DIR__ . '/config.test.php');
require_once __DIR__ . '/../src/bootstrap.php';

eq(cfg('default_n'), 15, 'config default_n loaded');
eq(h('<a>&"'), '&lt;a&gt;&amp;&quot;', 'h() escapes');
eq(url('participant.php?id=x'), 'participant.php?id=x', 'url() with empty base_path');
eq(fmt_ts(null), '—', 'fmt_ts null');
eq(fmt_ts(gmmktime(11,53,55,7,5,2026)), '2026-07-05 11:53', 'fmt_ts formats UTC');
