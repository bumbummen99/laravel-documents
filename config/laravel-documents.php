<?php

declare(strict_types=1);

return [

    'latest_snapshot_wins' => false,

    'snapshot_cache' => [
        'store' => null,
        'lock_seconds' => 300,
        'lock_wait_seconds' => 30,
    ],

];
