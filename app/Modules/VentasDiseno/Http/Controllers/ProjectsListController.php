<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Services\ProjectsListService;
use App\Shared\Http\Pagination;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SCRUM-690/691/692/693/694 (REQ-610→614, Batch D) — pantalla "Lista de Proyectos" del Epic CRM
 * (SCRUM-332). Ver memory/project_epic_crm_scrum332_batcha_cerrado.md para el mapeo completo del
 * epic. REQ-612 (navegación a Pipeline con highlight) y REQ-614 (+Nuevo Proyecto) son 100%
 * frontend — reusan el mecanismo de query params (?card=/?openNewProject=1) que ya existe en
 * PipelinePage, sin endpoint propio.
 */
final class ProjectsListController extends Controller
{
    use ParsesIdListQueryParam;

    /** @var list<string> */
    private const ALLOWED_SORTS = ['stage_changed_at', 'amount', 'stage'];

    /** @var array<string, string> RN4/RN5 — mismos valores que PipelineCard::stage / SalesProject::tag. */
    private const STAGE_LABELS = [
        'lead'     => 'Lead',
        'design'   => 'Diseño',
        'quote'    => 'Cotización',
        'proposal' => 'Propuesta',
        'approved' => 'Aprobado',
        'lost'     => 'Perdido',
    ];

    /** @var array<string, string> */
    private const TAG_LABELS = [
        'design' => 'Diseño',
        'quote'  => 'Cotización',
        'both'   => 'Diseño + Cotización',
    ];

    public function __construct(private readonly ProjectsListService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $filters    = $this->parseFilters($request);
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'stage_changed_at', 'desc');

        $paginator = $this->service->query($user, $filters)
            ->orderBy($pagination['sort_by'], $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        // RN6 (REQ-611): "X proyectos" (con los filtros aplicados, ya es meta.total) y "Y
        // proyectos en total" (mismo scope Mías/Equipo, ignorando search/stage/tag/owner_id) —
        // dos números distintos, no el mismo meta.total repetido. total_unfiltered SÍ respeta el
        // scope (nunca le muestra a un Vendedor cuántos proyectos tiene el resto del equipo).
        $totalInScope = $this->service->query($user, ['scope' => $filters['scope'] ?? 'own'])->count();

        return response()->json([
            'data'             => collect($paginator->items())
                ->map(fn (PipelineCard $c) => $this->service->formatRow($c))
                ->values(),
            'meta'             => Pagination::meta($paginator),
            'total_unfiltered' => $totalInScope,
            // RN4 (REQ-611): el filtro de Responsable solo tiene efecto real para
            // Líder/Gerencia — no tiene sentido pedir el listado completo del equipo
            // (y de paso filtrar información) para alguien que ya está limitado a lo suyo.
            'can_view_team'    => $canSeeTeam,
            'filters'          => ['owners' => $canSeeTeam ? $this->service->distinctOwners() : []],
        ]);
    }

    /** RN1/RN2 (REQ-613): mismos filtros que index(), sin paginar — exporta todo lo filtrado. */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $filters = $this->parseFilters($request);
        $cards   = $this->service->query($user, $filters)->orderBy('stage_changed_at', 'desc')->get();
        $filename = 'lista-proyectos-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($cards): void {
            $stream = fopen('php://output', 'w');
            assert($stream !== false);

            // UTF-8 BOM para que Excel abra bien los acentos (mismo patrón que
            // App\Modules\CRM\Http\Controllers\ReportsController::export()).
            fwrite($stream, "\xEF\xBB\xBF");

            // RN2 (REQ-613): EXACTAMENTE las 10 columnas visibles en la tabla — nada más.
            fputcsv($stream, [
                'Proyecto', 'Cliente', 'Etapa', 'Etiqueta', 'Responsable',
                'Valor', 'Superficie trabajada', 'Días en etapa', 'Fecha de entrega', 'Archivos',
            ]);

            foreach ($cards as $card) {
                $row = $this->service->formatRow($card);
                fputcsv($stream, [
                    $row['sales_project']['name'],
                    $row['master_client']['name'] ?? '—',
                    self::STAGE_LABELS[$row['stage']] ?? $row['stage'],
                    $row['sales_project']['tag'] !== null ? (self::TAG_LABELS[$row['sales_project']['tag']] ?? '') : '',
                    $row['owner']['name'],
                    $row['amount'] ?? '—',
                    $row['worked_area_m2'] ?? '—',
                    $row['days_in_stage'],
                    $row['next_delivery_date'] ?? '—',
                    $row['files_count'],
                ]);
            }

            fclose($stream);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** @return array{scope?: string, owner_id?: int, owner_ids?: list<int>, search?: string, stage?: string, tag?: string} */
    private function parseFilters(Request $request): array
    {
        $raw = $request->only(['scope', 'owner_id', 'search', 'stage', 'tag']);

        return array_filter([
            'scope'    => isset($raw['scope']) ? (string) $raw['scope'] : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados).
            'owner_ids' => $this->parseIdListParam($request, 'owner_ids'),
            'search'   => isset($raw['search']) ? (string) $raw['search'] : null,
            'stage'    => isset($raw['stage']) ? (string) $raw['stage'] : null,
            'tag'      => isset($raw['tag']) ? (string) $raw['tag'] : null,
        ], fn (mixed $v): bool => $v !== null);
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
