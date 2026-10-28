<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Http\Pagination;
use Illuminate\Support\Collection;

/**
 * Grupo 5 — pantalla Cotizaciones (listado, REQ-079 a 085). Ver
 * memory/project_backlog_ventas_diseno_req.md para el detalle de la decisión
 * sobre el Estado del documento (REQ-083, sin botón "Enviar" separado).
 *
 * Trabaja con arrays planos (no Collection encadenada) a propósito — Larastan
 * termina infiriendo tipos de array demasiado angostos (`non-empty-array`)
 * después de encadenar `Collection::filter()`, por la invariancia de
 * genéricos de `Collection`, y eso rompe la firma de los métodos privados de
 * abajo sin ganar nada en legibilidad.
 */
final class QuoteListService
{
    private const DETAIL_RELATIONS = ['masterClient', 'subClient', 'salesProject', 'owner', 'parts.items'];

    public function __construct(private readonly FuzzySearchService $fuzzySearch) {}

    /**
     * SCRUM-796 (secc. 9, RN1.1/RN1.2) — 2 decisiones explícitas de Luis para este ticket,
     * documentadas acá para que no se reabran sin querer: (1) el folio sigue asignándose en
     * `QuoteService::generate()` ("Guardar y generar cotización"), nunca en el primer
     * `saveDraft()` — un Borrador nunca quema folio, evita huecos por borradores abandonados.
     * (2) la agrupación de versiones sigue usando `sales_project_id` como proxy de
     * "tarjeta_pipeline_id" — no se agrega una columna `pipeline_card_id` nueva, porque el
     * invariante "1 proyecto = 1 sola PipelineCard" ya está garantizado en código
     * (`QuoteService::resolveLinkedCardForConfirm()`), así que ambos son equivalentes.
     *
     * SCRUM-796 (secc. 10, RN1.3 — CORRIGE SCRUM-734): antes, `data` mostraba una fila por
     * CADA `Quote` (cada versión confirmada, cada borrador). El ticket es explícito en que esa
     * definición anterior de PM quedó incorrecta — la lista principal debe mostrar una sola
     * fila por proyecto, con la versión CONFIRMADA más reciente (ver `dedupeForMainList()`).
     * Los Borradores NO son "versiones" en el sentido del ticket (RN2.3: una versión es una
     * cotización ya generada/confirmada) y REQ-082 sigue exigiendo verlos todos en la lista —
     * así que nunca se agrupan entre sí ni con una confirmada, cada uno sigue siendo su propia
     * fila. El dedupe corre ANTES del filtro de etapa y de la búsqueda: buscar el folio de una
     * versión vieja (no la más reciente) de un proyecto ya no la trae en `data` — sigue
     * disponible desde el detalle/historial de versiones (CA3).
     *
     * @param array{scope?: string, owner_id?: int, owner_ids?: list<int>, stage?: string, search?: string, view?: string} $filters
     * @param array{per_page: int, page: int} $pagination SCRUM-754 — summary se calcula sobre
     *   TODO el dataset filtrado (antes de paginar); data trae solo la página pedida.
     * @return array{
     *     summary: array<string, mixed>,
     *     fuzzy: bool,
     *     data: list<array<string, mixed>>,
     *     meta: array{total: int, per_page: int, current_page: int, last_page: int},
     * }
     */
    public function list(User $user, array $filters = [], array $pagination = ['per_page' => 20, 'page' => 1]): array
    {
        $scope = $this->resolveScope($user, $filters);

        $query = Quote::with(self::DETAIL_RELATIONS);

        // SCRUM-1195 — «de quién es» una cotización para este listado: la generó esa persona (`quotes.owner_id`) O es Responsable de su proyecto
        // (dueño de la tarjeta del Pipeline o en `compartido_con`) y la versión está CONFIRMADA. Gerencia suele crear la cotización y el vendedor/
        // diseñador es quien la trabaja: si se le asigna el proyecto, la ve aquí. Los borradores ajenos siguen privados (mismo criterio que
        // `QuoteController::canView`, SCRUM-1143).
        // Fuera del alcance de equipo los filtros por persona NO aplican: quien no ve al equipo solo ve lo suyo (antes un `owner_id`/`owner_ids`
        // ajeno por URL dejaba leer las cotizaciones y borradores de otra persona — Pre-QA SCRUM-1195, ALTO-1).
        $ownerIds = $scope !== 'team'
            ? [$user->id]
            : (! empty($filters['owner_ids']) ? $filters['owner_ids'] : (isset($filters['owner_id']) ? [$filters['owner_id']] : []));
        if ($ownerIds !== []) {
            $assignedProjectIds = PipelineCard::projectIdsOwnedByUserIds($ownerIds);
            $query->where(function ($q) use ($ownerIds, $assignedProjectIds): void {
                $q->whereIn('owner_id', $ownerIds);
                if ($assignedProjectIds !== []) {
                    $q->orWhere(fn ($w) => $w->whereNotNull('confirmed_at')->whereIn('sales_project_id', $assignedProjectIds));
                }
            });
        }

        /** @var Collection<int, Quote> $quotes */
        $quotes = $query->get();

        $projectIds = $quotes->pluck('sales_project_id')->filter()->unique()->values();
        $cardsByProject = PipelineCard::with('owner')->whereIn('sales_project_id', $projectIds)->get()->keyBy('sales_project_id');
        // SCRUM-1195 — Responsable de cada proyecto (dueño de la tarjeta): es lo que muestra la columna «Responsable», no quien generó la versión.
        $designerByProject = [];
        foreach ($cardsByProject as $projectId => $card) {
            $designerByProject[(int) $projectId] = ['id' => $card->owner->id, 'name' => trim("{$card->owner->first_name} {$card->owner->last_name}")];
        }

        /** @var list<array{quote: Quote, stage: string|null, document_status: string}> $rows */
        $rows = [];
        foreach ($quotes as $q) {
            $card = $q->sales_project_id !== null ? $cardsByProject->get($q->sales_project_id) : null;
            $rows[] = ['quote' => $q, 'stage' => $card?->stage, 'document_status' => $q->documentStatus($card)];
        }

        // SCRUM-796 (RN1.3) — 1 fila por proyecto en la tabla principal, antes de filtrar por
        // etapa o buscar (ver docblock de arriba y de dedupeForMainList()).
        $rows = $this->dedupeForMainList($rows);

        // SCRUM-912 (criterios 6/7) — vista "Solo Borradores": únicamente cotizaciones
        // NUEVAS sin guardar (creadas desde "+ Nueva cotización"), nunca los borradores de
        // "nueva versión" de un proyecto ya confirmado (ver filterToNewQuoteDrafts()).
        if (($filters['view'] ?? null) === 'drafts') {
            $rows = $this->filterToNewQuoteDrafts($rows);
        }

        if (! empty($filters['stage'])) {
            $stageFilter = $filters['stage'];
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['stage'] === $stageFilter));
        }

        $fuzzy = false;
        if (! empty($filters['search'])) {
            [$rows, $fuzzy] = $this->fuzzySearch->search(
                $rows,
                (string) $filters['search'],
                fn (array $r): string => $this->searchableText($r['quote']),
            );
        }

        $summary = $this->summary($rows);

        $paginator = Pagination::ofArray($rows, $pagination['per_page'], $pagination['page']);

        return [
            'summary' => $summary,
            'fuzzy'   => $fuzzy,
            'data'    => array_values(array_map(fn (array $r) => $this->formatRow($r, $designerByProject), $paginator->items())),
            'meta'    => Pagination::meta($paginator),
        ];
    }

    /**
     * REQ-133 (Compras, sprint2) — buscador de "Pedidos Aprobados" para asignar una línea de
     * orden de compra. A propósito NO reutiliza `list()`: esa lista aplica el alcance Mías/Equipo
     * de Ventas & Diseño (`canViewTeamV2('ventas_diseno')`), que un usuario de Compras (rol
     * `lider_compras`, sin visibilidad del módulo `ventas_diseno`) nunca tiene — Compras necesita
     * ver cualquier pedido aprobado de cualquier vendedor, no es "ver el equipo de Ventas &
     * Diseño", es una necesidad operativa distinta (ver docs/specs/
     * compras-proveedores-sprint2.md, hallazgo 4). Tampoco expone monto/pricing — solo lo
     * necesario para identificar el pedido, mismo criterio que "el costo nunca viaja al
     * buscador" (CatalogProductController).
     *
     * SCRUM-734 — dedupe por `sales_project_id` (mismo motivo que `summary()`, ver
     * su docblock): un proyecto Aprobado puede tener más de una cotización con
     * `documentStatus() === 'approved'` en su historial (todas las que se
     * confirmaron antes de llegar a Aprobado quedan con ese mismo estado
     * calculado). Se queda con la de `confirmed_at` más reciente — mismo criterio
     * que `OrderService::resolveApprovedQuote()` (Bodega) ya usa para resolver
     * "la" cotización de un proyecto Aprobado.
     *
     * SCRUM-818 — `$catalogProductId`/`$user` opcionales, usados por el picker de "Proyecto de
     * venta" de Comparación de Referencias (vía `ApprovedProjectController`). Cuando
     * `$catalogProductId` viene: (a) filtra a proyectos con una cotización aprobada que incluya
     * ese producto en alguna línea, y (b) si `$user` no tiene acceso completo a Compras
     * (superadmin.all/compras.read/compras.sustitutos.manage), scopea además a los proyectos de
     * los que `$user` es responsable (PipelineCard::projectIdsOwnedBy() — mismo criterio que
     * ReplacementRequestController). "Nueva Orden" nunca manda `$catalogProductId`, así que su
     * comportamiento queda 100% intacto (ninguna de las 2 ramas nuevas se activa).
     *
     * @param list<string> $jwtPermissions
     * @return list<array{sales_project_id: int, folio: string|null, project_name: string, client_name: string|null}>
     */
    public function searchApprovedProjects(string $search = '', ?int $catalogProductId = null, ?User $user = null, array $jwtPermissions = []): array
    {
        /** @var Collection<int, Quote> $quotes */
        $quotes = Quote::with(array_merge(['salesProject', 'subClient'], $catalogProductId !== null ? ['parts.items'] : []))
            ->whereNotNull('sales_project_id')
            ->whereNotNull('folio')
            ->get();

        if ($catalogProductId !== null) {
            $quotes = $quotes->filter(
                fn (Quote $q) => $q->parts->contains(
                    fn ($part) => $part->items->contains(fn ($item) => (int) $item->catalog_product_id === $catalogProductId),
                ),
            );
        }

        $ownedProjectIds = null;
        if ($catalogProductId !== null && $user !== null && ! $this->hasFullComprasAccess($jwtPermissions)) {
            $ownedProjectIds = PipelineCard::projectIdsOwnedBy($user);
        }

        $projectIds = $quotes->pluck('sales_project_id')->filter()->unique()->values();
        $cardsByProject = PipelineCard::whereIn('sales_project_id', $projectIds)->get()->keyBy('sales_project_id');

        /** @var array<int, array{sales_project_id: int, folio: string|null, project_name: string, client_name: string|null, confirmed_at_ts: int}> $latestApprovedByProject */
        $latestApprovedByProject = [];
        foreach ($quotes as $q) {
            $salesProject = $q->salesProject;
            $salesProjectId = $q->sales_project_id;
            if ($salesProject === null || $salesProjectId === null) {
                continue;
            }
            if ($ownedProjectIds !== null && ! in_array($salesProjectId, $ownedProjectIds, true)) {
                continue;
            }
            $card = $cardsByProject->get($salesProjectId);
            $documentStatus = $q->documentStatus($card);
            // SCRUM-818 (rebote 2026-09-03, Hallazgo 4) — SOLO para el picker filtrado por
            // producto (Comparación de Referencias, `$catalogProductId !== null`): antes exigía
            // 'approved' (etapa Propuesta/Aprobado en adelante), excluyendo proyectos que ya
            // tienen una cotización real confirmada pero siguen en etapa Cotización
            // ('sent'). Gerencia: "cualquier proyecto que ya tenga una cotización asociada debe
            // estar disponible... desde la etapa de Cotización en adelante". "Nueva Orden"
            // (`$catalogProductId === null`) es un picker DISTINTO y deliberadamente más
            // estricto ("Pedidos Aprobados", REQ-133) — nunca se toca, sigue exigiendo
            // 'approved' — ver test_solo_incluye_pedidos_aprobados.
            $isEligible = $catalogProductId !== null
                ? $documentStatus !== 'draft'
                : $documentStatus === 'approved';
            if (! $isEligible) {
                continue;
            }
            // confirmed_at no nulo está garantizado acá ('draft' es el único status con
            // confirmed_at null, y ambas ramas de arriba lo descartan), pero PHPStan no infiere
            // eso a través de la llamada — narrowing explícito.
            $confirmedAtTs = $q->confirmed_at !== null ? $q->confirmed_at->timestamp : 0;

            $current = $latestApprovedByProject[$salesProjectId] ?? null;
            if ($current === null || $confirmedAtTs > $current['confirmed_at_ts']) {
                $latestApprovedByProject[$salesProjectId] = [
                    'sales_project_id' => $salesProjectId,
                    'folio'            => $q->folio,
                    'project_name'     => $salesProject->name,
                    'client_name'      => $q->subClient?->business_name,
                    'confirmed_at_ts'  => $confirmedAtTs,
                ];
            }
        }

        /** @var list<array{sales_project_id: int, folio: string|null, project_name: string, client_name: string|null}> $rows */
        $rows = array_values(array_map(
            fn (array $r): array => [
                'sales_project_id' => $r['sales_project_id'],
                'folio'            => $r['folio'],
                'project_name'     => $r['project_name'],
                'client_name'      => $r['client_name'],
            ],
            $latestApprovedByProject,
        ));

        if ($search !== '') {
            [$rows] = $this->fuzzySearch->search(
                $rows,
                $search,
                fn (array $r): string => trim("{$r['folio']} {$r['project_name']} {$r['client_name']}"),
            );
        }

        return $rows;
    }

    /**
     * SCRUM-818 (rebote 2026-09-03, Hallazgo 2) — mismo criterio que
     * `ReplacementRequestController::hasFullComprasReadAccess()`: se evalúa contra el JWT
     * (`effectivePermissionsV2()`/`RoleModuleVisibility`), NUNCA `$user->hasPermission()` (v1) —
     * ver docblock de `canDecide()` en ese controller para el detalle completo de por qué. Antes
     * de este fix, Gerencia (Gerencia/Aprobador) y Compras (Lider Compras) tenían acceso real al módulo vía su
     * rol pero nunca el permiso v1 correspondiente, así que caían siempre a la rama de "proyectos
     * propios" — y como ninguno de los dos es dueño de proyectos de venta, veían "No existe
     * ningún proyecto que contenga este producto" sin importar el producto real.
     *
     * @param list<string> $jwtPermissions
     */
    private function hasFullComprasAccess(array $jwtPermissions): bool
    {
        return in_array('superadmin.all', $jwtPermissions, true)
            || in_array('compras.read', $jwtPermissions, true)
            || in_array('compras.sustitutos.manage', $jwtPermissions, true)
            || in_array('gerencia.view', $jwtPermissions, true);
    }

    /** @param array{scope?: string} $filters */
    private function resolveScope(User $user, array $filters): string
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');

        return ($filters['scope'] ?? 'own') === 'team' && $canSeeTeam ? 'team' : 'own';
    }

    private function searchableText(Quote $quote): string
    {
        return trim(implode(' ', array_filter([
            $quote->folio, $quote->masterClient?->name, $quote->subClient?->business_name, $quote->salesProject?->name,
        ])));
    }

    /**
     * REQ-080: tarjetas resumen, recalculadas sobre las cotizaciones que ya
     * pasaron el alcance y los filtros activos (etapa/búsqueda).
     *
     * Corrección 2026-07-16 (hallazgo Gerencia, SCRUM-102): antes se clasificaba
     * por `stage` crudo de PipelineCard, que no siempre coincide con la
     * columna "Estado" que ve el usuario (`document_status`, ver
     * `Quote::documentStatus()`) — una cotización "Enviada" con tarjeta sin
     * vincular o ya movida de etapa no contaba en "vigente", y "Aprobado"
     * incluía borradores ($0, sin folio) de proyectos ya en etapa Aprobado.
     * Ahora se clasifica por `document_status` (lo que el usuario realmente
     * ve en la tabla) y los borradores (nunca generados) se excluyen de las
     * 3 tarjetas monetarias.
     *
     * Corrección 2026-08-06 (Senior Review retroactivo de SCRUM-734, hallazgo
     * CRÍTICO real, reproducido en vivo): desde que `QuoteService::duplicate()`
     * permite varias cotizaciones CONFIRMADAS sobre el mismo proyecto (RN2.3,
     * "versiones"), sumar `grandTotal()` de cada fila duplicaba/triplicaba el
     * monto de un mismo proyecto acá — antes de este ticket 1 proyecto tenía
     * como mucho 1 cotización confirmada, así que nunca se notó. RN4.3 es
     * explícito: "Reportes/Inicio/Dashboard CRM siempre leen el campo Valor de
     * la tarjeta — nunca suman la tabla de cotizaciones". `$rows` sigue
     * mostrando cada versión como su propia fila en la tabla (RN1.3, sin
     * tocar acá), pero el resumen dedupe primero por `sales_project_id`,
     * quedándose con la versión CONFIRMADA más reciente de cada proyecto —
     * equivalente a leer `PipelineCard.amount` (que `confirm()` ya sobreescribe
     * con ese mismo total, nunca lo suma), sin necesitar pasar las tarjetas
     * hasta acá.
     *
     * @param list<array{quote: Quote, stage: string|null, document_status: string}> $rows
     * @return array{pending_amount: float, approved_amount: float, approved_count: int, lost_amount: float, lost_count: int, conversion_rate: int}
     */
    private function summary(array $rows): array
    {
        $pending = 0.0;
        $approved = 0.0;
        $approvedCount = 0;
        $lost = 0.0;
        $lostCount = 0;

        foreach ($this->latestVersionPerProject($rows) as $r) {
            $amount = $r['quote']->grandTotal();

            if ($r['document_status'] === 'approved') {
                $approved += $amount;
                $approvedCount++;
            } elseif ($r['stage'] === 'lost') {
                $lost += $amount;
                $lostCount++;
            } else {
                $pending += $amount;
            }
        }

        $decided = $approvedCount + $lostCount;

        return [
            'pending_amount'  => round($pending, 2),
            'approved_amount' => round($approved, 2),
            'approved_count'  => $approvedCount,
            'lost_amount'     => round($lost, 2),
            'lost_count'      => $lostCount,
            'conversion_rate' => $decided > 0 ? (int) round($approvedCount / $decided * 100) : 0,
        ];
    }

    /**
     * SCRUM-734 — filtra Borradores y dedupe por `sales_project_id`, quedándose
     * con la fila de `confirmed_at` más reciente de cada proyecto (misma
     * semántica que `OrdersService::buildOrders()` con
     * `unique('sales_project_id')` sobre `orderByDesc('generated_at')`, ver ahí
     * el comentario "1 Pedido por proyecto — la cotización generada más
     * reciente"). Un proyecto sin `sales_project_id` (no debería pasar para
     * cotizaciones confirmadas, pero por las dudas) nunca colapsa con otro.
     *
     * @param list<array{quote: Quote, stage: string|null, document_status: string}> $rows
     * @return list<array{quote: Quote, stage: string|null, document_status: string}>
     */
    private function latestVersionPerProject(array $rows): array
    {
        $byProject = [];

        foreach ($rows as $r) {
            if ($r['document_status'] === 'draft') {
                continue;
            }

            $key = $r['quote']->sales_project_id !== null
                ? 'project-' . $r['quote']->sales_project_id
                : 'quote-' . $r['quote']->id;

            $current = $byProject[$key] ?? null;
            if ($current === null || $this->confirmedMoreRecently($r['quote'], $current['quote'])) {
                $byProject[$key] = $r;
            }
        }

        return array_values($byProject);
    }

    /**
     * SCRUM-796 (RN1.3) — dedupe para la TABLA PRINCIPAL (`data`), a diferencia de
     * `latestVersionPerProject()` (que alimenta `summary()` y descarta Borradores por
     * completo): acá los Borradores se conservan todos, sin agrupar — solo las cotizaciones
     * CONFIRMADAS se dedupean por proyecto, quedándose con la más reciente (reusa
     * `latestVersionPerProject()` para decidir cuál gana). El orden de `$rows` se conserva tal
     * cual (array_filter no reordena), no se introduce ningún sort nuevo.
     *
     * @param list<array{quote: Quote, stage: string|null, document_status: string}> $rows
     * @return list<array{quote: Quote, stage: string|null, document_status: string}>
     */
    private function dedupeForMainList(array $rows): array
    {
        $winningQuoteIds = [];
        foreach ($this->latestVersionPerProject($rows) as $r) {
            $winningQuoteIds[$r['quote']->id] = true;
        }

        return array_values(array_filter(
            $rows,
            fn (array $r): bool => $r['document_status'] === 'draft' || isset($winningQuoteIds[$r['quote']->id]),
        ));
    }

    /**
     * SCRUM-912 (criterios 6/7) — "Solo Borradores" debe incluir ÚNICAMENTE cotizaciones
     * nuevas sin guardar (creadas desde "+ Nueva cotización"), nunca los borradores de
     * "nueva versión" de un proyecto ya confirmado (QuoteService::duplicate()). Se
     * distinguen sin ninguna columna nueva: `duplicate()` exige `$source->confirmed_at
     * !== null` para crear la versión, así que un borrador de nueva versión SIEMPRE
     * comparte `sales_project_id` con una cotización YA CONFIRMADA de ese mismo proyecto
     * — un borrador genuinamente nuevo nunca tiene ese hermano confirmado (incluido el
     * caso más común: `sales_project_id` todavía null, sin ningún proyecto elegido).
     *
     * La existencia del hermano confirmado se resuelve con una query directa a `Quote`
     * (no acotada por el scope/owner_id de $rows) porque puede pertenecer a otro dueño
     * (ej. Gerencia mirando "Equipo" vs el vendedor dueño del borrador).
     *
     * @param list<array{quote: Quote, stage: string|null, document_status: string}> $rows
     * @return list<array{quote: Quote, stage: string|null, document_status: string}>
     */
    private function filterToNewQuoteDrafts(array $rows): array
    {
        $draftRows = array_values(array_filter($rows, fn (array $r): bool => $r['document_status'] === 'draft'));

        $projectIds = array_values(array_unique(array_filter(
            array_map(fn (array $r): ?int => $r['quote']->sales_project_id, $draftRows),
        )));

        $projectsWithConfirmedSibling = $projectIds === []
            ? []
            : Quote::whereIn('sales_project_id', $projectIds)
                ->whereNotNull('confirmed_at')
                ->distinct()
                ->pluck('sales_project_id')
                ->all();

        return array_values(array_filter(
            $draftRows,
            fn (array $r): bool => $r['quote']->sales_project_id === null
                || ! in_array($r['quote']->sales_project_id, $projectsWithConfirmedSibling, true),
        ));
    }

    /** Precondición: ambas cotizaciones ya pasaron el filtro de Borrador (confirmed_at no nulo en la práctica). */
    private function confirmedMoreRecently(Quote $candidate, Quote $current): bool
    {
        $candidateTs = $candidate->confirmed_at !== null ? $candidate->confirmed_at->timestamp : 0;
        $currentTs   = $current->confirmed_at !== null ? $current->confirmed_at->timestamp : 0;

        return $candidateTs > $currentTs;
    }

    /**
     * @param array{quote: Quote, stage: string|null, document_status: string} $r
     * @param array<int, array{id: int, name: string}> $designerByProject Responsable (dueño de la tarjeta) por proyecto — SCRUM-1195.
     * @return array<string, mixed>
     */
    private function formatRow(array $r, array $designerByProject = []): array
    {
        $q = $r['quote'];

        return [
            'id'              => $q->id,
            'folio'           => $q->folio,
            'master_client'   => $q->masterClient !== null ? ['id' => $q->masterClient->id, 'name' => $q->masterClient->name] : null,
            'sub_client'      => $q->subClient !== null ? ['id' => $q->subClient->id, 'business_name' => $q->subClient->business_name] : null,
            'sales_project'   => $q->salesProject !== null ? ['id' => $q->salesProject->id, 'name' => $q->salesProject->name] : null,
            'amount'          => $q->grandTotal(),
            'stage'           => $r['stage'],
            'price_type'      => $q->price_type,
            'owner'           => ['id' => $q->owner->id, 'name' => trim("{$q->owner->first_name} {$q->owner->last_name}")],
            // SCRUM-1195 — Responsable del proyecto; sin tarjeta (borradores) cae a quien la generó. `owner` = quién generó esa fila.
            'designer'        => $q->sales_project_id !== null ? ($designerByProject[(int) $q->sales_project_id] ?? null) : null,
            'quote_date'      => ($q->generated_at ?? $q->created_at)->toIso8601String(),
            'document_status' => $r['document_status'],
        ];
    }
}
