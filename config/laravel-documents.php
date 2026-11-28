<?php

declare(strict_types=1);

return [

    'latest_snapshot_wins' => false,

    'coalescing' => [
        'enabled' => false,
        'record_target_events' => false,
        'connection' => null,
        'lease_seconds' => 300,
        'stage_retention_seconds' => 3600,
    ],

    'snapshot_cache' => [
        'store' => null,
        'lock_seconds' => 300,
        'lock_wait_seconds' => 30,
    ],

];
