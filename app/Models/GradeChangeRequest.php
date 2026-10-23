<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeChangeRequest extends Model
{
    protected $fillable = [
        'grade_id',
        'period_field',
        'old_value',
        'old_is_ng',
        'new_value',
        'new_is_ng',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'review_remarks',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'old_is_ng' => 'boolean',
            'new_is_ng' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
