<?php
return [
    'storage_root' => getenv('DDP_TEST_STORAGE') ?: sys_get_temp_dir() . '/ddp-inspector-test',
    'default_n'    => 15,
    'base_path'    => '',
];
