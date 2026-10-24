<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\QuoteListService;
use App\Shared\Http\Pagination;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Grupo 5 — pantalla Cotizaciones (listado, REQ-079 a 085).
 */
final class QuoteListController extends Controller
{
    use ParsesIdListQueryParam;

    public function __construct(private readonly QuoteListService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'owner_id', 'stage', 'search', 'view']);
        $filters = array_filter([
            'scope'    => isset($raw['scope'])  ? (string) $raw['scope']  : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados).
            'owner_ids' => $this->parseIdListParam($request, 'owner_ids'),
            'stage'    => isset($raw['stage'])  ? (string) $raw['stage']  : null,
            'search'   => isset($raw['search']) ? (string) $raw['search'] : null,
            // SCRUM-912 (criterios 6/7) — 'drafts' pide la vista "Solo Borradores" en vez
            // del listado normal, ver QuoteListService::list()/filterToNewQuoteDrafts().
            'view'     => isset($raw['view']) && $raw['view'] === 'drafts' ? 'drafts' : null,
        ], fn (mixed $v): bool => $v !== null);

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'quote_date');

        return response()->json($this->service->list($user, $filters, [
            'per_page' => $pagination['per_page'],
            'page'     => $pagination['page'],
        ]));
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
}
