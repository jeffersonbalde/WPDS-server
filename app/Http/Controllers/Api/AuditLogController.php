<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only activity trail for IT maintenance and Admin / Stakeholder oversight.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with('user:id,name,email,role');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));

        return response()->json(
            $query->orderByDesc('id')->paginate($perPage)->appends($request->query())
        );
    }
}
