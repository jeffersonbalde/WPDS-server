<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit log retention (days)
    |--------------------------------------------------------------------------
    |
    | Rows older than this are removed by `wpds:audit-logs-prune`.
    | Set to 0 to disable automatic pruning.
    |
    */
    'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 30),
];
