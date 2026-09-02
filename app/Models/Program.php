<?php

namespace App\Models;

use App\Enums\AcademicLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = [
        'code',
        'name',
        'academic_level',
        'track_type',
        'duration_years',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'academic_level' => AcademicLevel::class,
            'is_active' => 'boolean',
        ];
    }

    public function majors(): HasMany
    {
        return $this->hasMany(ProgramMajor::class);
    }

    public function curriculumItems(): HasMany
    {
        return $this->hasMany(CurriculumItem::class);
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function academicLevelValue(): string
    {
        return $this->academic_level instanceof AcademicLevel
            ? $this->academic_level->value
            : (string) $this->academic_level;
    }

    public function hasActiveMajors(): bool
    {
        if ($this->relationLoaded('majors')) {
            return $this->majors->contains(fn (ProgramMajor $major) => $major->is_active !== false);
        }

        return $this->majors()->where('is_active', true)->exists();
    }
}
