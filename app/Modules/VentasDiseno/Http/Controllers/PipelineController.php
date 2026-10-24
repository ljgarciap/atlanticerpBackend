<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Exceptions\AlreadyPassedToOrderException;
use App\Modules\VentasDiseno\Http\Requests\StorePipelineCardRequest;
use App\Modules\VentasDiseno\Http\Requests\UpdatePipelineCardRequest;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardFile;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\PipelineFileService;
use App\Modules\VentasDiseno\Services\PipelineService;
use App\Modules\VentasDiseno\Services\ProductDeliveryService;
use App\Modules\VentasDiseno\Services\ProjectAssociateService;
use App\Shared\Support\CircuitOpenException;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PipelineController extends Controller
{
    use ParsesIdListQueryParam;

    public const FILE_EXTENSIONS = 'png,jpg,jpeg,pdf';

    public function __construct(
        private readonly PipelineService $service,
        private readonly PipelineFileService $files,
        private readonly ProductDeliveryService $productDeliveries,
        private readonly ProjectAssociateService $associates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'chip', 'search', 'order', 'owner_id', 'tag', 'stage', 'orders']);

        $filters = array_filter([
            'scope'    => isset($raw['scope'])  ? (string) $raw['scope']  : null,
            'chip'     => isset($raw['chip'])   ? (string) $raw['chip']   : null,
            'search'   => isset($raw['search']) ? (string) $raw['search'] : null,
            'order'    => isset($raw['order'])  ? (string) $raw['order']  : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados), sobre el
            // mismo alcance "Equipo" que ya gatea owner_id singular. owner_id se mantiene para no
            // romper otros call sites de este mismo query param.
            'owner_ids' => $this->parseIdListParam($request, 'owner_ids'),
            // SCRUM-796 (secc. 1.3) — Dashboard "Por tipo de solicitud" navega acá con
            // ?tag=design|quote|both, el mismo enum de SalesProject::tag (StorePipelineCardRequest).
            // "both" ya representa "Diseño + Cotización" como valor propio del enum — no hace
            // falta combinar dos tags a la vez.
            'tag'      => isset($raw['tag']) && in_array($raw['tag'], ['design', 'quote', 'both'], true) ? (string) $raw['tag'] : null,
            // SCRUM-796 (secc. 1.1/1.2) — filtro genérico por etapa literal (los 6 stages),
            // distinto de `chip` (que mantiene su semántica propia: final_stage/stagnant/
            // approved). Dashboard CRM (indicadores y gráfica "Proyectos por etapa") navega
            // acá con ?stage=<lead|design|quote|proposal|approved|lost>.
            'stage'    => isset($raw['stage']) && in_array($raw['stage'], [...PipelineCard::stageOrder(), 'lost'], true) ? (string) $raw['stage'] : null,
            // SCRUM-1128 — filtro "Pedidos": approved = ya pasó a pedido (existe el pedido en
            // Bodega); pending = Aprobado en Pipeline y todavía sin pasar a pedido.
            'orders'   => isset($raw['orders']) && in_array($raw['orders'], ['approved', 'pending'], true) ? (string) $raw['orders'] : null,
        ], fn (mixed $v): bool => $v !== null);

        $cards = $this->service->list($user, $filters);

        return response()->json([
            'data' => $cards->map(fn (PipelineCard $c) => $this->format($c))->values(),
        ]);
    }

    public function store(StorePipelineCardRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{type: string, name: string, tag?: string|null, sub_client_id?: int|null, assignee_ids?: list<int>} $validated */
        $validated = $request->validated();

        // SCRUM-849.4 — solo Gerencia puede fijar la asignación al crear; para cualquier otro
        // rol, `assignee_ids` (si llegara en el payload) se ignora en silencio — la tarjeta
        // nace asignada a quien la crea, comportamiento de siempre.
        $assigneeIds = $user->canViewTeamV2('ventas_diseno') ? ($validated['assignee_ids'] ?? null) : null;

        $card = $this->service->create($validated, $user->id, $assigneeIds);

        return response()->json($this->formatDetail($card, $user), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($id);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canRead($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return response()->json($this->formatDetail($card, $user));
    }

    public function update(UpdatePipelineCardRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($id);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        // REQ-008: "lost" nunca editable; "approved" requiere el permiso elevado.
        if (! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /**
         * @var array{
         *   name?: string,
         *   tag?: string|null,
         *   master_client_id?: int|null,
         *   sub_client_id?: int|null,
         *   worked_area_m2?: float|null,
         *   delivery_type?: string|null,
         *   delivery_dates?: list<string>,
         *   observations?: string|null,
         *   assignee_ids?: list<int>,
         * } $validated
         */
        $validated = $request->validated();

        // SCRUM-849.4 — mismo gate que store(): un no-Gerencia no puede reasignar la tarjeta,
        // aunque el campo llegue en el payload (PipelineCardModal manda el formulario completo
        // en una sola mutation, mismo patrón que delivery_type/delivery_dates arriba).
        if (array_key_exists('assignee_ids', $validated) && ! $user->canViewTeamV2('ventas_diseno')) {
            unset($validated['assignee_ids']);
        }

        // REQ-010: si se vincula un Subcliente, debe pertenecer al Cliente Master
        // resultante (el que se está enviando en este request, o el actual si no cambia).
        if (array_key_exists('sub_client_id', $validated) && $validated['sub_client_id'] !== null) {
            $resultingMasterId = array_key_exists('master_client_id', $validated)
                ? $validated['master_client_id']
                : $card->master_client_id;

            $subClient = SubClient::find($validated['sub_client_id']);
            if ($subClient === null || $subClient->master_client_id !== $resultingMasterId) {
                return response()->json(
                    ['message' => 'El subcliente no pertenece al Cliente Master indicado'],
                    422,
                );
            }
        }

        // Pre-QA (SCRUM-821, 2026-08-31) — mismo hallazgo que ya se corrigió para "single"
        // (PipelineService::update() trunca a 1 fecha), en la dirección simétrica: "partial"
        // exige mínimo 2 fechas (REQ original de Pipeline), pero antes la API lo aceptaba con 0 o
        // 1 sin avisar — solo el frontend precargaba 2 campos, nunca lo exigía server-side. Acá
        // sí se rechaza en vez de normalizar (a diferencia de "single", no hay forma segura de
        // "inventar" una segunda fecha faltante).
        $resultingType = array_key_exists('delivery_type', $validated) ? $validated['delivery_type'] : $card->delivery_type;
        if ($resultingType === 'partial' && array_key_exists('delivery_dates', $validated) && count($validated['delivery_dates']) < 2) {
            return response()->json(
                ['message' => 'La modalidad "Parcial" requiere al menos 2 fechas de entrega.'],
                422,
            );
        }

        // SCRUM-78 solo ocultaba los campos de fecha en Lead/Diseño del lado del frontend — la
        // API en sí nunca rechazaba un delivery_type/delivery_dates fuera de Propuesta/Aprobado.
        // Mismo criterio que arriba: reforzar server-side la regla que el frontend ya aplica.
        //
        // SCRUM-828 (2026-09-02) — el guard original chequeaba array_key_exists() (presencia de
        // la key), no si el valor era realmente algo: PipelineCardModal guarda el formulario
        // completo en una sola mutation y siempre manda delivery_type/delivery_dates junto con el
        // resto de campos, aunque el usuario nunca haya tocado esos campos (van como null/[] en
        // Cotización, donde están ocultos desde SCRUM-78). Eso bloqueaba con este mismo mensaje
        // cualquier guardado en Cotización que no tocara fechas — hay que chequear el valor
        // resultante, no la mera presencia de la key.
        //
        // SCRUM-909 (rebote 2026-09-14) — Cotización pasa a ser una etapa válida para definir la
        // fecha de entrega (junto con "Asignar productos a entrega" del lado del frontend): antes
        // el campo se veía disponible en Cotización pero el guardado siempre rechazaba con este
        // mismo mensaje, un callejón sin salida real para el usuario.
        $deliveryTypeSet  = array_key_exists('delivery_type', $validated) && $validated['delivery_type'] !== null;
        $deliveryDatesSet = array_key_exists('delivery_dates', $validated) && count($validated['delivery_dates']) > 0;
        if (
            ($deliveryTypeSet || $deliveryDatesSet)
            && ! in_array($card->stage, ['quote', 'proposal', 'approved'], true)
        ) {
            return response()->json(
                ['message' => 'La fecha de entrega solo se puede definir en las etapas Cotización, Propuesta o Aprobado.'],
                422,
            );
        }

        // SCRUM-1027 — "Asignar productos a entrega" dentro de la edición: las asignaciones llegan
        // en el mismo PUT que las fechas (aún sin guardar). Se aplican en la MISMA transacción, ya
        // con las fechas sincronizadas, para que un 422 de asignación no deje fechas a medias.
        $productDeliveries = $request->validated('product_deliveries');
        if ($productDeliveries !== null && $productDeliveries !== []) {
            if ($resultingType !== 'partial') {
                return response()->json(
                    ['message' => 'Solo se pueden asignar fechas por producto en tarjetas con entrega Parcial.'],
                    422,
                );
            }
        }

        try {
            $card = DB::transaction(function () use ($card, $validated, $productDeliveries): PipelineCard {
                $updated = $this->service->update($card, $validated);
                if ($productDeliveries !== null && $productDeliveries !== []) {
                    /** @var list<array{quote_item_id: int, delivery_date: string|null}> $productDeliveries */
                    $this->productDeliveries->saveAssignments($updated, $productDeliveries);
                }

                return $updated;
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatDetail($card, $user));
    }

    public function changeStage(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($id);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var array{stage: string} $validated */
        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::in(['lead', 'design', 'quote', 'proposal', 'approved', 'lost'])],
        ]);
        $targetStage = $validated['stage'];

        // REQ-018 es la única acción permitida sobre una tarjeta en "lost" — bypassea
        // isEditableBy() a propósito (que bloquea "lost" por completo) para permitir
        // justamente esa reactivación.
        if ($card->stage !== PipelineCard::NON_EDITABLE_STAGE && ! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $gateError = $card->validationErrorForTransitionTo($targetStage);

        // SCRUM-909 — completa el gate de Propuesta->Aprobado ya existente en el Model (fecha de
        // entrega + archivos): con entrega Parcial (2+ fechas), además exige que TODOS los
        // productos de la cotización tengan una fecha asignada (ver
        // ProductDeliveryService::validationErrorForApproval()). Vive en el controller, no en el
        // Model, porque necesita resolver la cotización del proyecto (Quote/QuoteItem), algo que
        // el Model no hace en ningún otro gate — mismo criterio que ya separa
        // PipelineCard::isEditableBy() de la resolución de cotización en OrderService.
        if ($gateError === null && $card->stage === 'proposal' && $targetStage === 'approved') {
            $gateError = $this->productDeliveries->validationErrorForApproval($card);
        }

        if ($gateError !== null) {
            return response()->json(['message' => $gateError], 422);
        }

        $card = $this->service->changeStage($card, $targetStage);

        return response()->json($this->formatDetail($card, $user));
    }

    /**
     * SCRUM-1128 — "Pasar a Pedido": única vía para que un proyecto Aprobado genere su Pedido en
     * Bodega. Gate de ruta: `permission:ventas_diseno.pass_to_order` (Gerencia por rol, Félix por
     * grant individual; Vendedor/Diseñador reciben 403 aun llamando la ruta directo). No exige
     * `canAccess()`: Félix no es dueño ni tiene alcance de equipo, su permiso angosto es suficiente.
     */
    public function passToOrder(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($id);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $card = $this->service->passToOrder($card, $user);
        } catch (AlreadyPassedToOrderException $e) {
            $current = $this->service->findDetailed($id) ?? $card;

            return response()->json(['message' => $e->getMessage(), 'data' => $this->formatDetail($current, $user)], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Fallo del lado de Bodega: la transacción de passToOrder() ya revirtió la aprobación
            // y el folio, la tarjeta queda reintentable. No se filtra el mensaje interno.
            report($e);

            return response()->json([
                'message' => 'No se pudo generar el pedido en Bodega. La aprobación no se registró; intenta de nuevo.',
            ], 500);
        }

        return response()->json($this->formatDetail($card, $user));
    }

    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($id);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var array{type: string} $validated */
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['design', 'proposal', 'signed_quote', 'approval_proof', 'photo'])],
            // REQ-052: extensions (nombre de archivo), no mimes — mimes: falla en silencio
            // para extensiones que Symfony no reconoce en su registro MIME.
            'file' => "required|file|extensions:" . PipelineController::FILE_EXTENSIONS,
        ]);

        // REQ-013/REQ-015 — los archivos de diseño y de propuesta quedan de solo lectura una vez
        // el proyecto está Aprobado, sin excepción: a diferencia de isEditableBy() (que gobierna
        // nombre/cliente/superficie/etc. y sí tiene una excepción vía el permiso
        // ventas_diseno.edit.approved), este gate es incondicional. "approval_proof" queda afuera
        // a propósito: es el comprobante que se sube justo al aprobar y puede necesitar
        // reemplazarse después, ver test_subir_comprobante_de_aprobacion_en_aprobado_*.
        if (
            in_array($validated['type'], ['design', 'proposal'], true)
            && $card->stage === PipelineCard::RESTRICTED_EDIT_STAGE
        ) {
            $label = $validated['type'] === 'design' ? 'de diseño' : 'de propuesta';

            return response()->json([
                'message' => "No se pueden agregar archivos {$label} en un proyecto Aprobado.",
            ], 422);
        }

        if (! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        try {
            $uploaded = $this->files->upload($file, $card, $validated['type'], $user->id);
        } catch (CircuitOpenException) {
            return response()->json([
                'message' => 'Storage service temporarily unavailable. Please try again in a few minutes.',
            ], 503);
        }

        return response()->json($this->formatFile($uploaded), 201);
    }

    /**
     * SCRUM-767 — URL presignada (15 min) para ver/descargar un archivo ya guardado. No existía
     * ningún endpoint de lectura para `pipeline_card_files` (tabla propia, sin versionado, distinta
     * de `crm.project_documents`) — antes de esto un archivo cargado quedaba listado pero
     * funcionalmente inaccesible. Mismo patrón lazy que `CRM\Http\Controllers\DocumentController::url()`
     * (no se embebe la URL en el listado, se pide al momento de Ver/Descargar).
     */
    public function fileUrl(Request $request, int $cardId, int $fileId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($cardId);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canRead($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $file = $card->files()->find($fileId);
        if ($file === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var 'inline'|'attachment' $disposition */
        $disposition = $request->query('disposition') === 'attachment' ? 'attachment' : 'inline';
        $presignedUrl = $this->files->getPresignedUrl($file, $disposition);

        return response()->json([
            'url'       => $presignedUrl,
            'filename'  => $file->file_name,
            'mime_type' => $file->mime_type,
        ]);
    }

    public function deleteFile(Request $request, int $cardId, int $fileId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = $this->service->findDetailed($cardId);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        if (! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $file = $card->files()->find($fileId);
        if ($file === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // REQ-013/015 + evidencia de aprobación: en un proyecto Aprobado estos tipos no se quitan, ni siquiera con el
        // permiso `ventas_diseno.edit.approved` (igual de incondicional que el bloqueo de la subida en `uploadFile`).
        if ($card->stage === PipelineCard::RESTRICTED_EDIT_STAGE && isset(PipelineCard::APPROVED_LOCKED_FILE_TYPES[$file->type])) {
            $label = PipelineCard::APPROVED_LOCKED_FILE_TYPES[$file->type];

            return response()->json([
                'message' => "No se pueden quitar archivos {$label} de un proyecto Aprobado.",
            ], 422);
        }

        $this->files->delete($file);

        return response()->json(['message' => 'OK']);
    }

    /**
     * SCRUM-1128 — lectura: `canAccess()` + `ventas_diseno.pipeline.read_all` (Félix, solo lectura
     * de todo el equipo). Las acciones de escritura siguen usando `canAccess()` y, además, sus rutas
     * exigen `ventas_diseno.edit`, que ese permiso angosto no otorga.
     */
    private function canRead(PipelineCard $card, User $user): bool
    {
        return $this->canAccess($card, $user)
            || in_array('ventas_diseno.pipeline.read_all', $user->effectivePermissionsV2(), true);
    }

    private function canAccess(PipelineCard $card, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        // SCRUM-1139 — `ventas_diseno.pipeline.edit` (Félix): alcance de equipo SOLO dentro del Pipeline.
        if (PipelineCard::userHasPipelineEdit($user)) {
            return true;
        }

        // SCRUM-849.4 — `compartido_con` (REQ-506, Batch 15) ya contaba como "responsable" para
        // PipelineCard::projectIdsOwnedBy() (SCRUM-818), pero este gate nunca lo chequeó — antes
        // no importaba porque no existía ninguna UI para poblarlo; ahora que Gerencia puede
        // asignar varias personas (ver update()), un asignado secundario debe poder acceder.
        return $card->isResponsible($user);
    }

    /** @return list<array{id: int, name: string}> */
    private function resolveAssignees(PipelineCard $card): array
    {
        $assignees = [[
            'id' => $card->owner->id, 'name' => trim("{$card->owner->first_name} {$card->owner->last_name}"),
        ]];

        $extraIds = $card->compartido_con ?? [];
        if ($extraIds === []) {
            return $assignees;
        }

        foreach (User::whereIn('id', $extraIds)->get() as $extra) {
            $assignees[] = ['id' => $extra->id, 'name' => trim("{$extra->first_name} {$extra->last_name}")];
        }

        return $assignees;
    }

    /** @return array<string, mixed> */
    private function format(PipelineCard $card): array
    {
        return [
            'id'               => $card->id,
            'stage'            => $card->stage,
            'sales_project'    => [
                'id'   => $card->salesProject->id,
                'name' => $card->salesProject->name,
                'tag'  => $card->salesProject->tag,
            ],
            'master_client'    => $card->masterClient !== null ? [
                'id'   => $card->masterClient->id,
                'name' => $card->masterClient->name,
            ] : null,
            'sub_client'       => $card->subClient !== null ? [
                'id'            => $card->subClient->id,
                'business_name' => $card->subClient->business_name,
            ] : null,
            'owner'            => [
                'id'   => $card->owner->id,
                'name' => trim("{$card->owner->first_name} {$card->owner->last_name}"),
            ],
            // SCRUM-849.4 — owner + compartido_con, para que el multi-select de Gerencia
            // (UserMultiSelect) llegue preseleccionado con la asignación real de la tarjeta.
            'assignees'        => $this->resolveAssignees($card),
            'amount'           => $card->amount,
            'worked_area_m2'   => $card->worked_area_m2,
            'days_in_stage'    => $card->daysInStage(),
            'is_stagnant'      => $card->isStagnant(),
            'stage_changed_at' => $card->stage_changed_at->toIso8601String(),
            // SCRUM-1128 — estado REAL de "Pasar a Pedido": 'approved' = ya existe el pedido en
            // Bodega, 'pending' = Aprobado en Pipeline sin pasar a pedido, null = aún no aplica.
            'order_status'      => $this->orderStatus($card),
            'order_approved_by' => $card->orderApprover !== null ? [
                'id'   => $card->orderApprover->id,
                'name' => trim("{$card->orderApprover->first_name} {$card->orderApprover->last_name}"),
            ] : null,
            'order_approved_at' => $card->order_approved_at?->toIso8601String(),
        ];
    }

    private function canPassToOrder(User $user): bool
    {
        $permissions = $user->effectivePermissionsV2();

        return in_array('superadmin.all', $permissions, true)
            || in_array('ventas_diseno.pass_to_order', $permissions, true);
    }

    private function orderStatus(PipelineCard $card): ?string
    {
        $hasOrder = array_key_exists('has_order', $card->getAttributes())
            ? (bool) $card->getAttribute('has_order')
            : $card->orders()->exists();

        if ($hasOrder) {
            return 'approved';
        }

        return $card->stage === PipelineCard::RESTRICTED_EDIT_STAGE ? 'pending' : null;
    }

    /** @return array<string, mixed> */
    private function formatDetail(PipelineCard $card, User $user): array
    {
        // SCRUM-734 (sección 3) — la cotización confirmada más reciente del proyecto (ver `latest_quote_id`
        // abajo), resuelta una sola vez para exponer su id y su folio sin una segunda consulta.
        $latestQuote = $card->sales_project_id !== null
            ? Quote::where('sales_project_id', $card->sales_project_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('confirmed_at')
                ->orderByDesc('id')
                ->first(['id', 'folio'])
            : null;

        return array_merge($this->format($card), [
            // SCRUM-1121 — asociados del proyecto (los mismos que ven sus cotizaciones).
            'associates' => $card->salesProject !== null
                ? $this->associates->forProject($card->salesProject)->map(fn ($a): array => ProjectAssociateService::format($a))->values()
                : [],
            // SCRUM-884 — quien creó el proyecto, inmutable, distinto de "owner"/"assignees"
            // (Responsable, reasignable). Null solo en tarjetas pre-existentes al campo, antes
            // de correr el backfill (ventas_diseno:backfill-pipeline-card-created-by).
            'creator'               => $card->creator !== null ? [
                'id'   => $card->creator->id,
                'name' => trim("{$card->creator->first_name} {$card->creator->last_name}"),
            ] : null,
            'delivery_type'          => $card->delivery_type,
            'observations'          => $card->observations,
            'delivery_dates'        => $card->deliveryDates
                ->map(fn ($d) => $d->date->toDateString())
                ->values(),
            'contacts'              => $card->contacts->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'role'  => $c->role,
                'phone' => $c->phone,
                'email' => $c->email,
            ])->values(),
            'has_architect_contact' => $card->hasArchitectContact(),
            'can_edit'              => $card->isEditableBy($user),
            // SCRUM-1128 — el botón "Pasar a Pedido" se muestra solo con esto en true.
            'can_pass_to_order'     => $card->stage === PipelineCard::RESTRICTED_EDIT_STAGE
                && $this->orderStatus($card) === 'pending'
                && $this->canPassToOrder($user),
            'files'                 => $card->files->map(fn (PipelineCardFile $f) => $this->formatFile($f))->values(),
            // SCRUM-734 (sección 3) — id de la cotización confirmada más reciente del
            // proyecto, null si nunca hubo ninguna. Gate + destino de navegación del
            // botón "Ver cotizaciones de este proyecto" (abre QuoteViewerModal, que ya
            // resuelve el historial completo de versiones desde ese id). Desde
            // SCRUM-734, la única forma de que una tarjeta avance más allá de
            // Lead/Diseño es QuoteService::confirm(), así que en la práctica esto ya
            // es equivalente a `stage !== lead/design` — pero se calcula explícito acá
            // para no depender de esa inferencia y para cubrir "lost" (pudo perderse
            // desde Cotización en adelante, con historial real, o desde Lead/Diseño,
            // sin ninguno).
            'latest_quote_id'       => $latestQuote?->id,
            // SCRUM-1115 — folio de esa misma cotización, para el diálogo "Crear cotización" de
            // un proyecto en Lead/Diseño que ya tiene historial (ofrece partir de COT-xxxx).
            'latest_quote_folio'    => $latestQuote?->folio,
        ]);
    }

    /** @return array<string, mixed> */
    private function formatFile(PipelineCardFile $file): array
    {
        return [
            'id'         => $file->id,
            'type'       => $file->type,
            'file_name'  => $file->file_name,
            'size_bytes' => $file->size_bytes,
            // SCRUM-767 — el frontend lo necesita para decidir entre preview inline (PDF/imagen)
            // y descarga directa, sin adivinar por extensión de `file_name`.
            'mime_type'  => $file->mime_type,
            'created_at' => $file->created_at->toIso8601String(),
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
