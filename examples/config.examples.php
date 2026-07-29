<?php
// Config pointing at the synthetic example dataset. Use it without touching
// your real config.php:
//   DDP_INSPECTOR_CONFIG=examples/config.examples.php ./run-dev.sh
return [
    'ddp_dir'         => __DIR__ . '/ddp',
    'transcripts_dir' => __DIR__ . '/transcripts',
    'default_n'       => 15,
    'base_path'       => '',
];
