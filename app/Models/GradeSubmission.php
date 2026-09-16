<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeSubmission extends Model
{
    /** @var list<string> */
    public const PERIODS = ['prelim', 'midterm', 'semi_final', 'final'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'released', 'returned'];

    protected $fillable = [
        'class_section_id',
        'period',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function periodLabel(string $period): string
    {
        return match ($period) {
            'prelim' => 'Prelim',
            'midterm' => 'Midterm',
            'semi_final' => 'Semi-Final',
            'final' => 'Final',
            default => ucfirst(str_replace('_', ' ', $period)),
        };
    }
}
