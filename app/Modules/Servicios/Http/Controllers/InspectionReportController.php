<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\InspectionReportAccessDeniedException;
use App\Modules\Servicios\Http\Requests\SaveInspectionReportRequest;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InspectionReportPhoto;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\InspectionReportPdfService;
use App\Modules\Servicios\Services\InspectionReportService;
use App\Shared\Services\AuditService;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243) + Batch 9 (REQ-244). Anidado bajo
 * /servicios/tickets/{ticketId}/inspection-report.
 */
final class InspectionReportController extends Controller
{
    public function __construct(
        private readonly InspectionReportService $reports,
        private readonly InspectionReportPdfService $pdf,
    ) {}

    /** GET /api/servicios/tickets/{ticketId}/inspection-report/fields — REQ-239, catálogo dinámico para renderizar el formulario. */
    public function fields(int $ticketId): JsonResponse
    {
        $ticket = Ticket::with('ticketProducts')->find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->reports->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Informe de Inspección.'], 422);
        }

        return response()->json(['data' => $this->reports->fieldsForTicket($ticket)]);
    }

    /** GET /api/servicios/tickets/{ticketId}/inspection-report — REQ-244, ver informe (o 404 si no existe todavía). */
    public function show(int $ticketId): JsonResponse
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $report = $this->reports->findByTicket($ticket);
        if ($report === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->serialize($report));
    }

    /**
     * GET /api/servicios/tickets/{ticketId}/inspection-report/pdf — REQ-244, informe lleno.
     * Requiere que el informe ya exista (404 si no) — el frontend solo ofrece este botón después
     * de Completado, ver `InspectionReportModal.tsx`.
     */
    public function pdf(int $ticketId): Response
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            abort(404);
        }

        $report = $this->reports->findByTicket($ticket);
        if ($report === null) {
            abort(404);
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — informe lleno con datos reales del
        // ticket/cliente. pdfBlank() (plantilla vacía por tipo/subtipo) queda fuera a propósito.
        app(AuditService::class)->log(
            action:     AuditService::SERVICIOS_INSPECTION_REPORT_PDF_DOWNLOADED,
            entityType: 'inspection_report',
            entityId:   $report->id,
        );

        return $this->pdf->generate($report, $ticket)->download("Informe-Inspeccion-{$report->numero}.pdf");
    }

    /**
     * GET /api/servicios/tickets/{ticketId}/inspection-report/pdf/blank — REQ-244, plantilla en
     * blanco por tipo/subtipo. No requiere informe existente — RN "Descargar plantilla en blanco":
     * Aaron + técnico interno (rol validado en la ruta, más angosto que el resto del recurso).
     */
    public function pdfBlank(int $ticketId): Response
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            abort(404);
        }

        if (! $this->reports->isApplicable($ticket)) {
            abort(422, 'Este tipo de ticket no genera Informe de Inspección.');
        }

        return $this->pdf->generateBlank($ticket)->download("Informe-Inspeccion-{$ticket->numero}-plantilla.pdf");
    }

    /**
     * PUT /api/servicios/tickets/{ticketId}/inspection-report — REQ-238→243, "Guardar informe".
     * Crea en el primer guardado (asigna folio, RN1) y actualiza después — RN5 (REQ-238): solo
     * Aaron/Líder de Servicios o el técnico interno asignado a este ticket.
     */
    public function save(SaveInspectionReportRequest $request, int $ticketId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->reports->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Informe de Inspección.'], 422);
        }

        try {
            $this->reports->assertCanEdit($ticket, $user);
        } catch (InspectionReportAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        /** @var array{
         *   fecha_inspeccion?: ?string, hora_inicio?: ?string, hora_fin?: ?string,
         *   internal_technician_id?: ?int,
         *   findings?: list<array{label: string, value: ?string, ticket_product_id?: ?int, is_additional?: bool}>,
         *   materiales?: list<array{nombre_material: string, cantidad: string}>,
         *   conclusion?: ?string, requiere_seguimiento?: bool, proximos_pasos?: ?string,
         *   firma_tecnico_nombre?: ?string, firma_cliente_nombre?: ?string,
         * } $validated
         */
        $validated = $request->validated();

        try {
            $report = $this->reports->save($ticket, $validated, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($report));
    }

    /**
     * POST /api/servicios/tickets/{ticketId}/inspection-report/mobile — REQ-251→254, Informe
     * móvil. Multipart (no PUT+JSON, mismo motivo que `saveArchivoMode()` abajo: PHP no puebla
     * `$_FILES` en PUT sin method-spoofing): `payload` es el mismo shape JSON que
     * `SaveInspectionReportRequest` codificado como string (los campos anidados
     * `findings`/`materiales` son inviables de mandar como multipart plano desde un formulario
     * móvil), `firma_cliente_imagen` es el archivo de la firma gráfica (RN5 REQ-253). Una sola
     * llamada — RN4 (REQ-252): nada se persiste hasta que la firma se confirma, ver docblock de
     * `InspectionReportService::save()`.
     */
    public function saveMobile(Request $request, int $ticketId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->reports->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Informe de Inspección.'], 422);
        }

        try {
            $this->reports->assertCanEdit($ticket, $user);
        } catch (InspectionReportAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        // SCRUM-316 (rebote QA 2026-08-14) — RN4 de este mismo ticket ya decía "no se puede
        // modificar una firma ya confirmada"; la implementación original solo lo hacía cumplir
        // DENTRO de la sesión (el <canvas> desaparece del DOM tras confirmar), no contra el
        // servidor: reentrar a "Ver en móvil" de un informe ya firmado reabría el formulario
        // editable y "Continuar a firmar" reemplazaba la firma confirmada del cliente en
        // silencio, sin el cliente presente. Se hace cumplir acá, del lado servidor, igual que
        // el gate de "ya completada" en Hoja de Reclamo (`ClaimSheetController`).
        $existing = $this->reports->findByTicket($ticket);
        if ($existing !== null && $existing->firma_cliente_imagen !== null) {
            return response()->json(['message' => 'Esta firma ya fue confirmada y no puede reemplazarse.'], 409);
        }

        $validated = $request->validate([
            'payload'              => ['required', 'string'],
            'firma_cliente_imagen' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($validated['payload'], true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'payload inválido.'], 422);
        }

        // El payload viaja como JSON string (ver docblock arriba), así que nunca pasa por el
        // pipeline de validación normal de `SaveInspectionReportRequest` (PUT .../inspection-report)
        // — sin esto, un payload sin `conclusion` llegaría directo a
        // InspectionReportService::save(), que asume esa validación ya ocurrió (ver su docblock:
        // "conclusión obligatoria ya garantiza que llegar acá es un guardado válido"). Se revalida
        // acá con el MISMO ruleset (una sola fuente de verdad), no una copia que pueda divergir.
        $payloadValidator = Validator::make($payload, (new SaveInspectionReportRequest())->rules());
        $payloadValidator->after(function ($v) use ($payload): void {
            $conclusion = $payload['conclusion'] ?? null;
            if (! is_string($conclusion) || trim($conclusion) === '') {
                $v->errors()->add('conclusion', 'La conclusión es obligatoria para completar el informe.');
            }
        });
        if ($payloadValidator->fails()) {
            return response()->json(['message' => $payloadValidator->errors()->first()], 422);
        }

        // REQ-253 RN1 — nombre obligatorio antes de firmar, específico del flujo móvil (el informe
        // de escritorio no lo exige, `firma_cliente_nombre` es `nullable` en el ruleset de arriba).
        if (trim((string) ($payload['firma_cliente_nombre'] ?? '')) === '') {
            return response()->json(['message' => 'El nombre de quien firma es obligatorio.'], 422);
        }

        /** @var \Illuminate\Http\UploadedFile $firma */
        $firma = $validated['firma_cliente_imagen'];

        try {
            $report = $this->reports->save($ticket, $payload, $user, $firma);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CircuitOpenException) {
            return response()->json(['message' => 'Storage service temporarily unavailable. Please try again in a few minutes.'], 503);
        }

        return response()->json($this->serialize($report));
    }

    /**
     * POST /api/servicios/tickets/{ticketId}/inspection-report/upload — REQ-243, modo alternativo
     * "Súbelo aquí". POST (no PUT) a propósito — ver docblock de
     * `InspectionReportService::save()`. `archivo` es opcional salvo en el primer guardado de
     * este modo (RN: sin archivo no hay nada que marcar Completado).
     */
    public function saveArchivoMode(Request $request, int $ticketId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->reports->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Informe de Inspección.'], 422);
        }

        try {
            $this->reports->assertCanEdit($ticket, $user);
        } catch (InspectionReportAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $validated = $request->validate([
            'archivo'                => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:15360'],
            'firma_tecnico_nombre'   => ['nullable', 'string', 'max:255'],
            'firma_cliente_nombre'   => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $report = $this->reports->saveArchivoMode(
                $ticket,
                $validated['archivo'] ?? null,
                $validated['firma_tecnico_nombre'] ?? null,
                $validated['firma_cliente_nombre'] ?? null,
                $user,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CircuitOpenException) {
            return response()->json(['message' => 'Storage service temporarily unavailable. Please try again in a few minutes.'], 503);
        }

        return response()->json($this->serialize($report));
    }

    /** POST /api/servicios/tickets/{ticketId}/inspection-report/photos — REQ-241 RN1, fotos "antes"/"después". */
    public function storePhoto(Request $request, int $ticketId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $report = $this->reports->findByTicket($ticket);
        if ($report === null) {
            return response()->json(['message' => 'Guarda el informe antes de adjuntar fotos.'], 422);
        }

        try {
            $this->reports->assertCanEdit($ticket, $user);
        } catch (InspectionReportAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $validated = $request->validate([
            'categoria' => ['required', 'string', Rule::in(InspectionReportPhoto::CATEGORIAS)],
            'file'      => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:15360'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        try {
            $photo = $this->reports->uploadPhoto($report, $file, $validated['categoria'], $user->id);
        } catch (CircuitOpenException) {
            return response()->json(['message' => 'Storage service temporarily unavailable. Please try again in a few minutes.'], 503);
        }

        return response()->json([
            'id'        => $photo->id,
            'categoria' => $photo->categoria,
            'url'       => $this->reports->presignedPhotoUrl($photo),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(InspectionReport $report): array
    {
        return [
            'id'                      => $report->id,
            'numero'                  => $report->numero,
            'ticket_id'               => $report->ticket_id,
            'modo'                    => $report->modo,
            'fecha_inspeccion'        => $report->fecha_inspeccion?->toDateString(),
            'hora_inicio'             => $report->hora_inicio,
            'hora_fin'                => $report->hora_fin,
            'internal_technician_id'  => $report->internal_technician_id,
            'findings'                => $report->findings ?? [],
            'materiales'              => $report->materials->map(fn ($m) => [
                'id'                 => $m->id,
                'nombre_material'    => $m->nombre_material,
                'cantidad'           => $m->cantidad,
                'catalog_product_id' => $m->catalog_product_id,
            ])->all(),
            'fotos' => $report->photos->map(fn (InspectionReportPhoto $p) => [
                'id'        => $p->id,
                'categoria' => $p->categoria,
                'url'       => $this->reports->presignedPhotoUrl($p),
            ])->all(),
            'conclusion'              => $report->conclusion,
            'requiere_seguimiento'    => $report->requiere_seguimiento,
            'proximos_pasos'          => $report->proximos_pasos,
            'firma_tecnico_nombre'    => $report->firma_tecnico_nombre,
            'firma_cliente_nombre'    => $report->firma_cliente_nombre,
            'firma_cliente_imagen_url' => $report->firma_cliente_imagen !== null
                ? Storage::disk('s3-private')->temporaryUrl($report->firma_cliente_imagen, now()->addMinutes(15))
                : null,
            'archivo_adjunto_url' => $this->reports->presignedArchivoUrl($report),
            'estado'              => $report->estado,
            'created_at'          => $report->created_at->toIso8601String(),
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
