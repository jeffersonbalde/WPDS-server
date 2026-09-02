<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramMajor extends Model
{
    protected $fillable = [
        'program_id',
        'code',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $appends = [
        'label',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getLabelAttribute(): string
    {
        $code = trim((string) $this->code);
        $name = trim((string) $this->name);

        if ($code !== '' && $name !== '') {
            return $code.' — '.$name;
        }

        return $name !== '' ? $name : $code;
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function isInUse(): bool
    {
        return $this->admissions()->exists() || $this->studentProfiles()->exists();
    }
}
