<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditRetentionService;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'wpds:audit-logs-prune {--days= : Override retention days from the saved schedule} {--force : Run even if auto-delete is disabled}';

    protected $description = 'Delete activity log rows older than the IT-configured retention period.';

    public function handle(AuditRetentionService $retention): int
    {
        $settings = $retention->get();
        $override = $this->option('days');
        $force = (bool) $this->option('force');

        if ($override !== null && $override !== '') {
            $days = max(0, (int) $override);
        } else {
            if (! $settings['enabled'] && ! $force) {
                $this->line('Activity log auto-delete is disabled.');

                return self::SUCCESS;
            }
            $days = max(0, (int) $settings['retention_days']);
        }

        if ($days === 0) {
            $this->line('Activity log pruning skipped (retention_days = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $retention->markRan($deleted);

        $this->info(
            "Deleted {$deleted} activity log row(s) older than ".$retention->labelForDays($days).'.'
        );

        return self::SUCCESS;
    }
}
