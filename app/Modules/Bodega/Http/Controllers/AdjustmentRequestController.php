<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Exceptions\AdjustmentDuplicateConflictException;
use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\GeneralCountRequestLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\AdjustmentEvidenceService;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Bodega\Services\WarehouseStockService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use App\Shared\Http\Pagination;
use App\Shared\Support\AprobacionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SCRUM-428/429/430/446/447/448/449/450 (REQ-358/359/360/376/377/378/379/380) — "Solicitud de
 * ajuste": Bodega solicita (con motivo + evidencia, puede incluir varias bodegas a la vez) →
 * Mark aprueba o rechaza CADA línea por separado → aprobar ajusta el inventario automáticamente
 * vía `KardexService` (tipo Ajuste), rechazar no toca nada.
 *
 * REQ-427/431/432 (confirmar llegada física, modal de detalle de producto, deep-link) quedaron
 * fuera de este batch a propósito (decisión de Luis 2026-07-21) — se construyeron después en
 * Bloque B2 ("Ver Inventario" de Bodega), ver `App\Modules\Bodega\Http\Controllers\
 * BodegaInventoryController` y `App\Modules\Bodega\Services\ArrivalConfirmationService`.
 */
final class AdjustmentRequestController extends Controller
{
    public function __construct(
        private readonly AdjustmentEvidenceService $evidence,
        private readonly KardexService $kardex,
        private readonly FuzzySearchService $fuzzySearch,
        private readonly WarehouseStockService $warehouseStock,
    ) {}

