<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'wpds:backup {--path= : Directory to write the backup file into (default: storage/app/backups)}';

    protected $description = 'Export academic-records tables to timestamped SQL (+ JSON) backup files.';

    public function handle(DatabaseBackupService $backups): int
    {
        $directory = $this->option('path') ?: null;
        $created = $backups->writeToDisk($directory);

        $this->info("SQL backup written to {$created['path']}");
        if (! empty($created['json_name'])) {
            $this->line('JSON companion: '.$created['json_name'].' (use with wpds:restore)');
        }

        return self::SUCCESS;
    }
}
