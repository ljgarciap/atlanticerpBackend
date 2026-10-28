<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\GeneralCountRequestLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\Pagination;
use App\Shared\Support\AprobacionGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-460→466 (REQ-390→396, Bloque B5 "Conteos generales de inventario") — ver
 * ADR-SCRUM460-466-conteos-generales.md. Bodega inicia un conteo de TODA una bodega
 * (`store()`, precarga automática desde `ProductWarehouseStock`), evalúa diferencias
 * (`evaluate()`), envía a aprobación de Gerencia (`submit()`, que además limpia solicitudes de
 * ajuste cruzadas — REQ-394), y solo tras aprobar aplica el ajuste al Kardex (`apply()`,
 * SCRUM-466) — el primer bloque del proyecto donde "Gerencia aprueba" y "se aplica al inventario"
 * son dos pasos desacoplados (a diferencia de `AdjustmentRequestController`/
 * `RelocationRequestController`, donde aprobar = aplicar de inmediato).
 */
final class GeneralCountController extends Controller
{
    public function __construct(private readonly KardexService $kardex) {}

    /**
     * SCRUM-465 (REQ-395) — bandeja/historial, filtro por estado. RN3 exige la columna
     * "Diferencias encontradas" por fila (ver ADR + mockup `3H__Bodega_InventarioGeneral.html`,
     * que la puebla con un número real por fila) — se resuelve acá con un `withCount`
     * constreñido (no un N+1: una sola query agregada, igual que `lines_count`), en vez de dejar
     * la celda sin dato en el frontend (hallazgo de Pre-QA 2026-07-25, corregido en la misma
     * sesión).
     */
    public function index(Request $request): JsonResponse
    {
        $query = GeneralCountRequest::query()->with(['warehouse', 'realizadoPor', 'resolvedBy'])
            ->withCount('lines')
            ->withCount(['lines as diferencias_count' => fn ($q) => $q->where('diferencia', '!=', 0)]);

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
            'data' => collect($paginator->items())->map(fn (GeneralCountRequest $r) => $this->formatRequest($r))->values(),
            'meta' => Pagination::meta($paginator),
            // SCRUM-463 (rebote de Gerencia Test 2026-08-14) — mismo gap que SCRUM-466 (apply()):
            // el frontend no tenía forma de saber quién es "Gerencia" (primary_approver_user_id es
            // puramente de backend, sin claim en el JWT), así que Aprobar/Rechazar quedaban
            // visibles+clicables para TODOS con un candado decorativo — el backend ya rechazaba
            // (403) a quien no fuera Gerencia, pero el botón seguía pareciendo funcional. Se expone
            // acá para que el frontend oculte el botón por completo, no solo con un candado.
            'can_approve' => $this->canApprove($request),
        ]);
    }

    /**
     * SCRUM-461 (REQ-391) — detalle del conteo con sus líneas, incluyendo la señal de cruce con
     * una `AdjustmentRequestLine` pendiente sobre el mismo producto/bodega (el frontend la usa
     * para mostrar el aviso). Decisión de implementación: esta ruta GET /{id} no está en la
     * lista explícita de rutas del ADR (que solo lista GET /), pero SCRUM-461 exige el detalle
     * ("el GET del conteo (index/show)") — se agrega acá porque sin ella el frontend no tiene
     * forma de cargar las líneas de un conteo puntual para evaluarlo.
     */
    public function show(int $id): JsonResponse
    {
        $generalCountRequest = GeneralCountRequest::with(['warehouse', 'realizadoPor', 'resolvedBy', 'lines.catalogProduct'])->find($id);
        if ($generalCountRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($generalCountRequest));
    }

    /**
     * SCRUM-460 (REQ-390) — crea el conteo y precarga automáticamente una línea por cada
     * producto registrado en la bodega elegida. Decidido por Luis en el ADR: mismo criterio que
     * `WarehouseController::show()` — solo `ProductWarehouseStock` con `quantity > 0` entra al
     * conteo (un sobrante físico de un producto en 0 en el sistema queda fuera de este flujo).
     *
     * SCRUM-797 (rebote de Gerencia Test 2026-08-27) — la bodega elegida sigue siendo la única
     * "selección" real de este flujo (el conteo es siempre de TODA la bodega, precarga todos los
     * productos con stock — eso no cambió). Lo que sí cambió: el chequeo de duplicados dejó de ser
     * "¿esta bodega ya tiene CUALQUIER conteo sin resolver?" (bloqueaba toda la bodega por un solo
     * producto en disputa, decisión de Luis 2026-08-26 revertida al día siguiente) y pasa a ser
     * por producto — ver `findConflictingLines()`. Si uno o más de los productos que va a incluir
     * este conteo ya tienen una línea con diferencia real sin resolver en OTRO conteo de la misma
     * bodega, se devuelve 409 con la lista de esos productos puntuales; el resto de la bodega se
     * carga normal, sin bloqueo ni alerta. `confirm_replace: true` reemplaza únicamente esas
     * líneas puntuales (`replaced_by_id`), nunca el conteo completo.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Warehouse::existsRule()],
            'confirm_replace' => ['nullable', 'boolean'],
        ]);
        $confirmReplace = (bool) ($validated['confirm_replace'] ?? false);

        $result = DB::transaction(function () use ($validated, $user, $confirmReplace): array {
            // Pre-QA (2026-08-26) — hallazgo real: `lockForUpdate()` solo bloquea filas que YA
            // existen; si el conteo colisionante todavía no se creó (2 requests concurrentes),
            // un SELECT ... FOR UPDATE no encuentra nada que lockear y no bloquea el INSERT de la
            // otra transacción. Se lockea la fila de `warehouses` (que SÍ existe siempre, ya
            // validada arriba) antes de cualquier lectura/escritura de esta transacción —
            // serializa cualquier par de requests concurrentes sobre la misma bodega.
            Warehouse::where('id', $validated['warehouse_id'])->lockForUpdate()->first();

            $stockRows = ProductWarehouseStock::query()
                ->where('warehouse_id', $validated['warehouse_id'])
                ->where('quantity', '>', 0)
                ->get();
            /** @var list<int> $catalogProductIds */
            $catalogProductIds = array_values($stockRows->pluck('catalog_product_id')->all());

            $conflictingLines = $this->findConflictingLines((int) $validated['warehouse_id'], $catalogProductIds);
            if ($conflictingLines->isNotEmpty() && ! $confirmReplace) {
                return ['status' => 409, 'duplicates' => $this->formatDuplicates($conflictingLines)];
            }

            $generalCountRequest = GeneralCountRequest::create([
                'warehouse_id' => $validated['warehouse_id'],
                'realizado_por' => $user->id,
                'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
            ]);

            foreach ($stockRows as $stockRow) {
                $newLine = GeneralCountRequestLine::create([
                    'general_count_request_id' => $generalCountRequest->id,
                    'catalog_product_id' => $stockRow->catalog_product_id,
                    // SCRUM-460 (REQ-390) exige "cantidad en sistema" visible apenas se carga la
                    // tabla, no recien tras "Evaluar" (ver mockup: BODEGAS_CONTEO ya trae
                    // cantidadSistema poblado al cargar). evaluate() sigue re-tomando el snapshot
                    // FRESCO de ProductWarehouseStock en el momento de evaluar (sin cambios,
                    // ver su docblock) — este valor inicial es solo para el primer render.
                    'cantidad_sistema' => $stockRow->quantity,
                ]);

                // Reemplazo por línea (SCRUM-797) — solo el/los producto(s) que de verdad
                // colisionaban quedan marcados, el resto de las líneas nuevas queda "limpia".
                if ($conflictingLines->has($stockRow->catalog_product_id)) {
                    $this->markConflictingLinesReplaced((int) $validated['warehouse_id'], (int) $stockRow->catalog_product_id, $newLine->id);
                }
            }

            return ['status' => 201, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 409) {
            return response()->json(['message' => 'Uno o más productos ya tienen un conteo sin resolver.', 'duplicates' => $result['duplicates']], 409);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];
        $generalCountRequest->load('lines');

        return response()->json([
            'id' => $generalCountRequest->id,
            'total_productos' => $generalCountRequest->lines->count(),
        ], 201);
    }

    /**
     * SCRUM-462 (REQ-392) — "Evaluar": RN1 exige `cantidad_contada` en TODAS las líneas del
     * conteo, ni una de más ni una de menos. `cantidad_sistema` se toma como snapshot FRESCO de
     * `ProductWarehouseStock.quantity` en el momento de evaluar (no el guardado en `store()`,
     * que nunca se llenó) — refleja cualquier movimiento de Kardex ocurrido entre iniciar y
     * evaluar el conteo, ver docblock de la migración de líneas.
     *
     * RN2 (edición posterior a evaluar) — decisión de implementación (no está detallada en el
     * ADR, que deja la puerta abierta a "el mismo endpoint de carga o un PATCH de línea"): este
     * endpoint es reemplazable/idempotente. Volver a llamarlo mientras el conteo sigue en
     * `pendiente_evaluacion` o `evaluado` (es decir, todavía no se envió a aprobación) recalcula
     * TODAS las líneas de cero — nunca queda una `diferencia` vieja mezclada con una corrección
     * nueva. No se agregó una ruta PATCH de línea separada porque el ADR no la lista y este
     * enfoque cubre el mismo caso sin ampliar la superficie de la API aprobada.
     */
    public function evaluate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.cantidad_contada' => ['required', 'integer', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($id, $validated): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if (! in_array($generalCountRequest->estado, [GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, GeneralCountRequest::ESTADO_EVALUADO], true)) {
                return ['status' => 422, 'message' => 'Este conteo ya fue enviado a aprobación y no se puede volver a evaluar.'];
            }

            $lines = $generalCountRequest->lines()->get()->keyBy('id');

            /** @var array<int, array{id: int, cantidad_contada: int}> $submittedLines */
            $submittedLines = $validated['lines'];

            // Array plano (no Collection) para que el acceso por índice más abajo sea un array
            // shape que PHPStan puede seguir sin ambigüedad de "offset podría no existir".
            /** @var array<int, int> $cantidadPorLineaId */
            $cantidadPorLineaId = [];
            foreach ($submittedLines as $submittedLine) {
                $cantidadPorLineaId[(int) $submittedLine['id']] = (int) $submittedLine['cantidad_contada'];
            }

            // RN1 (REQ-392) — todas las líneas del conteo deben traer cantidad_contada, ni una
            // de más (id ajeno al conteo) ni una de menos.
            $lineIds = $lines->keys()->sort()->values()->all();
            $submittedIds = collect(array_keys($cantidadPorLineaId))->sort()->values()->all();
            if ($lineIds !== $submittedIds) {
                return ['status' => 422, 'message' => 'Debe indicar la cantidad contada de todos los productos del conteo.'];
            }

            foreach ($lines as $line) {
                /** @var GeneralCountRequestLine $line */
                $cantidadContada = $cantidadPorLineaId[$line->id] ?? 0;
                $cantidadSistema = (int) (ProductWarehouseStock::query()
                    ->where('catalog_product_id', $line->catalog_product_id)
                    ->where('warehouse_id', $generalCountRequest->warehouse_id)
                    ->value('quantity') ?? 0);

                $line->update([
                    'cantidad_sistema' => $cantidadSistema,
                    'cantidad_contada' => $cantidadContada,
                    'diferencia' => $cantidadContada - $cantidadSistema,
                ]);
            }

            $generalCountRequest->update(['estado' => GeneralCountRequest::ESTADO_EVALUADO]);

            return ['status' => 200, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /**
     * SCRUM-463/464 (REQ-393/394) — "Enviar a aprobación de Gerencia": RN1 exige `estado=evaluado`.
     * En la misma transacción ejecuta el cruce de REQ-394: por cada línea con `diferencia != 0`
     * que cruce con una `AdjustmentRequestLine` pendiente (mismo `catalog_product_id` +
     * `warehouse_id`, ver criterio de cruce en el ADR), la elimina; si la `AdjustmentRequest`
     * contenedora queda sin líneas, se elimina también (decidido por Luis en el ADR — evita
     * solicitudes fantasma sin ningún detalle real). El frontend ya confirmó con el usuario
     * antes de llamar este endpoint (ver ADR) — el backend no vuelve a confirmar, solo ejecuta.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate(['confirm_replace' => ['nullable', 'boolean']]);
        $confirmReplace = (bool) ($validated['confirm_replace'] ?? false);

        $result = DB::transaction(function () use ($id, $confirmReplace): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if ($generalCountRequest->estado !== GeneralCountRequest::ESTADO_EVALUADO) {
                return ['status' => 422, 'message' => 'Debe evaluar el conteo antes de enviarlo a aprobación.'];
            }

            // Pre-QA (2026-08-26) — mismo criterio que `store()` (ver ese comentario): lockea la
            // fila de `warehouses` antes del chequeo de duplicados, no solo la del propio conteo.
            Warehouse::where('id', $generalCountRequest->warehouse_id)->lockForUpdate()->first();

            $linesWithDifference = $generalCountRequest->lines()->where('diferencia', '!=', 0)->get();

            // SCRUM-797 (rebote 2026-08-27) — "al enviar a aprobación de Gerencia": si MIENTRAS TANTO
            // otro conteo de la misma bodega ya dejó una línea con diferencia real sin resolver
            // para alguno de los MISMOS productos que este conteo también encontró con diferencia,
            // requiere confirmación explícita antes de reemplazar esa línea puntual (nunca el
            // conteo completo — mismo criterio por producto que `store()`, ver
            // `findConflictingLines()`).
            /** @var list<int> $catalogProductIds */
            $catalogProductIds = array_values($linesWithDifference->pluck('catalog_product_id')->all());
            $conflictingLines = $this->findConflictingLines($generalCountRequest->warehouse_id, $catalogProductIds, excludeRequestId: $generalCountRequest->id);
            if ($conflictingLines->isNotEmpty() && ! $confirmReplace) {
                return ['status' => 409, 'duplicates' => $this->formatDuplicates($conflictingLines)];
            }
            foreach ($linesWithDifference as $line) {
                /** @var GeneralCountRequestLine $line */
                if ($conflictingLines->has($line->catalog_product_id)) {
                    $this->markConflictingLinesReplaced($generalCountRequest->warehouse_id, (int) $line->catalog_product_id, $line->id, excludeRequestId: $generalCountRequest->id);
                }
            }

            foreach ($linesWithDifference as $line) {
                /** @var GeneralCountRequestLine $line */
                $crossedLines = AdjustmentRequestLine::query()
                    ->lockForUpdate()
                    ->where('warehouse_id', $generalCountRequest->warehouse_id)
                    ->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)
                    ->whereHas('request', fn ($q) => $q->where('catalog_product_id', $line->catalog_product_id))
                    ->get();

                foreach ($crossedLines as $crossedLine) {
                    /** @var AdjustmentRequestLine $crossedLine */
                    $adjustmentRequestId = $crossedLine->adjustment_request_id;
                    $crossedLine->delete();

                    $remainingLines = AdjustmentRequestLine::where('adjustment_request_id', $adjustmentRequestId)->count();
                    if ($remainingLines === 0) {
                        AdjustmentRequest::where('id', $adjustmentRequestId)->delete();
                    }
                }
            }

            $generalCountRequest->update([
                'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
                // SCRUM-797 RN8 — momento exacto en que se pidió la aprobación, distinto de
                // `created_at` (creación del borrador) y de `resuelto_at` (decisión de Gerencia).
                'fecha_solicitud_aprobacion' => now(),
            ]);

            return ['status' => 200, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }
        if ($result['status'] === 409) {
            return response()->json(['message' => 'Uno o más productos ya tienen un conteo sin resolver.', 'duplicates' => $result['duplicates']], 409);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /**
     * SCRUM-465 (REQ-395) — Gerencia aprueba. Nunca toca el Kardex (a diferencia de
     * Ajustes/Reubicaciones) — ver SCRUM-466/`apply()` para el paso separado que sí lo hace.
     * Mismo fix de concurrencia que `RelocationRequestController::approve()`: lock + chequeo de
     * estado dentro de la transacción.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveApproverAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $result = DB::transaction(function () use ($id, $user): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if ($generalCountRequest->estado !== GeneralCountRequest::ESTADO_PENDIENTE_APROBACION) {
                return ['status' => 422, 'message' => 'Este conteo no está pendiente de aprobación.'];
            }

            $generalCountRequest->update([
                'estado' => GeneralCountRequest::ESTADO_APROBADA,
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /** SCRUM-465 (REQ-395 RN2) — Gerencia rechaza, motivo obligatorio, no toca el Kardex. */
    public function reject(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveApproverAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $validated = $request->validate(['motivo_rechazo' => ['required', 'string', 'min:1']]);

        $result = DB::transaction(function () use ($id, $user, $validated): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if ($generalCountRequest->estado !== GeneralCountRequest::ESTADO_PENDIENTE_APROBACION) {
                return ['status' => 422, 'message' => 'Este conteo no está pendiente de aprobación.'];
            }

            $generalCountRequest->update([
                'estado' => GeneralCountRequest::ESTADO_RECHAZADA,
                'motivo_rechazo' => $validated['motivo_rechazo'],
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /**
     * SCRUM-466 (REQ-396) — "Realizar ajuste": solo si `estado=aprobada` y `aplicado_at IS NULL`
     * — idempotencia real en backend (lock + doble chequeo dentro de la transacción), el botón
     * deshabilitado en frontend tras el primer click no alcanza por sí solo. Por cada línea con
     * `diferencia != 0`, un movimiento de Kardex `TIPO_CONTEO` con la cantidad firmada =
     * `diferencia` (positiva si sobró, negativa si faltó).
     *
     * Rebote de Gerencia Test (2026-08-14) — RN1 del REQ define este paso como una confirmación
     * manual EXCLUSIVA del Líder de Bodega (doble validación humana: Gerencia aprueba el conteo,
     * Líder de Bodega confirma que el ajuste ya se aplicó físicamente). El endpoint solo estaba
     * gateado por `permission:bodega.write` — un permiso amplio que cualquier perfil de Bodega
     * tiene (Asistentes/Operativos) y que Gerencia también tiene vía su security level de Gerencia,
     * dejando el botón funcional para gente que nunca debió poder accionarlo. `resolveUser()` no
     * alcanza por sí solo — mismo patrón que `resolveApproverAccess()` en este mismo controller y en
     * `RelocationRequestController`, pero gateando por `role_key` en vez de por
     * `primary_approver_user_id` (esto es un rol, no una persona puntual).
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveLiderBodegaAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $result = DB::transaction(function () use ($id, $user): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if ($generalCountRequest->estado !== GeneralCountRequest::ESTADO_APROBADA) {
                return ['status' => 422, 'message' => 'El conteo debe estar aprobado para realizar el ajuste.'];
            }
            if ($generalCountRequest->aplicado_at !== null) {
                return ['status' => 422, 'message' => 'El ajuste de este conteo ya fue aplicado.'];
            }

            $linesWithDifference = $generalCountRequest->lines()->where('diferencia', '!=', 0)->get();

            foreach ($linesWithDifference as $line) {
                /** @var GeneralCountRequestLine $line */
                $this->kardex->registrar(
                    catalogProductId: $line->catalog_product_id,
                    warehouseId: $generalCountRequest->warehouse_id,
                    tipo: InventoryMovement::TIPO_CONTEO,
                    cantidad: (int) $line->diferencia,
                    referenciaTexto: "Conteo general aplicado — conteo #{$generalCountRequest->id}",
                    referenciaEntidadTipo: 'CONTEO_GENERAL',
                    referenciaEntidadId: $generalCountRequest->id,
                    realizadoPor: $user->id,
                );
            }

            $generalCountRequest->update(['aplicado_at' => now()]);

            return ['status' => 200, 'request' => $generalCountRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        /** @var GeneralCountRequest $generalCountRequest */
        $generalCountRequest = $result['request'];

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /**
     * SCRUM-462 (REQ-392, rebote de Gerencia Test 2026-08-14) — "Continuar" un conteo que quedó
     * en `pendiente_evaluacion` o `evaluado` sin enviarse a aprobación. No recalcula nada (eso
     * sigue siendo `evaluate()`, idempotente) — solo confirma que el conteo TODAVÍA es
     * continuable y devuelve el detalle completo (mismo shape que `show()`/`evaluate()`), con
     * `cantidad_contada`/`diferencia` ya poblados si se había evaluado antes, para que el
     * frontend navegue directo a la pantalla de evaluación pre-cargada.
     */
    public function continueDraft(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $generalCountRequest = GeneralCountRequest::find($id);
        if ($generalCountRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! in_array($generalCountRequest->estado, [GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, GeneralCountRequest::ESTADO_EVALUADO], true)) {
            return response()->json(['message' => 'Este conteo ya fue enviado a aprobación y no se puede continuar.'], 422);
        }

        return response()->json($this->formatDetail($this->freshDetail($generalCountRequest)));
    }

    /**
     * SCRUM-462 (REQ-392, rebote de Gerencia Test 2026-08-14) — "Eliminar" el borrador completo.
     * Mismo criterio de estado que `continueDraft()`: solo si NUNCA se envió a aprobación
     * (`pendiente_evaluacion`/`evaluado`) — a partir de `pendiente_aprobacion` ya no es "solo un
     * borrador" de Bodega, es una decisión de Gerencia, no se puede eliminar por acá. Las líneas se
     * borran solas (`cascadeOnDelete`, ver migración de `general_count_request_lines`) — nunca
     * toca `AdjustmentRequestLine` ni el Kardex, un conteo en este estado no pudo haber cruzado ni
     * aplicado nada todavía.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $result = DB::transaction(function () use ($id): array {
            /** @var GeneralCountRequest|null $generalCountRequest */
            $generalCountRequest = GeneralCountRequest::lockForUpdate()->find($id);
            if ($generalCountRequest === null) {
                return ['status' => 404];
            }
            if (! in_array($generalCountRequest->estado, [GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, GeneralCountRequest::ESTADO_EVALUADO], true)) {
                return ['status' => 422, 'message' => 'Este conteo ya fue enviado a aprobación y no se puede eliminar.'];
            }

            $generalCountRequest->delete();

            return ['status' => 200];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => 'Conteo eliminado.']);
    }

    /**
     * Gate "exclusivo de Gerencia" — mismo mecanismo que
     * `AdjustmentRequestController::resolveApproverAccess()` (`ComprasSettings::primary_approver_user_id`).
     *
     * SCRUM-161/162 (2026-08-26): delega en `AprobacionGate`, que además de Gerencia acepta cualquier
     * usuario con una `ReglaAprobacion` activa para tipo=general_count (SCRUM-163) — ver docblock
     * de `AprobacionGate` para el porqué.
     *
     * @return array{0: User}|JsonResponse
     */
    private function resolveApproverAccess(Request $request): array|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! app(AprobacionGate::class)->canAct($user, 'general_count')) {
            return response()->json(['message' => 'No tienes permiso para aprobar o rechazar esta solicitud.'], 403);
        }

        return [$user];
    }

    /** SCRUM-463/161 — misma condición que resolveApproverAccess(), como boolean informativo para el listado. */
    private function canApprove(Request $request): bool
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return false;
        }

        return app(AprobacionGate::class)->canAct($user, 'general_count');
    }

    /**
     * Gate "exclusivo de Líder de Bodega" (SCRUM-466, rebote 2026-08-14) — a diferencia de
     * `resolveApproverAccess()`, esto es un ROL (`BodegaRoles::LIDER_BODEGA`), no una persona puntual
     * configurable — nunca hardcodear un `user_id`, mismo criterio que `PickingService::
     * assignPicker()` (`$actor->role?->key`).
     *
     * @return array{0: User}|JsonResponse
     */
    private function resolveLiderBodegaAccess(Request $request): array|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($user->role?->key !== BodegaRoles::LIDER_BODEGA) {
            return response()->json(['message' => 'Solo el Líder de Bodega puede realizar el ajuste.'], 403);
        }

        return [$user];
    }

    private function freshDetail(GeneralCountRequest $generalCountRequest): GeneralCountRequest
    {
        /** @var GeneralCountRequest $fresh */
        $fresh = $generalCountRequest->fresh(['warehouse', 'realizadoPor', 'resolvedBy', 'lines.catalogProduct']);

        return $fresh;
    }

    /**
     * `withCount('lines')` en `index()` evita N+1 en la bandeja; `show()`/`freshDetail()` cargan
     * `lines` completo (necesitan el detalle), así que ahí se cuenta la colección ya en memoria.
     */
    private function totalProductos(GeneralCountRequest $generalCountRequest): int
    {
        if ($generalCountRequest->lines_count !== null) {
            return (int) $generalCountRequest->lines_count;
        }

        if ($generalCountRequest->relationLoaded('lines')) {
            return $generalCountRequest->lines->count();
        }

        return $generalCountRequest->lines()->count();
    }

    /**
     * SCRUM-465 (REQ-395 RN3) — "Diferencias encontradas" por fila. Mismo patrón que
     * `totalProductos()`: `withCount` agregado en `index()`, colección en memoria en
     * `show()`/`freshDetail()`. Líneas con `diferencia` NULL (conteo nunca evaluado) no cuentan
     * como diferencia — solo `diferencia != 0` cuenta, igual que el filtro que usa `submit()`
     * para el cruce.
     */
    private function diferenciasEncontradas(GeneralCountRequest $generalCountRequest): int
    {
        if ($generalCountRequest->diferencias_count !== null) {
            return (int) $generalCountRequest->diferencias_count;
        }

        if ($generalCountRequest->relationLoaded('lines')) {
            return $generalCountRequest->lines
                ->filter(fn (GeneralCountRequestLine $line) => $line->diferencia !== null && $line->diferencia !== 0)
                ->count();
        }

        return $generalCountRequest->lines()->where('diferencia', '!=', 0)->count();
    }

    /** @return array<string, mixed> */
    private function formatRequest(GeneralCountRequest $generalCountRequest): array
    {
        return [
            'id' => $generalCountRequest->id,
            'warehouse_id' => $generalCountRequest->warehouse_id,
            'bodega' => $generalCountRequest->warehouse?->name,
            'realizado_por' => $generalCountRequest->realizadoPor !== null
                ? trim("{$generalCountRequest->realizadoPor->first_name} {$generalCountRequest->realizadoPor->last_name}") : null,
            'estado' => $generalCountRequest->estado,
            'total_productos' => $this->totalProductos($generalCountRequest),
            'diferencias_encontradas' => $this->diferenciasEncontradas($generalCountRequest),
            'fecha' => $generalCountRequest->created_at->toIso8601String(),
            // SCRUM-797 RN8 — "Ver detalle": null mientras el conteo sigue en borrador
            // (pendiente_evaluacion/evaluado), poblado por `submit()`.
            'fecha_solicitud_aprobacion' => $generalCountRequest->fecha_solicitud_aprobacion?->toIso8601String(),
            'motivo_rechazo' => $generalCountRequest->motivo_rechazo,
            'resuelto_por' => $generalCountRequest->resolvedBy !== null
                ? trim("{$generalCountRequest->resolvedBy->first_name} {$generalCountRequest->resolvedBy->last_name}") : null,
            'resuelto_at' => $generalCountRequest->resuelto_at?->toIso8601String(),
            'aplicado_at' => $generalCountRequest->aplicado_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(GeneralCountRequest $generalCountRequest): array
    {
        $base = $this->formatRequest($generalCountRequest);
        $base['lines'] = $generalCountRequest->lines
            ->map(fn (GeneralCountRequestLine $line) => $this->formatLine($generalCountRequest, $line))
            ->values();

        return $base;
    }

    /** @return array<string, mixed> */
    private function formatLine(GeneralCountRequest $generalCountRequest, GeneralCountRequestLine $line): array
    {
        $cruceFecha = $this->crossedAdjustmentFecha($line->catalog_product_id, $generalCountRequest->warehouse_id);

        return [
            'id' => $line->id,
            'producto' => [
                'id' => $line->catalogProduct?->id,
                'reference' => $line->catalogProduct?->reference,
                'description' => $line->catalogProduct?->name,
            ],
            'cantidad_sistema' => $line->cantidad_sistema,
            'cantidad_contada' => $line->cantidad_contada,
            'diferencia' => $line->diferencia,
            // SCRUM-461 (REQ-391) — aviso de cruce con solicitud de ajuste pendiente sobre el
            // mismo producto/bodega.
            'tiene_cruce_pendiente' => $cruceFecha !== null,
            'cruce_fecha' => $cruceFecha,
        ];
    }

    /**
     * SCRUM-461 (REQ-391) — un producto "cruza" si existe una `AdjustmentRequestLine` con
     * `estado=Pendiente` cuya `AdjustmentRequest.catalog_product_id` coincide, cruzando además
     * por `warehouse_id` de la línea contra la bodega del conteo (criterio definido en el ADR,
     * para no bloquear un conteo de Bodega Central por una solicitud pendiente de Zona Libre).
     */
    private function crossedAdjustmentFecha(int $catalogProductId, int $warehouseId): ?string
    {
        $crossedLine = AdjustmentRequestLine::query()
            ->where('warehouse_id', $warehouseId)
            ->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)
            ->whereHas('request', fn ($q) => $q->where('catalog_product_id', $catalogProductId))
            ->oldest('created_at')
            ->first();

        return $crossedLine?->created_at->toIso8601String();
    }

    /**
     * SCRUM-797 (rebote de Gerencia Test 2026-08-27) — reemplaza el viejo `findUnresolvedForWarehouse()`
     * (bloqueo de bodega completa). Un producto está "en conflicto" únicamente si ya tiene una
     * línea EVALUADA con diferencia real (`diferencia != 0`) en OTRO conteo de la misma bodega que
     * sigue sin aprobar/rechazar por Gerencia (`evaluado`/`pendiente_aprobacion`) — decisión explícita
     * de Luis 2026-08-27: pertenecer a un conteo todavía sin evaluar (`pendiente_evaluacion`,
     * `diferencia` siempre NULL en ese estado) no cuenta como conflicto, nada está en disputa
     * todavía. `replaced_by_id IS NULL` — una línea ya reemplazada por confirmación previa no
     * vuelve a bloquear nada.
     *
     * @param  list<int>  $catalogProductIds  productos a evaluar (todos los que va a incluir el
     *                                        conteo en `store()`, o los que salieron con diferencia en `submit()`)
     * @return Collection<int, GeneralCountRequestLine> indexada por `catalog_product_id`
     */
    private function findConflictingLines(int $warehouseId, array $catalogProductIds, ?int $excludeRequestId = null): Collection
    {
        if ($catalogProductIds === []) {
            return new Collection;
        }

        return $this->conflictingLinesQuery($warehouseId, $catalogProductIds, $excludeRequestId)
            ->with('request')
            ->lockForUpdate()
            ->get()
            ->keyBy('catalog_product_id');
    }

    /**
     * @param  list<int>  $catalogProductIds
     * @return Builder<GeneralCountRequestLine>
     */
    private function conflictingLinesQuery(int $warehouseId, array $catalogProductIds, ?int $excludeRequestId = null): Builder
    {
        return GeneralCountRequestLine::query()
            ->whereIn('catalog_product_id', $catalogProductIds)
            ->whereNull('replaced_by_id')
            ->whereNotNull('diferencia')
            ->where('diferencia', '!=', 0)
            ->whereHas('request', function ($q) use ($warehouseId, $excludeRequestId): void {
                $q->where('warehouse_id', $warehouseId)
                    ->whereIn('estado', [GeneralCountRequest::ESTADO_EVALUADO, GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]);
                if ($excludeRequestId !== null) {
                    $q->where('id', '!=', $excludeRequestId);
                }
            });
    }

    /**
     * SCRUM-800 — `findConflictingLines()` colapsa a UNA línea por producto (`keyBy`) para el
     * chequeo/aviso 409, correcto para ese propósito. Pero si hubo más de un ciclo de conteo sin
     * resolver para el mismo producto+bodega (varios `store()`/`submit()` sin que Gerencia
     * apruebe/rechace los anteriores), solo la línea "colapsada" quedaba marcada como
     * reemplazada — las demás quedaban huérfanas (`replaced_by_id` NULL) y seguían bloqueando
     * ajustes/conteos nuevos para siempre, aunque ya no reflejaran el estado real más reciente.
     * Este método marca TODAS las líneas conflictivas huérfanas del producto, no solo una.
     */
    private function markConflictingLinesReplaced(int $warehouseId, int $catalogProductId, int $replacementLineId, ?int $excludeRequestId = null): void
    {
        $this->conflictingLinesQuery($warehouseId, [$catalogProductId], $excludeRequestId)
            ->lockForUpdate()
            ->get()
            ->each(fn (GeneralCountRequestLine $line) => $line->update(['replaced_by_id' => $replacementLineId]));
    }

    /**
     * @param  Collection<int, GeneralCountRequestLine>  $conflictingLines  indexada por catalog_product_id
     * @return list<array<string, mixed>>
     */
    private function formatDuplicates(Collection $conflictingLines): array
    {
        $products = CatalogProduct::query()->whereIn('id', $conflictingLines->keys())->get()->keyBy('id');

        $mapped = $conflictingLines
            ->map(function (GeneralCountRequestLine $line, int $catalogProductId) use ($products): array {
                $product = $products->get($catalogProductId);

                return [
                    'catalog_product_id' => $catalogProductId,
                    'product_reference' => $product?->reference,
                    'product_name' => $product?->name,
                    'general_count' => [
                        'id' => $line->general_count_request_id,
                        'fecha' => $line->created_at->toIso8601String(),
                        'estado' => $line->request->estado,
                    ],
                ];
            })
            ->all();

        return array_values($mapped);
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
