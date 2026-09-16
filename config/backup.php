<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup storage directory
    |--------------------------------------------------------------------------
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    |--------------------------------------------------------------------------
    | Retention (days). Files older than this are removed after each backup.
    | Set 0 to keep all files.
    |--------------------------------------------------------------------------
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Automatic backup schedule (requires: php artisan schedule:run)
    | On Windows, run schedule:run via Task Scheduler every minute.
    |--------------------------------------------------------------------------
    */
    'schedule_enabled' => filter_var(env('BACKUP_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

];
