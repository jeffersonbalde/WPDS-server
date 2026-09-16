<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class RestoreDatabaseCommand extends Command
{
    protected $signature = 'wpds:restore {file : Path to a wpds:backup JSON file} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore academic-records tables from a wpds:backup JSON file. This replaces existing data.';

    public function handle(DatabaseBackupService $backups): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }

        $snapshot = json_decode((string) file_get_contents($file), true);

        if (! is_array($snapshot) || ! isset($snapshot['tables'])) {
            $this->error('This file does not look like a valid wpds:backup snapshot.');

            return self::FAILURE;
        }

        $this->warn('This will replace all current academic-records data with the contents of the backup.');
        $this->line('Generated at: '.($snapshot['generated_at'] ?? 'unknown'));

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        $backups->restore($snapshot);

        $this->info('Restore complete.');

        return self::SUCCESS;
    }
}
