<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Services\DirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class DirectoryController extends Controller
{
    public function __construct(private readonly DirectoryService $service) {}

    /**
     * GET /api/crm/directory?type={developer|arquitecto|encargado|contacto}&q={search}
     *
     * Returns aggregated directory entries grouped by the given project field.
     * Visibility follows the same rules as ProjectService.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(DirectoryService::allowedTypes())],
            'q'    => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->service->get(
            $user,
            $this->activeRole($request),
            $validated['type'],
            (string) ($validated['q'] ?? '')
        );

        return response()->json($result);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }

    private function activeRole(Request $request): string
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return isset($payload['role']) ? $payload['role'] : '';
    }
}
