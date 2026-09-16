<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Registrar-facing management list: every announcement, drafts included.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Announcement::query()->with('author:id,name');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        if ($request->query('status') === 'published') {
            $query->where('is_published', true);
        } elseif ($request->query('status') === 'draft') {
            $query->where('is_published', false);
        }

        $query->orderByDesc('created_at');

        $summary = [
            'total' => Announcement::count(),
            'published' => Announcement::where('is_published', true)->count(),
            'draft' => Announcement::where('is_published', false)->count(),
        ];

        $wantsPagination = $request->has('page') || $request->has('per_page');
        if (! $wantsPagination) {
            return response()->json([
                'data' => $query->get(),
                'summary' => $summary,
            ]);
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => $summary,
        ]));
    }

    /**
     * Student dashboard feed: published announcements only, newest first.
     */
    public function feed(): JsonResponse
    {
        $announcements = Announcement::query()
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'title', 'body', 'published_at']);

        return response()->json(['data' => $announcements]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $data['created_by'] = $request->user()->id;

        if ($data['is_published']) {
            $data['published_at'] = now();
        }

        $announcement = Announcement::create($data);

        return response()->json($announcement->load('author:id,name'), 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $data = $this->validatedData($request);

        if ($data['is_published'] && $announcement->published_at === null) {
            $data['published_at'] = now();
        }

        $announcement->update($data);

        return response()->json($announcement->fresh()->load('author:id,name'));
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'is_published' => ['boolean'],
        ], [
            'title.required' => 'Title is required.',
            'body.required' => 'Message is required.',
        ]);

        $data['title'] = trim($data['title']);
        $data['body'] = trim($data['body']);
        $data['is_published'] = $data['is_published'] ?? false;

        return $data;
    }
}
