<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Audit
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function write(
        ?Request $request,
        string $action,
        ?Model $auditable = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null,
    ): AuditLog {
        $actorId = $userId;
        if ($actorId === null && $request) {
            $actorId = $request->user()?->id;
        }

        return AuditLog::create([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
        ]);
    }
}
