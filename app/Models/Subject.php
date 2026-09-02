<?php

namespace App\Models;

use App\Enums\AcademicLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'code',
        'title',
        'units',
        'academic_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'academic_level' => AcademicLevel::class,
            'units' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function curriculumItems(): HasMany
    {
        return $this->hasMany(CurriculumItem::class);
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class);
    }
}
