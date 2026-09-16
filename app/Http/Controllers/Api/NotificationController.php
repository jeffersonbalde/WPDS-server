<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->notifications();
        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));
        $paginator = $query->paginate($perPage)->appends($request->query());

        $paginator->getCollection()->transform(fn ($notification) => $this->present($notification));

        return response()->json(array_merge($paginator->toArray(), [
            'unread_count' => $user->unreadNotifications()->count(),
        ]));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'notification' => $this->present($notification->fresh()),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(mixed $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? 'system',
            'title' => $data['title'] ?? 'Notification',
            'body' => $data['body'] ?? '',
            'action_url' => $data['action_url'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
        ];
    }
}
