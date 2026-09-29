<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditRetentionService;
use App\Support\Audit;
use App\Support\AuditSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Activity trail for IT maintenance and Admin / Stakeholder oversight.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, AuditRetentionService $retention): JsonResponse
    {
        $query = AuditLog::query()->with('user:id,name,email,role');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', 'like', '%'.$action.'%');
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));

        $paginator = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());
        $paginator->getCollection()->transform(function (AuditLog $log) {
            $row = $log->toArray();
            $row['user_name'] = AuditSummary::userDisplayName($log->user);
            $row['record_summary'] = AuditSummary::recordSummary($log);

            if (isset($row['user']['role'])) {
                $role = $row['user']['role'];
                if (is_array($role)) {
                    $row['user']['role'] = $role['value'] ?? null;
                } elseif (is_object($role)) {
                    $row['user']['role'] = $role->value ?? (string) $role;
                }
            }

            return $row;
        });

        $schedule = $retention->publicPayload();

        return response()->json(array_merge($paginator->toArray(), [
            'meta' => [
                'retention_days' => $schedule['retention_days'],
                'retention' => $schedule,
            ],
        ]));
    }

    public function showRetention(AuditRetentionService $retention): JsonResponse
    {
        return response()->json($retention->publicPayload());
    }

    public function updateRetention(Request $request, AuditRetentionService $retention): JsonResponse
    {
        $this->ensureIt($request);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'retention_days' => ['required', 'integer', Rule::in(AuditRetentionService::ALLOWED_DAYS)],
        ]);

        $saved = $retention->save($data);

        Audit::write($request, 'system.audit_retention_updated', null, null, $saved);

        return response()->json([
            'message' => $saved['enabled']
                ? 'Auto-delete schedule saved. Records older than '.$saved['label'].' will be removed daily.'
                : 'Auto-delete turned off. Old activity records will be kept until cleaned manually.',
            'retention' => $retention->publicPayload(),
        ]);
    }

    public function destroy(Request $request, AuditLog $auditLog): JsonResponse
    {
        $this->ensureIt($request);

        $auditLog->delete();

        return response()->json(['message' => 'Activity entry deleted.']);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $this->ensureIt($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:audit_logs,id'],
        ]);

        $deleted = AuditLog::query()->whereIn('id', $data['ids'])->delete();

        return response()->json([
            'message' => $deleted === 1
                ? '1 activity entry deleted.'
                : "{$deleted} activity entries deleted.",
            'deleted' => $deleted,
        ]);
    }

    public function prune(Request $request, AuditRetentionService $retention): JsonResponse
    {
        $this->ensureIt($request);

        $data = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(AuditRetentionService::ALLOWED_DAYS)],
        ]);

        $settings = $retention->get();
        $days = (int) ($data['days'] ?? $settings['retention_days']);
        $cutoff = now()->subDays($days);
        $deleted = AuditLog::query()->where('created_at', '<', $cutoff)->delete();

        $retention->markRan($deleted);

        Audit::write($request, 'system.audit_logs_pruned', null, null, [
            'days' => $days,
            'label' => $retention->labelForDays($days),
            'deleted' => $deleted,
            'manual' => true,
        ]);

        $label = $retention->labelForDays($days);

        return response()->json([
            'message' => $deleted === 0
                ? "No entries older than {$label}."
                : "Deleted {$deleted} entr".($deleted === 1 ? 'y' : 'ies')." older than {$label}.",
            'deleted' => $deleted,
            'days' => $days,
            'retention' => $retention->publicPayload(),
        ]);
    }

    private function ensureIt(Request $request): void
    {
        $role = $request->user()?->role;
        $value = $role instanceof \BackedEnum ? $role->value : (string) $role;
        abort_unless($value === 'it', 403, 'Only IT can manage activity logs.');
    }
}
