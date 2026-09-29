<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Resolves dashboard / overview date filters from query params.
 *
 * period: all | this_month | last_month | this_year | custom
 * custom uses date_from / date_to (YYYY-MM-DD, inclusive).
 */
final class DateRangeFilter
{
    public function __construct(
        public readonly string $period,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $period = strtolower(trim((string) $request->query('period', 'all')));
        if ($period === '') {
            $period = 'all';
        }

        $allowed = ['all', 'this_month', 'last_month', 'this_year', 'custom'];
        if (! in_array($period, $allowed, true)) {
            throw ValidationException::withMessages([
                'period' => 'Period must be one of: '.implode(', ', $allowed).'.',
            ]);
        }

        $rawFrom = $request->query('date_from');
        $rawTo = $request->query('date_to');
        // Backward-compatible aliases used elsewhere (Activity Log style).
        if ($rawFrom === null || $rawFrom === '') {
            $rawFrom = $request->query('from');
        }
        if ($rawTo === null || $rawTo === '') {
            $rawTo = $request->query('to');
        }

        $tz = config('app.timezone') ?: 'UTC';
        $now = Carbon::now($tz);

        $from = null;
        $to = null;

        switch ($period) {
            case 'this_month':
                $from = $now->copy()->startOfMonth()->toDateString();
                $to = $now->copy()->endOfMonth()->toDateString();
                break;
            case 'last_month':
                $last = $now->copy()->subMonthNoOverflow();
                $from = $last->copy()->startOfMonth()->toDateString();
                $to = $last->copy()->endOfMonth()->toDateString();
                break;
            case 'this_year':
                $from = $now->copy()->startOfYear()->toDateString();
                $to = $now->copy()->endOfYear()->toDateString();
                break;
            case 'custom':
                $from = self::parseDate($rawFrom, 'date_from');
                $to = self::parseDate($rawTo, 'date_to');
                if ($from === null && $to === null) {
                    throw ValidationException::withMessages([
                        'date_from' => 'Provide at least a start or end date for a custom range.',
                    ]);
                }
                break;
            case 'all':
            default:
                // Optional: allow from/to even on "all" if both provided (treat as custom).
                if (($rawFrom !== null && $rawFrom !== '') || ($rawTo !== null && $rawTo !== '')) {
                    $from = self::parseDate($rawFrom, 'date_from');
                    $to = self::parseDate($rawTo, 'date_to');
                    $period = 'custom';
                }
                break;
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages([
                'date_to' => 'End date must be on or after the start date.',
            ]);
        }

        return new self($period, $from, $to);
    }

    public function isActive(): bool
    {
        return $this->dateFrom !== null || $this->dateTo !== null;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, string $column = 'created_at'): Builder
    {
        if ($this->dateFrom !== null) {
            $query->whereDate($column, '>=', $this->dateFrom);
        }
        if ($this->dateTo !== null) {
            $query->whereDate($column, '<=', $this->dateTo);
        }

        return $query;
    }

    /**
     * @return array{period: string, date_from: ?string, date_to: ?string, active: bool, label: string}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'active' => $this->isActive(),
            'label' => $this->label(),
        ];
    }

    public function label(): string
    {
        if (! $this->isActive()) {
            return 'All time';
        }

        $from = $this->dateFrom;
        $to = $this->dateTo;

        return match ($this->period) {
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year' => 'This year',
            default => ($from && $to)
                ? "{$from} → {$to}"
                : ($from ? "From {$from}" : "Until {$to}"),
        };
    }

    private static function parseDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw ValidationException::withMessages([
                $field => 'Use date format YYYY-MM-DD.',
            ]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'Invalid date.',
            ]);
        }
    }
}