    /**
     * SCRUM-428 (REQ-358 RN1/RN5) — cantidad actual por bodega para el selector del modal, y
     * unidades comprometidas (por_servir) para la advertencia al elegir "Restar". Hallazgo de
     * Pre-QA 2026-07-21 (MEDIO): faltaba esta data, el selector solo mostraba nombres.
     *
     * SCRUM-403 (REQ-333) — el cálculo en sí se extrajo a `WarehouseStockService` para que el
     * endpoint agregado por pedido ("Ver bodegas") lo reuse sin duplicar la query; el contrato de
     * esta respuesta no cambió.
     */
    public function productWarehouseStock(Request $request, int $productId): JsonResponse
    {
        $product = CatalogProduct::find($productId);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->warehouseStock->breakdownForProduct($productId));
    }

    /** SCRUM-428/446 (REQ-358/376) — buscador de producto para el selector de "Nueva solicitud". */
    public function searchProducts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return response()->json(['data' => []]);
        }

        $products = array_values(CatalogProduct::where('is_active', true)->get()->all());
        [$matches] = $this->fuzzySearch->search(
            $products,
            $search,
            fn (CatalogProduct $p): string => "{$p->reference} {$p->name}",
        );

        return response()->json([
            'data' => collect($matches)->take(10)->map(fn (CatalogProduct $p) => [
                'id' => $p->id, 'reference' => $p->reference, 'description' => $p->name,
            ])->values(),
        ]);
    }

    /** SCRUM-447 (REQ-377) — historial, una fila por línea de bodega. */
    public function index(Request $request): JsonResponse
    {
        $query = AdjustmentRequestLine::query()->with(['request.catalogProduct', 'request.solicitante', 'warehouse', 'resolvedBy']);

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '' && $estado !== 'todas') {
            $query->where('estado', $estado);
        }

        $pagination = Pagination::params($request, ['created_at'], 'created_at', 'desc');
        $paginator = $query
            ->orderBy('created_at', $pagination['sort_dir'])
            ->orderBy('id', $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AdjustmentRequestLine $l) => $this->formatLine($l))->values(),
            'meta' => Pagination::meta($paginator),
            // SCRUM-429 (rebote de Gerencia Test 2026-08-13) — mismo gap que SCRUM-463/466: el
            // frontend no tenía forma de saber quién es "Mark" (primary_approver_user_id es
            // puramente de backend), así que Aprobar/Rechazar quedaban visibles+clicables para
            // TODOS con un candado decorativo — el backend ya rechazaba (403) a quien no fuera
            // Mark, pero el botón seguía pareciendo funcional para Bodega. Se expone acá para que
            // el frontend oculte el botón por completo, no solo con un candado.
            'can_approve' => $this->canApprove($request),
        ]);
    }

    /** SCRUM-429/161 — misma condición que resolveMarkAccess(), como boolean informativo para el listado. */
    private function canApprove(Request $request): bool
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return false;
        }

        return app(AprobacionGate::class)->canAct($user, 'adjustment');
    }

    /**
     * SCRUM-428/429/446 (REQ-358/359/376) — crea la solicitud con N líneas de bodega.
     *
     * SCRUM-797 RN5→RN7 — al crear ES el único momento de "enviar a aprobación de Mark" de este
     * flujo (a diferencia de Inventario General, acá no hay borrador previo — ver diseño acordado
     * con Luis 2026-08-26), así que la advertencia (RN6) y la confirmación de reemplazo (RN7)
     * ocurren en el mismo request. Por cada línea (producto fijo de la solicitud + bodega de esa
     * línea) se chequean 2 tipos de colisión, ambos exigidos por el texto del ticket:
     *   - MISMO TIPO: otra `AdjustmentRequestLine` `Pendiente` para el mismo producto+bodega →
     *     si se confirma, esa línea se marca `Reemplazada` (con trazabilidad, RN7).
     *   - CRUZADO: un `GeneralCountRequest` sin resolver para esa bodega (cubre el producto por
     *     ser de bodega completa) → solo informativo, nunca se "reemplaza" un conteo completo por
     *     un ajuste puntual — es una advertencia distinta al mecanismo de cruce ya existente en
     *     `GeneralCountController::submit()` (SCRUM-464, unidireccional/DELETE, sin tocar).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'catalog_product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            // RN3 (REQ-358) — una bodega ya agregada no puede repetirse en la misma solicitud.
            // Hallazgo de Pre-QA 2026-07-21: el backend lo aceptaba vía API directa (la UI ya
            // lo impedía sacando la bodega del selector, pero el server-side no lo exigía).
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'distinct'],
            'lines.*.tipo' => ['required', 'string', 'in:Sumar,Restar'],
            'lines.*.cantidad' => ['required', 'integer', 'min:1'],
            // SCRUM-428 (rebote de Gerencia Test 2026-08-13) — Motivo pasa de texto libre a un
            // desplegable de 6 opciones fijas (ver AdjustmentRequestLine::MOTIVOS); descripcion/
            // responsable son nuevos, ambos opcionales — descripcion también sirve para detallar
            // cuando motivo = OTRO_ESPECIFICAR (el frontend decide si lo exige en ese caso, el
            // backend no fuerza esa dependencia entre campos).
            'lines.*.motivo' => ['required', 'string', Rule::in(AdjustmentRequestLine::MOTIVOS)],
            'lines.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'lines.*.responsable' => ['nullable', 'string', 'max:255'],
            'lines.*.evidencia' => ['required', 'file', 'max:20480', 'extensions:jpg,jpeg,png,gif,webp,pdf'],
            'confirm_replace' => ['nullable', 'boolean'],
        ]);
        $confirmReplace = (bool) ($validated['confirm_replace'] ?? false);

        // Chequeo temprano SIN lock, antes de subir ninguna evidencia — puro ahorro de trabajo
        // para el caso común (sin colisión real). NO es la fuente de verdad: dos requests
        // concurrentes pueden pasar este chequeo a la vez sin verse todavía. La fuente de verdad
        // real es el chequeo CON lock dentro de la transacción, más abajo.
        if (! $confirmReplace) {
            $duplicates = $this->findDuplicates((int) $validated['catalog_product_id'], $validated['lines']);
            if ($duplicates !== []) {
                return response()->json(['message' => 'Uno o más productos ya tienen un conteo o ajuste sin resolver.', 'duplicates' => $duplicates], 409);
            }
        }

        try {
            $adjustmentRequest = DB::transaction(function () use ($validated, $user, $confirmReplace): AdjustmentRequest {
                $adjustmentRequest = AdjustmentRequest::create([
                    'catalog_product_id' => $validated['catalog_product_id'],
                    'solicitado_por' => $user->id,
                ]);

                foreach ($validated['lines'] as $line) {
                    // Pre-QA (2026-08-26) — hallazgo real #1: `lockForUpdate()` sobre
                    // `AdjustmentRequestLine` más abajo solo bloquea una línea PENDIENTE que ya
                    // exista; si dos requests concurrentes arrancan el mismo producto+bodega antes
                    // de que exista ninguna línea todavía, ninguno de los dos SELECT ... FOR UPDATE
                    // encuentra nada que lockear y ambos avanzan a la vez. Se lockea la fila de
                    // `warehouses` de esta línea (que sí existe siempre) ANTES del chequeo,
                    // serializando cualquier par de requests concurrentes sobre el mismo
                    // producto+bodega — mismo criterio que `GeneralCountController::store()`/
                    // `submit()`.
                    Warehouse::where('id', $line['warehouse_id'])->lockForUpdate()->first();

                    $existingLine = AdjustmentRequestLine::query()
                        ->where('warehouse_id', $line['warehouse_id'])
                        ->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)
                        ->whereHas('request', fn ($q) => $q->where('catalog_product_id', $validated['catalog_product_id']))
                        ->lockForUpdate()
                        ->first();

                    // Pre-QA (2026-08-26) — hallazgo real #2: aun con el lock de arriba, esta
                    // rama antes reemplazaba `$existingLine` SIEMPRE que existiera, sin volver a
                    // mirar `$confirmReplace` — el chequeo "sin confirmar" de arriba solo corría
                    // ANTES de la transacción, así que 2 requests concurrentes sin confirmar
                    // (ninguno vio al otro en el chequeo temprano) terminaban, una vez serializados
                    // por el lock, "confirmando" el reemplazo el uno del otro sin que el usuario
                    // hubiera confirmado nada — confirmado en vivo con 2 requests simultáneos: dos
                    // 201, una línea marcada Reemplazada automáticamente. Ahora, si el lock revela
                    // una colisión real y el actor no confirmó, se aborta (excepción → rollback
                    // real de TODO lo de esta transacción, no solo de esta línea) en vez de
                    // reemplazar en silencio.
                    if ($existingLine !== null && ! $confirmReplace) {
                        throw new AdjustmentDuplicateConflictException([[
                            'warehouse_id' => $line['warehouse_id'],
                            'warehouse_name' => $existingLine->warehouse?->name,
                            'adjustment' => ['id' => $existingLine->id, 'fecha' => $existingLine->created_at->toIso8601String()],
                            'general_count' => null,
                        ]]);
                    }

                    $storageKey = $this->evidence->upload($line['evidencia'], $adjustmentRequest->id);

                    $newLine = AdjustmentRequestLine::create([
                        'adjustment_request_id' => $adjustmentRequest->id,
                        'warehouse_id' => $line['warehouse_id'],
                        'tipo' => $line['tipo'],
                        'cantidad' => $line['cantidad'],
                        'motivo' => $line['motivo'],
                        'descripcion' => $line['descripcion'] ?? null,
                        'responsable' => $line['responsable'] ?? null,
                        'evidencia_storage_key' => $storageKey,
                        'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
                    ]);

                    if ($existingLine !== null) {
                        $existingLine->update([
                            'estado' => AdjustmentRequestLine::ESTADO_REEMPLAZADA,
                            'replaced_by_id' => $newLine->id,
                        ]);
                    }
                }

                return $adjustmentRequest;
            });
        } catch (AdjustmentDuplicateConflictException $e) {
            return response()->json(['message' => $e->getMessage(), 'duplicates' => $e->duplicates()], 409);
        }

        $adjustmentRequest->load('lines');

        return response()->json(['id' => $adjustmentRequest->id, 'lines' => $adjustmentRequest->lines->pluck('id')], 201);
    }

    /**
     * @param  list<array{warehouse_id: int, tipo?: mixed, cantidad?: mixed}>  $lines
     * @return list<array<string, mixed>>
     */
    private function findDuplicates(int $catalogProductId, array $lines): array
    {
        $duplicates = [];

        foreach ($lines as $line) {
            $warehouseId = (int) $line['warehouse_id'];

            $sameType = AdjustmentRequestLine::query()
                ->where('warehouse_id', $warehouseId)
                ->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)
                ->whereHas('request', fn ($q) => $q->where('catalog_product_id', $catalogProductId))
                ->with('warehouse')
                ->first();

            // SCRUM-797 (rebote de Gerencia Test 2026-08-27) — el cruce contra Inventario General
            // dejó de ser "¿esta bodega tiene CUALQUIER conteo general sin resolver?" (bloqueaba
            // un ajuste de cualquier producto por un conflicto ajeno en la misma bodega) y pasa a
            // ser por producto: solo bloquea si ESTE producto puntual ya tiene una línea evaluada
            // con diferencia real sin resolver — mismo criterio que
            // `GeneralCountController::findConflictingLines()`.
            $crossType = GeneralCountRequestLine::query()
                ->where('catalog_product_id', $catalogProductId)
                ->whereNull('replaced_by_id')
                ->whereNotNull('diferencia')
                ->where('diferencia', '!=', 0)
                ->whereHas('request', fn ($q) => $q->where('warehouse_id', $warehouseId)
                    ->whereIn('estado', [GeneralCountRequest::ESTADO_EVALUADO, GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]))
                ->with('request.warehouse')
                ->first();

            if ($sameType === null && $crossType === null) {
                continue;
            }

            $warehouseName = $sameType !== null ? $sameType->warehouse?->name : $crossType->request->warehouse?->name;

            $duplicates[] = [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'adjustment' => $sameType !== null ? [
                    'id' => $sameType->id, 'fecha' => $sameType->created_at->toIso8601String(),
                ] : null,
                'general_count' => $crossType !== null ? [
                    'id' => $crossType->general_count_request_id, 'fecha' => $crossType->created_at->toIso8601String(), 'estado' => $crossType->request->estado,
                ] : null,
            ];
        }

        return $duplicates;
    }

    /**
     * SCRUM-448/449 (REQ-378/379 Escenario 1) — Mark aprueba, ajusta el inventario real.
     *
     * Hallazgo de Pre-QA 2026-07-21 (subagente real, CRÍTICO): el fetch + chequeo de
     * `isPending()` vivía ANTES de abrir la transacción, sin lock — 5 approve concurrentes
     * sobre la misma línea aplicaban el ajuste 3 veces (stock triplicado). El lock
     * (`lockForUpdate()`) y el chequeo de estado ahora viven DENTRO de la misma transacción:
     * la segunda request concurrente espera el lock, ve `estado = Aprobada` ya escrito por la
     * primera, y devuelve 422 en vez de volver a aplicar el ajuste.
     */
    public function approve(Request $request, int $lineId): JsonResponse
    {
        $access = $this->resolveMarkAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $result = DB::transaction(function () use ($lineId, $user): array {
            /** @var AdjustmentRequestLine|null $line */
            $line = AdjustmentRequestLine::with('request')->lockForUpdate()->find($lineId);
            if ($line === null) {
                return ['status' => 404];
            }
            if (! $line->isPending()) {
                return ['status' => 422];
            }

            $signedQuantity = $line->tipo === AdjustmentRequestLine::TIPO_SUMAR ? $line->cantidad : -$line->cantidad;

            $this->kardex->registrar(
                catalogProductId: $line->request->catalog_product_id,
                warehouseId: $line->warehouse_id,
                tipo: InventoryMovement::TIPO_AJUSTE,
                cantidad: $signedQuantity,
                referenciaTexto: "Ajuste aprobado — {$line->motivo}",
                referenciaEntidadTipo: 'SOLICITUD_AJUSTE_LINEA',
                referenciaEntidadId: $line->id,
                realizadoPor: $user->id,
            );

            $line->update([
                'estado' => AdjustmentRequestLine::ESTADO_APROBADA,
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'line' => $line];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 422);
        }

        /** @var AdjustmentRequestLine $line */
        $line = $result['line'];
        /** @var AdjustmentRequestLine $fresh */
        $fresh = $line->fresh(['request.catalogProduct', 'request.solicitante', 'warehouse', 'resolvedBy']);

        return response()->json($this->formatLine($fresh));
    }

    /**
     * SCRUM-448 (REQ-378 RN1/Escenario 1/2) — Mark rechaza, motivo obligatorio, no toca
     * inventario. Mismo fix de concurrencia que `approve()` — lock + chequeo de estado dentro
     * de la transacción.
     */
    public function reject(Request $request, int $lineId): JsonResponse
    {
        $access = $this->resolveMarkAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $validated = $request->validate(['motivo_rechazo' => ['required', 'string', 'min:1']]);

        $result = DB::transaction(function () use ($lineId, $user, $validated): array {
            /** @var AdjustmentRequestLine|null $line */
            $line = AdjustmentRequestLine::lockForUpdate()->find($lineId);
            if ($line === null) {
                return ['status' => 404];
            }
            if (! $line->isPending()) {
                return ['status' => 422];
            }

            $line->update([
                'estado' => AdjustmentRequestLine::ESTADO_RECHAZADA,
                'motivo_rechazo' => $validated['motivo_rechazo'],
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'line' => $line];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 422);
        }

        /** @var AdjustmentRequestLine $line */
        $line = $result['line'];
        /** @var AdjustmentRequestLine $fresh */
        $fresh = $line->fresh(['request.catalogProduct', 'request.solicitante', 'warehouse', 'resolvedBy']);

        return response()->json($this->formatLine($fresh));
    }

    /**
     * SCRUM-430 (REQ-360) — el aprobador es "Mark", mismo `primary_approver_user_id` configurable
     * ya usado por el gate de aprobación de órdenes de Compras (SCRUM-206) — una sola fuente de
     * verdad de quién es Mark, no una config paralela por módulo.
     *
     * SCRUM-161/162 (2026-08-26): delega en `AprobacionGate`, que además de Mark acepta cualquier
     * usuario con una `ReglaAprobacion` activa para tipo=adjustment (SCRUM-163) — antes ese
     * mecanismo solo se consultaba en Gerencia (de forma incorrecta, sin filtrar por tipo) y
     * nunca acá, así que un aprobador designado por regla para este tipo específico igual recibía
     * 403 al intentar ejecutar la acción real.
     *
     * @return array{0: User}|JsonResponse
     */
    private function resolveMarkAccess(Request $request): array|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! app(AprobacionGate::class)->canAct($user, 'adjustment')) {
            return response()->json(['message' => 'No tienes permiso para aprobar o rechazar esta solicitud.'], 403);
        }

        return [$user];
    }

    /** @return array<string, mixed> */
    private function formatLine(AdjustmentRequestLine $line): array
    {
        return [
            'id' => $line->id,
            'producto' => [
                'id' => $line->request->catalogProduct?->id,
                'reference' => $line->request->catalogProduct?->reference,
                'description' => $line->request->catalogProduct?->name,
            ],
            'bodega' => $line->warehouse?->name,
            'tipo' => $line->tipo,
            'cantidad' => $line->cantidad,
            'motivo' => $line->motivo,
            'descripcion' => $line->descripcion,
            'responsable' => $line->responsable,
            'evidencia_url' => $line->evidencia_storage_key !== null ? $this->evidence->presignUrl($line->evidencia_storage_key) : null,
            'solicitado_por' => $line->request->solicitante !== null
                ? trim("{$line->request->solicitante->first_name} {$line->request->solicitante->last_name}") : null,
            'fecha' => $line->created_at->toIso8601String(),
            'estado' => $line->estado,
            'motivo_rechazo' => $line->motivo_rechazo,
            'resuelto_por' => $line->resolvedBy !== null
                ? trim("{$line->resolvedBy->first_name} {$line->resolvedBy->last_name}") : null,
        ];
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
