<?php

namespace App\Models;

use App\Services\GradeCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    protected $fillable = [
        'enrollment_subject_id',
        'prelim',
        'midterm',
        'semi_final',
        'final',
        'final_grade',
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
            'is_locked' => 'boolean',
            'submitted_at' => 'datetime',
        ];
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

    public function recalculate(GradeCalculator $calculator, \App\Enums\AcademicLevel $level): void
    {
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
