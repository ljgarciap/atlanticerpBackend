<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Servicios\Events\InspectionReportCompleted;
use App\Modules\Servicios\Exceptions\InspectionReportAccessDeniedException;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InspectionReportMaterial;
use App\Modules\Servicios\Models\InspectionReportPhoto;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243, docs/architecture/servicios-fase4-diseno.md sección
 * 4/8). Informe de Inspección — datos generales, hallazgos dinámicos por tipo/subtipo (REQ-239),
 * materiales (REQ-240 RN1/RN2 solamente — RN3/RN4 diferidos a Batch 14, ver docblock de la
 * migración de `inspection_report_materials`), fotos/conclusión/seguimiento (REQ-241), firmas de
 * escritorio (REQ-242) y modo alternativo de archivo subido (REQ-243).
 */
final class InspectionReportService
{
    private const DISK = 's3-private';

    private const PRESIGNED_URL_TTL = 15;

    // REQ-239 RN1 — bloque por producto para tickets Retrofit con productos asociados.
    private const RETROFIT_PRODUCT_FIELDS = [
        ['key' => 'estado_actual_producto', 'label' => 'Estado actual del producto', 'type' => 'select', 'options' => ['Funcional', 'Deteriorado', 'Obsoleto / fuera de catálogo'], 'quote_recommendation' => false],
        ['key' => 'observacion_tecnica', 'label' => 'Observación técnica', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
    ];

    // REQ-239 RN2 — bloque por producto para cualquier otro tipo con productos asociados (ej. Garantías).
    private const NON_RETROFIT_PRODUCT_FIELDS = [
        ['key' => 'diagnostico_producto', 'label' => 'Diagnóstico', 'type' => 'select', 'options' => ['Defecto de fábrica', 'Mal uso / desgaste', 'Sin hallazgo'], 'quote_recommendation' => false],
        ['key' => 'observacion_especifica', 'label' => 'Observación específica', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
    ];

    /**
     * REQ-239 RN3 — campos estáticos cuando el ticket NO tiene productos asociados, por
     * tipo/subtipo. `quote_recommendation: true` marca el campo que REQ-239 RN5/REQ-229 RN5 debe
     * precargar como observación inicial de la Cotización — asignado acá, en el catálogo, nunca
     * inferido del texto del label (los 3 nombres distintos que usa RN3 son justo el problema que
     * este flag resuelve).
     *
     * Claves: "{comportamiento}" o "{comportamiento}:{subtipo}" (Fase 7b: el comportamiento del tipo de ticket, no su clave) — se busca
     * primero por comportamiento:subtipo, luego por comportamiento solo.
     *
     * @var array<string, list<array{key: string, label: string, type: string, options: list<string>|null, quote_recommendation: bool}>>
     */
    private const STATIC_FIELDS_BY_TIPO = [
        TicketTypes::INSTALACION.':'.Ticket::SUBTIPO_INSPECTION => [
            ['key' => 'checklist_tecnico', 'label' => 'Checklist técnico', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'recomendacion_cotizacion', 'label' => 'Recomendación para cotización', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => true],
        ],
        TicketTypes::INSTALACION => [
            ['key' => 'checklist_instalacion', 'label' => 'Checklist de instalación', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'observaciones_cierre', 'label' => 'Observaciones de cierre', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
        ],
        TicketTypes::GARANTIA.':'.Ticket::SUBTIPO_REPLACEMENT_INSPECTION => [
            ['key' => 'evaluacion_desgaste', 'label' => 'Evaluación de desgaste', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'evidencia_vida_util', 'label' => 'Evidencia de vida útil cumplida', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'recomendacion', 'label' => 'Recomendación', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => true],
        ],
        TicketTypes::GARANTIA => [
            ['key' => 'descripcion_falla', 'label' => 'Descripción de la falla', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'diagnostico', 'label' => 'Diagnóstico', 'type' => 'select', 'options' => ['Defecto de fábrica', 'Mal uso / desgaste', 'Sin hallazgo'], 'quote_recommendation' => false],
            ['key' => 'aplica_garantia', 'label' => '¿Aplica garantía?', 'type' => 'select', 'options' => ['Sí', 'No'], 'quote_recommendation' => false],
            ['key' => 'accion_recomendada', 'label' => 'Acción recomendada', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => true],
        ],
        TicketTypes::RETROFIT => [
            ['key' => 'hallazgos_inspeccion', 'label' => 'Hallazgos de la inspección', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'detalle_productos_cambiar', 'label' => 'Detalle de productos a cambiar', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => false],
            ['key' => 'recomendacion_cotizacion_retrofit', 'label' => 'Recomendación para cotización', 'type' => 'textarea', 'options' => null, 'quote_recommendation' => true],
        ],
    ];

    public function __construct(
        private readonly InspectionReportFolioService $folios,
        private readonly TechnicianSpecialtyService $specialty,
        private readonly KardexService $kardex,
        private readonly InsumoService $insumos,
    ) {}

    /** REQ-238 UBICACION — solo tickets Instalación/Garantías/Retrofit generan informe; Reclamos usan Hoja de Reclamo (REQ-278). */
    public function isApplicable(Ticket $ticket): bool
    {
        return ! TicketTypes::is($ticket->tipo, TicketTypes::RECLAMO);
    }

    /**
     * REQ-239 — catálogo de campos dinámicos para este ticket, usado por el frontend para
     * renderizar el formulario y por `save()` para tagear `is_quote_recommendation`.
     *
     * @return list<array{key: string, label: string, type: string, options: list<string>|null, quote_recommendation: bool, ticket_product_id: int|null}>
     */
    public function fieldsForTicket(Ticket $ticket): array
    {
        $products = $ticket->ticketProducts()->get();

        if ($products->isNotEmpty()) {
            $template = TicketTypes::is($ticket->tipo, TicketTypes::RETROFIT) ? self::RETROFIT_PRODUCT_FIELDS : self::NON_RETROFIT_PRODUCT_FIELDS;

            $fields = [];
            foreach ($products as $product) {
                foreach ($template as $field) {
                    $fields[] = $field + ['ticket_product_id' => $product->id];
                }
            }

            return $fields;
        }

        $comportamiento = TicketTypes::comportamiento($ticket->tipo);
        $byTipoSubtipo = $ticket->subtipo !== null ? self::STATIC_FIELDS_BY_TIPO["{$comportamiento}:{$ticket->subtipo}"] ?? null : null;
        $template       = $byTipoSubtipo ?? self::STATIC_FIELDS_BY_TIPO[$comportamiento] ?? [];

        return array_values(array_map(static fn (array $f): array => $f + ['ticket_product_id' => null], $template));
    }

    /**
     * REQ-238 RN5 — solo Lider Servicios/Líder de Servicios (superadmin) o el técnico interno asignado a
     * ESTE ticket pueden generar/editar su informe. Gerencia y el resto quedan solo con lectura
     * (validado por la Matriz de Permisos por Rol del Excel, fila "Generar/editar Informe de
     * Inspección").
     *
     * @throws InspectionReportAccessDeniedException
     */
    public function assertCanEdit(Ticket $ticket, User $user): void
    {
        $roleKey = $user->role?->key;

        if ($roleKey === RoleKeys::SUPERADMIN || $roleKey === RoleKeys::LIDER_SERVICIOS) {
            return;
        }

        if (($roleKey === RoleKeys::TECNICO || $roleKey === RoleKeys::TECNICO_GARANTIAS)
            && $ticket->internal_technician_id === $user->id
        ) {
            return;
        }

        throw new InspectionReportAccessDeniedException();
    }

    public function findByTicket(Ticket $ticket): ?InspectionReport
    {
        return InspectionReport::where('ticket_id', $ticket->id)
            ->with(['materials', 'photos'])
            ->first();
    }

    /**
     * Upsert del informe en modo formulario (REQ-238→242) — JSON puro, sin archivo. El modo
     * archivo_subido (REQ-243) usa `saveArchivoMode()`, un endpoint POST separado: PHP no puebla
     * `$_FILES` en un request PUT con `multipart/form-data` sin method-spoofing, y ningún otro
     * endpoint de este proyecto lo usa — separar por modo evita ese problema por completo en vez
     * de introducir el único caso de spoofing del código base.
     *
     * "Guardar informe" es una única acción, no hay estado intermedio de borrador (ningún REQ de
     * este batch describe uno). RN1 (REQ-238): el número se asigna al guardar por primera vez.
     * RN4 (REQ-239): hallazgos adicionales vacíos se descartan.
     *
     * Batch 10 (REQ-251→254) — `firmaClienteImagen`, cuando llega, es la firma gráfica capturada en
     * el Informe móvil (RN5 REQ-253: debe guardarse como imagen). El informe de escritorio nunca la
     * manda (permanece `null`, comportamiento sin cambios). RN4 (REQ-252): en móvil, "Guardar
     * informe" NO persiste nada hasta que la firma se confirma — el frontend retiene el formulario
     * completo en memoria entre la pantalla de datos y la de firma, y llama a este mismo método una
     * sola vez, con el archivo ya incluido, al confirmar la firma. No existe un estado "guardado sin
     * firmar" en el backend — mismo principio ya sentado en Batch 8 ("Guardar informe es una única
     * acción, no hay estado intermedio de borrador").
     *
     * @param array{
     *   fecha_inspeccion?: ?string, hora_inicio?: ?string, hora_fin?: ?string,
     *   internal_technician_id?: ?int,
     *   findings?: list<array{label: string, value: ?string, ticket_product_id?: ?int, is_additional?: bool}>,
     *   materiales?: list<array{nombre_material: string, cantidad: string, catalog_product_id?: ?int}>,
     *   conclusion?: ?string, requiere_seguimiento?: bool, proximos_pasos?: ?string,
     *   firma_tecnico_nombre?: ?string, firma_cliente_nombre?: ?string,
     * } $data
     * @throws \InvalidArgumentException REQ-238 RN4 — el técnico elegido no atiende este tipo de ticket.
     * @throws \App\Shared\Support\CircuitOpenException almacenamiento S3 temporalmente no disponible (solo si $firmaClienteImagen !== null).
     */
    public function save(Ticket $ticket, array $data, User $user, ?UploadedFile $firmaClienteImagen = null): InspectionReport
    {
        if (! empty($data['internal_technician_id'])) {
            $technician = User::find($data['internal_technician_id']);

            if ($technician === null || ! $this->specialty->matches($technician, $ticket->tipo)) {
                throw new \InvalidArgumentException('El técnico seleccionado no atiende este tipo de ticket.');
            }
        }

        // Subida a S3 ANTES de abrir la transacción — I/O de red no debe mantener una transacción
        // de Postgres (con lockForUpdate) abierta mientras espera a S3. Sin cleanup de una firma
        // previa: reemplazar una firma ya confirmada no es un caso real (RN4 REQ-253: el lienzo
        // queda bloqueado tras confirmar), y el trade-off de un objeto huérfano en S3 ante un
        // reintento es el mismo ya aceptado en `saveArchivoMode()`.
        $firmaClienteImagenKey = $firmaClienteImagen !== null
            ? $this->uploadSignatureImage($ticket, $firmaClienteImagen)
            : null;

        return $this->withUniqueRetry(fn (): InspectionReport => DB::transaction(function () use ($ticket, $data, $user, $firmaClienteImagenKey): InspectionReport {
            [$report, $isNew] = $this->beginUpsert($ticket, $user);

            $report->modo                    = InspectionReport::MODO_FORMULARIO;
            $report->fecha_inspeccion        = isset($data['fecha_inspeccion']) ? \Carbon\Carbon::parse($data['fecha_inspeccion']) : null;
            $report->hora_inicio             = $data['hora_inicio'] ?? null;
            $report->hora_fin                = $data['hora_fin'] ?? null;
            $report->internal_technician_id  = $data['internal_technician_id'] ?? null;
            $report->findings                = $this->normalizeFindings($ticket, $data['findings'] ?? []);
            $report->conclusion              = $data['conclusion'] ?? null;
            $report->requiere_seguimiento    = $data['requiere_seguimiento'] ?? false;
            // REQ-241 RN3 — si no requiere seguimiento, próximos_pasos nunca se guarda.
            $report->proximos_pasos          = $report->requiere_seguimiento ? ($data['proximos_pasos'] ?? null) : null;
            $report->archivo_adjunto         = null;
            $report->firma_tecnico_nombre    = $this->blankToNull($data['firma_tecnico_nombre'] ?? null);
            $report->firma_cliente_nombre    = $this->blankToNull($data['firma_cliente_nombre'] ?? null);

            if ($firmaClienteImagenKey !== null) {
                $report->firma_cliente_imagen = $firmaClienteImagenKey;
            }

            // RN2 (REQ-241, vía validación de la Request) — conclusión obligatoria ya garantiza que
            // llegar acá es un guardado válido y completo.
            $report->estado                  = InspectionReport::ESTADO_COMPLETED;
            $report->save();

            $this->replaceMaterials($report, $data['materiales'] ?? []);

            return $this->finishUpsert($ticket, $report, $user, $isNew);
        }));
    }

    private function uploadSignatureImage(Ticket $ticket, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension()) ?: 'png';
        $key = "servicios/inspection-reports/{$ticket->id}/firma-cliente/".Str::uuid()->toString().".{$ext}";

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $key;
    }

    /**
     * REQ-243 — modo alternativo "Súbelo aquí". `archivo` es opcional: nulo cuando el usuario solo
     * edita las firmas de un informe que ya tiene un archivo adjunto de un guardado anterior (RN2:
     * "editar" sigue permitido después de Completado, ver REQ-238 RN5) — pero es obligatorio en el
     * PRIMER guardado de este modo (sin archivo no hay nada que marcar como Completado).
     *
     * @throws \InvalidArgumentException si es el primer guardado y no llega archivo.
     * @throws \App\Shared\Support\CircuitOpenException almacenamiento S3 temporalmente no disponible.
     */
    public function saveArchivoMode(Ticket $ticket, ?UploadedFile $archivo, ?string $firmaTecnico, ?string $firmaCliente, User $user): InspectionReport
    {
        return $this->withUniqueRetry(fn (): InspectionReport => DB::transaction(function () use ($ticket, $archivo, $firmaTecnico, $firmaCliente, $user): InspectionReport {
            [$report, $isNew] = $this->beginUpsert($ticket, $user);

            if ($isNew && $archivo === null) {
                throw new \InvalidArgumentException('Adjunta un archivo para completar el informe en este modo.');
            }

            $report->modo                   = InspectionReport::MODO_ARCHIVO_SUBIDO;
            $report->fecha_inspeccion       = null;
            $report->hora_inicio            = null;
            $report->hora_fin               = null;
            $report->internal_technician_id = null;
            $report->findings               = null;
            $report->conclusion             = null;
            $report->requiere_seguimiento   = false;
            $report->proximos_pasos         = null;

            if ($archivo !== null) {
                $previousKey = $report->archivo_adjunto;
                $ext         = strtolower($archivo->getClientOriginalExtension());
                $key         = "servicios/inspection-reports/{$ticket->id}/archivo/".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

                (new CircuitBreaker(self::DISK))->call(function () use ($archivo, $key): void {
                    $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $archivo, basename($key));

                    if ($uploaded === false) {
                        throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
                    }
                });

                $report->archivo_adjunto = $key;

                if ($previousKey !== null) {
                    try {
                        Storage::disk(self::DISK)->delete($previousKey);
                    } catch (\Throwable $e) {
                        Log::error('inspection_report_service.previous_archivo_cleanup_failed', ['key' => $previousKey, 'error' => $e->getMessage()]);
                    }
                }
            }

            $report->firma_tecnico_nombre = $this->blankToNull($firmaTecnico);
            $report->firma_cliente_nombre = $this->blankToNull($firmaCliente);
            // RN2 — guardar en este modo marca el informe como Completado, igual que el formulario.
            $report->estado               = InspectionReport::ESTADO_COMPLETED;
            $report->save();

            $report->materials()->delete();

            return $this->finishUpsert($ticket, $report, $user, $isNew);
        }));
    }

    /**
     * Hallazgo Pre-QA (2026-08-10, doble submit real vía curl concurrente sobre un ticket sin
     * informe todavía): `beginUpsert()` usa `lockForUpdate()` sobre `ticket_id`, pero bajo READ
     * COMMITTED eso no bloquea nada cuando la fila TODAVÍA no existe — dos transacciones
     * concurrentes pueden pasar ambas por el `SELECT ... FOR UPDATE` vacío, y la segunda en
     * confirmar pierde la carrera del lado de Postgres (`UniqueConstraintViolationException` sobre
     * `servicios_inspection_reports_ticket_uq`) en vez de terminar actualizando la fila que la
     * primera ya creó. Reintentar una vez alcanza: para cuando el segundo intento reintenta, la
     * primera transacción ya commiteó, así que `beginUpsert()` esta vez sí encuentra la fila real y
     * la actualiza. (En `saveArchivoMode()` un reintento en esta rama puede dejar huérfano el
     * archivo ya subido a S3 por el intento perdedor — aceptable frente a la alternativa de un 500
     * con stack trace expuesto al cliente; no se resuelve acá, ver `docs/pre-qa/` de esta sesión.)
     */
    private function withUniqueRetry(callable $attempt): InspectionReport
    {
        try {
            return $attempt();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'servicios_inspection_reports_ticket_uq')) {
                throw $e;
            }

            return $attempt();
        }
    }

    /** @return array{0: InspectionReport, 1: bool} [informe (nuevo o existente, bloqueado con lockForUpdate), esNuevo] */
    private function beginUpsert(Ticket $ticket, User $user): array
    {
        $report = InspectionReport::where('ticket_id', $ticket->id)->lockForUpdate()->first();
        $isNew  = $report === null;

        if ($isNew) {
            $report             = new InspectionReport();
            $report->numero     = $this->folios->next();
            $report->ticket_id  = $ticket->id;
            $report->created_by = $user->id;
        }

        return [$report, $isNew];
    }

    /**
     * `$applyConsumption` (Grupo D parte 2, REQ-240 RN3/RN4, REQ-291 RN3(c)/RN4, SCRUM-361) —
     * true SOLO en la primera vez que este informe se completa (`$isNew` de `beginUpsert()`). El
     * Consumo real del Kardex se aplica una única vez por informe, nunca en ediciones
     * posteriores — `replaceMaterials()` borra y recrea las filas en CADA guardado (mismo patrón
     * ya usado para `findings`), así que aplicar Consumo en cada edición duplicaría la salida de
     * inventario cada vez que alguien corrige una observación sin tocar los materiales. Si un
     * material vinculado a un insumo necesita corregirse después de la primera vez, la corrección
     * de inventario es manual (Ajuste de Bodega) — mismo criterio que el resto del código no tiene
     * un mecanismo de "deshacer" automático para efectos de stock ya aplicados (ver
     * `GoodsReceiptConfirmationService`, que tampoco revierte una confirmación).
     */
    private function finishUpsert(Ticket $ticket, InspectionReport $report, User $user, bool $applyConsumption): InspectionReport
    {
        $report = $report->refresh()->load(['materials', 'photos']);

        if ($applyConsumption) {
            $this->consumeMaterialsFromReserve($report, $user);
        }

        // REQ-218/REQ-220 — gate de cierre del ticket: `tickets.inspection_report_status` es
        // denormalizado a propósito (ver docblock de la migración de `tickets`), nada lo
        // actualizaba desde Batch 1 porque este módulo no existía todavía. Sin este update el
        // ticket queda bloqueado en el gate de REQ-218 para siempre, aunque el informe ya esté
        // Completado.
        $ticket->inspection_report_status = Ticket::INSPECTION_STATUS_COMPLETED;
        $ticket->save();

        event(new InspectionReportCompleted(
            inspectionReportId: $report->id,
            numero:              $report->numero,
            ticketId:            $ticket->id,
            materiales:          array_values($report->materials->map(fn (InspectionReportMaterial $m): array => [
                'nombre_material' => $m->nombre_material,
                'cantidad'        => $m->cantidad,
            ])->all()),
            tenant:              $this->requiredTenantSlug(),
        ));

        return $report;
    }

    public function uploadPhoto(InspectionReport $report, UploadedFile $file, string $categoria, int $uploadedBy): InspectionReportPhoto
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "servicios/inspection-reports/{$report->id}/{$categoria}/".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        try {
            return InspectionReportPhoto::create([
                'inspection_report_id' => $report->id,
                'categoria'             => $categoria,
                'storage_key'           => $key,
                'uploaded_by'           => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            try {
                Storage::disk(self::DISK)->delete($key);
            } catch (\Throwable $se) {
                Log::error('inspection_report_service.photo_cleanup_failed', ['key' => $key, 'error' => $se->getMessage()]);
            }

            throw $e;
        }
    }

    public function presignedPhotoUrl(InspectionReportPhoto $photo): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($photo->storage_key, now()->addMinutes(self::PRESIGNED_URL_TTL));
    }

    public function presignedArchivoUrl(InspectionReport $report): ?string
    {
        if ($report->archivo_adjunto === null) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl($report->archivo_adjunto, now()->addMinutes(self::PRESIGNED_URL_TTL));
    }

    /**
     * @param list<array{label: string, value: ?string, ticket_product_id?: ?int, is_additional?: bool}> $incoming
     * @return list<array{label: string, value: string, ticket_product_id: ?int, is_additional: bool, is_quote_recommendation: bool}>
     */
    private function normalizeFindings(Ticket $ticket, array $incoming): array
    {
        $catalog = $this->fieldsForTicket($ticket);

        $normalized = [];

        foreach ($incoming as $entry) {
            $isAdditional = (bool) ($entry['is_additional'] ?? false);
            $value        = trim((string) ($entry['value'] ?? ''));

            // REQ-239 RN4 — hallazgos adicionales vacíos al guardar se omiten, no se guardan en blanco.
            if ($isAdditional && $value === '') {
                continue;
            }

            $ticketProductId = $entry['ticket_product_id'] ?? null;
            $isQuoteRecommendation = false;

            if (! $isAdditional) {
                $match = null;
                foreach ($catalog as $field) {
                    if ($field['label'] === $entry['label'] && $field['ticket_product_id'] === $ticketProductId) {
                        $match = $field;
                        break;
                    }
                }

                if ($match === null) {
                    // Defensivo: el frontend y este catálogo deberían estar siempre sincronizados
                    // (mismo criterio que Ticket::REQUIREMENT_LABELS) — si no matchea, no se
                    // descarta el dato del usuario, solo se deja sin marcar como recomendación.
                    Log::warning('inspection_report_service.finding_label_not_in_catalog', [
                        'ticket_id' => $ticket->id,
                        'label'     => $entry['label'],
                    ]);
                } else {
                    $isQuoteRecommendation = $match['quote_recommendation'];
                }
            }

            $normalized[] = [
                'label'                   => $entry['label'],
                'value'                   => $value,
                'ticket_product_id'       => $ticketProductId,
                'is_additional'           => $isAdditional,
                'is_quote_recommendation' => $isQuoteRecommendation,
            ];
        }

        return $normalized;
    }

    /** @param list<array{nombre_material: string, cantidad: string, catalog_product_id?: ?int}> $materiales */
    private function replaceMaterials(InspectionReport $report, array $materiales): void
    {
        $report->materials()->delete();

        foreach ($materiales as $material) {
            $nombre   = trim((string) $material['nombre_material']);
            $cantidad = trim((string) $material['cantidad']);

            // REQ-240 RN2 — líneas vacías al guardar se omiten.
            if ($nombre === '' || $cantidad === '') {
                continue;
            }

            InspectionReportMaterial::create([
                'inspection_report_id' => $report->id,
                'nombre_material'      => $nombre,
                'cantidad'             => $cantidad,
                'catalog_product_id'   => $material['catalog_product_id'] ?? null,
            ]);
        }
    }

    /**
     * REQ-240 RN3/RN4, REQ-291 RN3(c)/RN4 — dispara el movimiento de Consumo real en el Kardex por
     * cada material vinculado a un insumo real de la Reserva Servicios (`catalog_product_id` no
     * nulo). Material sin vincular (texto libre, todo el histórico previo a este batch) queda
     * intacto, sin efecto de inventario — ver docblock de la migración de `catalog_product_id`.
     *
     * `cantidad` sigue siendo texto libre (columna histórica, ver
     * `InspectionReportMaterial::$cantidad`) — solo se aplica Consumo cuando el valor es
     * numéricamente válido y positivo; si no, se registra en log y se omite sin bloquear el
     * guardado (mismo criterio defensivo que `normalizeFindings()` con un label fuera de
     * catálogo: el dato del usuario nunca se descarta, solo queda sin el efecto automático).
     */
    private function consumeMaterialsFromReserve(InspectionReport $report, User $user): void
    {
        $reservaWarehouseId = $this->insumos->reservaWarehouseId();

        foreach ($report->materials as $material) {
            if ($material->catalog_product_id === null) {
                continue;
            }

            $cantidadTexto = trim($material->cantidad);
            if (! is_numeric($cantidadTexto)) {
                Log::warning('inspection_report_service.material_cantidad_not_numeric', [
                    'inspection_report_id' => $report->id,
                    'material_id'          => $material->id,
                    'cantidad'             => $material->cantidad,
                ]);

                continue;
            }

            $cantidad = (int) $cantidadTexto;
            if ($cantidad <= 0) {
                continue;
            }

            $this->kardex->registrar(
                catalogProductId: $material->catalog_product_id,
                warehouseId: $reservaWarehouseId,
                tipo: InventoryMovement::TIPO_CONSUMO,
                cantidad: -$cantidad,
                referenciaTexto: "Consumo — Informe de Inspección {$report->numero}",
                referenciaEntidadTipo: 'SERVICIOS_INSPECTION_REPORT',
                referenciaEntidadId: $report->id,
                realizadoPor: $user->id,
            );
        }
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
