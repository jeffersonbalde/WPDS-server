<?php

namespace App\Services;

/**
 * IT-configurable activity log auto-delete schedule.
 */
class AuditRetentionService
{
    /** @var list<int> */
    public const ALLOWED_DAYS = [7, 14, 30, 60, 90, 180, 365];

    public function path(): string
    {
        return storage_path('app/audit-retention.json');
    }

    /**
     * @return array{
     *   enabled: bool,
     *   retention_days: int,
     *   label: string,
     *   last_run_at: string|null,
     *   last_deleted: int|null
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
     *   retention_days: int,
     *   label: string,
     *   last_run_at: string|null,
     *   last_deleted: int|null
     * }
     */
    public function save(array $input): array
    {
        $current = $this->get();
        $merged = $this->normalize(array_merge($current, $input));

        // Preserve run stats unless explicitly provided.
        $merged['last_run_at'] = array_key_exists('last_run_at', $input)
            ? $input['last_run_at']
            : $current['last_run_at'];
        $merged['last_deleted'] = array_key_exists('last_deleted', $input)
            ? $input['last_deleted']
            : $current['last_deleted'];

        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $merged;
    }

    public function markRan(int $deleted): void
    {
        $settings = $this->get();
        $settings['last_run_at'] = now()->toIso8601String();
        $settings['last_deleted'] = $deleted;
        $this->save($settings);
    }

    /**
     * @return array{value: int, label: string}[]
     */
    public function options(): array
    {
        return array_map(fn (int $days) => [
            'value' => $days,
            'label' => $this->labelForDays($days),
        ], self::ALLOWED_DAYS);
    }

    /**
     * @return array{
     *   enabled: bool,
     *   retention_days: int,
     *   label: string,
     *   last_run_at: string|null,
     *   last_deleted: int|null,
     *   options: array{value: int, label: string}[]
     * }
     */
    public function publicPayload(): array
    {
        $settings = $this->get();

        return array_merge($settings, [
            'options' => $this->options(),
        ]);
    }

    public function labelForDays(int $days): string
    {
        return match ($days) {
            7 => '1 week',
            14 => '2 weeks',
            30 => '1 month',
            60 => '2 months',
            90 => '3 months',
            180 => '6 months',
            365 => '1 year',
            default => $days.' days',
        };
    }

    /**
     * @return array{
     *   enabled: bool,
     *   retention_days: int,
     *   label: string,
     *   last_run_at: string|null,
     *   last_deleted: int|null
     * }
     */
    private function defaults(): array
    {
        $days = (int) config('audit.retention_days', 30);
        if (! in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }

        return [
            'enabled' => $days > 0,
            'retention_days' => $days > 0 ? $days : 30,
            'label' => $this->labelForDays($days > 0 ? $days : 30),
            'last_run_at' => null,
            'last_deleted' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   enabled: bool,
     *   retention_days: int,
     *   label: string,
     *   last_run_at: string|null,
     *   last_deleted: int|null
     * }
     */
    private function normalize(array $data): array
    {
        $days = (int) ($data['retention_days'] ?? 30);
        if (! in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }

        return [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'retention_days' => $days,
            'label' => $this->labelForDays($days),
            'last_run_at' => $data['last_run_at'] ?? null,
            'last_deleted' => isset($data['last_deleted']) ? (int) $data['last_deleted'] : null,
        ];
    }
}
