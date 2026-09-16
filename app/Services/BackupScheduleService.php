<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Persists IT-configurable automatic backup schedule settings.
 */
class BackupScheduleService
{
    public function path(): string
    {
        return storage_path('app/backup-schedule.json');
    }

    /**
     * @return array{
     *   enabled: bool,
     *   frequency: string,
     *   time: string,
     *   weekday: int|null,
     *   retention_days: int,
     *   last_run_at: string|null,
     *   last_run_slot: string|null
     * }
     */
    public function get(): array
    {
        $defaults = $this->defaults();
        $path = $this->path();

        if (! is_file($path)) {
            return $defaults;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        return $this->normalize(array_merge($defaults, $decoded));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   enabled: bool,
     *   frequency: string,
     *   time: string,
     *   weekday: int|null,
     *   retention_days: int,
     *   last_run_at: string|null,
     *   last_run_slot: string|null
     * }
     */
    public function save(array $input): array
    {
        $current = $this->get();
        $merged = $this->normalize(array_merge($current, $input));
        // Preserve run tracking unless explicitly provided.
        $merged['last_run_at'] = array_key_exists('last_run_at', $input)
            ? $input['last_run_at']
            : $current['last_run_at'];
        $merged['last_run_slot'] = array_key_exists('last_run_slot', $input)
            ? $input['last_run_slot']
            : $current['last_run_slot'];

        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Keep Laravel config in sync for retention pruning.
        config(['backup.retention_days' => $merged['retention_days']]);

        return $merged;
    }

    public function markRan(string $slot, ?Carbon $at = null): void
    {
        $settings = $this->get();
        $settings['last_run_at'] = ($at ?: now())->toIso8601String();
        $settings['last_run_slot'] = $slot;
        $this->save($settings);
    }

    /**
     * Whether a scheduled backup should run right now.
     */
    public function isDue(?Carbon $now = null): bool
    {
        $settings = $this->get();
        if (! $settings['enabled']) {
            return false;
        }

        $now = ($now ?: now())->copy()->timezone(config('app.timezone', 'UTC'));
        $time = $settings['time'];

        if ($now->format('H:i') !== $time) {
            return false;
        }

        if ($settings['frequency'] === 'weekly') {
            $weekday = $settings['weekday'];
            if ($weekday === null || (int) $now->dayOfWeek !== (int) $weekday) {
                return false;
            }
        }

        $slot = $this->slotKey($now, $settings);
        if ($settings['last_run_slot'] === $slot) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{enabled: bool, frequency: string, time: string, weekday: int|null, retention_days: int, last_run_at: string|null, last_run_slot: string|null}  $settings
     */
    public function slotKey(Carbon $now, array $settings): string
    {
        if ($settings['frequency'] === 'weekly') {
            return $now->format('o-\WW').'-'.$settings['time'];
        }

        return $now->format('Y-m-d').'-'.$settings['time'];
    }

    /**
     * Human label + next estimated run (best-effort).
     *
     * @return array{label: string, next_run_at: string|null}
     */
    public function summary(?Carbon $now = null): array
    {
        $settings = $this->get();
        $now = ($now ?: now())->copy()->timezone(config('app.timezone', 'UTC'));

        if (! $settings['enabled']) {
            return ['label' => 'Disabled', 'next_run_at' => null];
        }

        $label = $settings['frequency'] === 'weekly'
            ? sprintf('Every %s at %s', $this->weekdayName((int) $settings['weekday']), $settings['time'])
            : sprintf('Daily at %s', $settings['time']);

        return [
            'label' => $label,
            'next_run_at' => $this->nextRunAt($settings, $now)?->toIso8601String(),
        ];
    }

    /**
     * @param  array{enabled: bool, frequency: string, time: string, weekday: int|null}  $settings
     */
    public function nextRunAt(array $settings, ?Carbon $now = null): ?Carbon
    {
        if (! $settings['enabled']) {
            return null;
        }

        $now = ($now ?: now())->copy()->timezone(config('app.timezone', 'UTC'));
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));

        if ($settings['frequency'] === 'weekly') {
            $weekday = (int) ($settings['weekday'] ?? 1);
            $candidate = $now->copy()->setTime($hour, $minute, 0);
            while ((int) $candidate->dayOfWeek !== $weekday || $candidate->lte($now)) {
                $candidate->addDay()->setTime($hour, $minute, 0);
            }

            return $candidate;
        }

        $candidate = $now->copy()->setTime($hour, $minute, 0);
        if ($candidate->lte($now)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Run backup if due. Returns created file meta or null.
     *
     * @return array<string, mixed>|null
     */
    public function runIfDue(DatabaseBackupService $backups, ?Carbon $now = null): ?array
    {
        $now = ($now ?: now())->copy()->timezone(config('app.timezone', 'UTC'));
        $settings = $this->get();

        if (! $this->isDue($now)) {
            return null;
        }

        config(['backup.retention_days' => $settings['retention_days']]);
        $created = $backups->writeToDisk();
        $this->markRan($this->slotKey($now, $settings), $now);

        return $created;
    }

    /**
     * @return array{
     *   enabled: bool,
     *   frequency: string,
     *   time: string,
     *   weekday: int|null,
     *   retention_days: int,
     *   last_run_at: string|null,
     *   last_run_slot: string|null
     * }
     */
    private function defaults(): array
    {
        return [
            'enabled' => (bool) config('backup.schedule_enabled', true),
            'frequency' => 'daily',
            'time' => (string) config('backup.schedule_time', '02:00'),
            'weekday' => 1,
            'retention_days' => (int) config('backup.retention_days', 30),
            'last_run_at' => null,
            'last_run_slot' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   enabled: bool,
     *   frequency: string,
     *   time: string,
     *   weekday: int|null,
     *   retention_days: int,
     *   last_run_at: string|null,
     *   last_run_slot: string|null
     * }
     */
    private function normalize(array $data): array
    {
        $frequency = in_array($data['frequency'] ?? 'daily', ['daily', 'weekly'], true)
            ? $data['frequency']
            : 'daily';

        $time = (string) ($data['time'] ?? '02:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '02:00';
        }

        $weekday = isset($data['weekday']) ? (int) $data['weekday'] : 1;
        if ($weekday < 0 || $weekday > 6) {
            $weekday = 1;
        }

        $retention = max(0, (int) ($data['retention_days'] ?? 30));

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'frequency' => $frequency,
            'time' => $time,
            'weekday' => $frequency === 'weekly' ? $weekday : null,
            'retention_days' => $retention,
            'last_run_at' => $data['last_run_at'] ?? null,
            'last_run_slot' => $data['last_run_slot'] ?? null,
        ];
    }

    private function weekdayName(int $weekday): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$weekday] ?? 'Monday';
    }
}
