<?php

namespace App\Models;

use App\Enums\AcademicLevel;
use App\Services\GradeCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    protected $fillable = [
        'enrollment_subject_id',
        'prelim',
        'prelim_ng',
        'midterm',
        'midterm_ng',
        'semi_final',
        'semi_final_ng',
        'final',
        'final_ng',
        'final_grade',
        'final_grade_ng',
        'remarks',
        'is_locked',
        'encoded_by',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'prelim' => 'decimal:2',
            'midterm' => 'decimal:2',
            'semi_final' => 'decimal:2',
            'final' => 'decimal:2',
            'final_grade' => 'decimal:2',
            'prelim_ng' => 'boolean',
            'midterm_ng' => 'boolean',
            'semi_final_ng' => 'boolean',
            'final_ng' => 'boolean',
            'final_grade_ng' => 'boolean',
            'is_locked' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * A period counts as recorded when it holds a numeric grade or "NG" (No Grade).
     */
    public function hasPeriodRecorded(string $period): bool
    {
        return $this->{$period} !== null || (bool) $this->{$period.'_ng'};
    }

    public function setPeriodNoGrade(string $period): void
    {
        $this->{$period} = null;
        $this->{$period.'_ng'} = true;
    }

    public function setPeriodValue(string $period, ?float $value): void
    {
        $this->{$period} = $value;
        $this->{$period.'_ng'} = false;
    }

    public function hasAnyNoGrade(): bool
    {
        foreach (GradeSubmission::PERIODS as $period) {
            if ($this->{$period.'_ng'}) {
                return true;
            }
        }

        return false;
    }

    public function enrollmentSubject(): BelongsTo
    {
        return $this->belongsTo(EnrollmentSubject::class);
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(GradeChangeRequest::class);
    }

    public function recalculate(GradeCalculator $calculator, AcademicLevel $level): void
    {
        // Any "NG" period makes the final grade "NG" and the remark FAILED.
        if ($this->hasAnyNoGrade()) {
            $this->final_grade = null;
            $this->final_grade_ng = true;
            $this->remarks = 'FAILED';

            return;
        }

        $this->final_grade_ng = false;

        $finalGrade = $calculator->computeFinalGrade(
            $this->prelim !== null ? (float) $this->prelim : null,
            $this->midterm !== null ? (float) $this->midterm : null,
            $this->semi_final !== null ? (float) $this->semi_final : null,
            $this->final !== null ? (float) $this->final : null,
        );

        $this->final_grade = $finalGrade;
        $this->remarks = $calculator->computeRemarks($finalGrade, $level);
    }
}
