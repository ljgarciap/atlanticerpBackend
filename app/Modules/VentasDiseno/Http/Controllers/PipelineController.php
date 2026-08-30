<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StorePipelineCardRequest;
use App\Modules\VentasDiseno\Http\Requests\UpdatePipelineCardRequest;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardFile;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\PipelineFileService;
use App\Modules\VentasDiseno\Services\PipelineService;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class PipelineController extends Controller
{
    private const FILE_EXTENSIONS = 'png,jpg,jpeg,pdf';

    public function __construct(
        private readonly PipelineService $service,
        private readonly PipelineFileService $files,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'chip', 'search', 'order', 'owner_id', 'tag', 'stage']);

        $filters = array_filter([
            'scope'    => isset($raw['scope'])  ? (string) $raw['scope']  : null,
            'chip'     => isset($raw['chip'])   ? (string) $raw['chip']   : null,
            'search'   => isset($raw['search']) ? (string) $raw['search'] : null,
            'order'    => isset($raw['order'])  ? (string) $raw['order']  : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
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

        /** @var array{type: string, name: string, tag?: string|null, sub_client_id?: int|null} $validated */
        $validated = $request->validated();

        $card = $this->service->create($validated, $user->id);

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

        if (! $this->canAccess($card, $user)) {
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
         * } $validated
         */
        $validated = $request->validated();

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

        $card = $this->service->update($card, $validated);

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
        if ($gateError !== null) {
            return response()->json(['message' => $gateError], 422);
        }

        $card = $this->service->changeStage($card, $targetStage);

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
            'file' => "required|file|extensions:" . self::FILE_EXTENSIONS,
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

        if (! $this->canAccess($card, $user)) {
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

    private function canAccess(PipelineCard $card, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $card->owner_id === $user->id;
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
            'amount'           => $card->amount,
            'worked_area_m2'   => $card->worked_area_m2,
            'days_in_stage'    => $card->daysInStage(),
            'is_stagnant'      => $card->isStagnant(),
            'stage_changed_at' => $card->stage_changed_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(PipelineCard $card, User $user): array
    {
        return array_merge($this->format($card), [
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
            'latest_quote_id'       => $card->sales_project_id !== null
                ? Quote::where('sales_project_id', $card->sales_project_id)
                    ->whereNotNull('confirmed_at')
                    ->orderByDesc('confirmed_at')
                    ->value('id')
                : null,
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
