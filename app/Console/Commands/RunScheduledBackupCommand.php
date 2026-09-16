<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class RunScheduledBackupCommand extends Command
{
    protected $signature = 'wpds:backup-run-scheduled';

    protected $description = 'Create a backup when the IT-configured schedule is due.';

    public function handle(BackupScheduleService $schedule, DatabaseBackupService $backups): int
    {
        $created = $schedule->runIfDue($backups);

        if (! $created) {
            $this->line('No scheduled backup due.');

            return self::SUCCESS;
        }

        AuditLog::create([
            'user_id' => null,
            'action' => 'system.backup_scheduled',
            'auditable_type' => null,
            'auditable_id' => null,
            'new_values' => [
                'name' => $created['name'],
                'size' => $created['size'],
                'format' => 'sql',
            ],
            'ip_address' => null,
        ]);

        $this->info('Scheduled backup created: '.$created['name']);

        return self::SUCCESS;
    }
}
