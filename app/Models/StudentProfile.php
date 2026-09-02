<?php

namespace App\Models;

use App\Enums\AcademicLevel;
use App\Support\StudentProfileData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'student_no',
        'academic_level',
        'program_id',
        'program_major_id',
        'year_level',
        'section',
        'last_name',
        'first_name',
        'middle_name',
        'date_of_birth',
        'place_of_birth',
        'gender',
        'civil_status',
        'address_line_1',
        'address_line_2',
        'mobile',
        'contact_email',
        'telephone',
        'ethnic_origin',
        'religion',
        'educational_background',
        'parents_guardian',
    ];

    protected function casts(): array
    {
        return [
            'academic_level' => AcademicLevel::class,
            'date_of_birth' => 'date:Y-m-d',
        ];
    }

    public function getEducationalBackgroundAttribute(mixed $value): array
    {
        return StudentProfileData::normalizeEducationalBackground($value);
    }

    public function setEducationalBackgroundAttribute(mixed $value): void
    {
        $this->attributes['educational_background'] = json_encode(
            StudentProfileData::normalizeEducationalBackground($value)
        );
    }

    public function getParentsGuardianAttribute(mixed $value): array
    {
        return StudentProfileData::normalizeParentsGuardian($value);
    }

    public function setParentsGuardianAttribute(mixed $value): void
    {
        $this->attributes['parents_guardian'] = json_encode(
            StudentProfileData::normalizeParentsGuardian($value)
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function programMajor(): BelongsTo
    {
        return $this->belongsTo(ProgramMajor::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function fullName(): string
    {
        return trim("{$this->last_name}, {$this->first_name} {$this->middle_name}");
    }
}
