<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class NotificationController extends Controller
{
    // ── GET /api/notifications ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderByRaw('read_at IS NOT NULL') // no leídas primero
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Notification $n) => $this->format($n))
                ->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // ── GET /api/notifications/unread-count ────────────────────────────────

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $count = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    // ── POST /api/notifications/{id}/read ──────────────────────────────────

    public function read(Request $request, int $id): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $notification = Notification::query()->where('user_id', $userId)->find($id);

        if ($notification === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($this->format($notification->refresh()));
    }

    // ── POST /api/notifications/read-all ───────────────────────────────────

    public function readAll(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'OK']);
    }

    private function currentUserId(Request $request): ?int
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        return isset($payload['sub']) ? (int) $payload['sub'] : null;
    }

    /** @return array<string, mixed> */
    private function format(Notification $n): array
    {
        return [
            'id'         => $n->id,
            'content'    => $n->content,
            'link_url'   => $n->link_url,
            'is_read'    => $n->isRead(),
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
