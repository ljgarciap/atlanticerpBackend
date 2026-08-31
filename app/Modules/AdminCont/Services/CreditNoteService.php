<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\CreditNoteStateException;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Services\CustomerReturnService as BodegaCustomerReturnService;
use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Batch 10 (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones (solo lectura,
 * `summary()`/`activeItbmsRates()`/`itbmsTreatmentForInvoice()`). Batch 11 (SCRUM-559→564,
 * REQ-482→487) agrega el escritor real: `register()`. Ver su docblock para el alcance exacto
 * (deliberadamente NO cubre el subtipo "Corrección de datos" — eso es Batch 12).
 */
final class CreditNoteService
{
    /** Labels de `previewCorreccion()` (tarjeta "Corrección aplicada") — ver docblock ahí. */
    private const MOTIVO_CORRECCION_LABELS = [
        AdminContCreditNote::MOTIVO_CORRECCION_ITBMS => 'Tratamiento de ITBMS incorrecto',
        AdminContCreditNote::MOTIVO_CORRECCION_FECHA => 'Fecha de facturación incorrecta',
        AdminContCreditNote::MOTIVO_CORRECCION_AMBOS => 'Ambos',
    ];

    public function __construct(
        private readonly CreditBalanceService $creditBalance,
        private readonly AdminContCreditNoteFolioService $folio,
        private readonly AdminContCreditNoteAttachmentService $attachments,
        private readonly AdminContInvoiceFolioService $invoiceFolio,
        private readonly BodegaCustomerReturnService $bodegaReturns,
    ) {}

    /**
     * REQ-477 — 5 tarjetas de indicadores del mes: total acreditado, N° de notas, pendientes de
     * aprobación, devoluciones por generar, nota promedio.
     *
     * @return array{total_acreditado: float, numero_notas: int, pendientes_aprobacion_monto: float, pendientes_aprobacion_cantidad: int, devoluciones_por_generar: int, nota_promedio: float, primary_approval_threshold: float}
     */
    public function summary(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $notasMes = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->whereBetween('fecha', [$monthStart, $monthEnd]);

        $numeroNotas = (clone $notasMes)->count();
        $totalAcreditado = (float) (clone $notasMes)->sum('monto');

        $pendientes = AdminContCreditNote::query()->where('estado', AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);
        $pendientesAprobacionCantidad = (clone $pendientes)->count();
        $pendientesAprobacionMonto = (float) (clone $pendientes)->sum('monto');

        // REQ-481/491 — devoluciones confirmadas por Bodega, pendientes solo de generar la nota.
        // Batch 12 conecta la cola real (ver `pendingBodegaReturns()`).
        $devolucionesPorGenerar = count($this->pendingBodegaReturns());

        return [
            'total_acreditado' => $totalAcreditado,
            'numero_notas' => $numeroNotas,
            'pendientes_aprobacion_monto' => $pendientesAprobacionMonto,
            'pendientes_aprobacion_cantidad' => $pendientesAprobacionCantidad,
            'devoluciones_por_generar' => $devolucionesPorGenerar,
            'nota_promedio' => $numeroNotas > 0 ? round($totalAcreditado / $numeroNotas, 2) : 0.0,
            'primary_approval_threshold' => $this->primaryApprovalThreshold(),
        ];
    }

    /**
     * Batch 23 (SCRUM-649, REQ-572) — distribución del mes en curso por los 4 motivos reales.
     * Mismo filtro que `summary()` (`estado = aplicada`, `fecha` dentro del mes calendario en
     * curso) — nunca un filtro paralelo. RN1: los 4 buckets siempre aparecen, aunque el monto sea
     * $0. Mapeo real: `tipo` solo (devolución/descuento) o `tipo` + `subtipo_anulacion` (anulación).
     *
     * @return list<array{motivo: string, monto: float}>
     */
    public function porMotivoMesActual(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $notasMes = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->whereBetween('fecha', [$monthStart, $monthEnd])
            ->get();

        $devolucion = (float) $notasMes->where('tipo', AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA)->sum('monto');
        $descuento = (float) $notasMes->where('tipo', AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL)->sum('monto');
        $anulaciones = $notasMes->where('tipo', AdminContCreditNote::TIPO_ANULACION_COMPLETA);
        $cancelado = (float) $anulaciones->where('subtipo_anulacion', AdminContCreditNote::SUBTIPO_CANCELADO)->sum('monto');
        $correccion = (float) $anulaciones->where('subtipo_anulacion', AdminContCreditNote::SUBTIPO_CORRECCION)->sum('monto');

        return [
            ['motivo' => 'Devolución de mercancía',          'monto' => round($devolucion, 2)],
            ['motivo' => 'Descuento comercial',                'monto' => round($descuento, 2)],
            ['motivo' => 'Anulación — Pedido cancelado',        'monto' => round($cancelado, 2)],
            ['motivo' => 'Anulación — Corrección de datos',     'monto' => round($correccion, 2)],
        ];
    }

    /**
     * Batch 23 Grupo 3 (SCRUM-661→664, REQ-586) — mismo mapeo `tipo`+`subtipo_anulacion` de
     * `porMotivoMesActual()`, extraído acá para que `InvoiceBookService` (columna "Motivo" del
     * Libro de Facturas) use exactamente el mismo label, nunca una copia paralela.
     */
    public static function motivoLabel(AdminContCreditNote $nota): string
    {
        return match (true) {
            $nota->tipo === AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA => 'Devolución de mercancía',
            $nota->tipo === AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL => 'Descuento comercial',
            $nota->subtipo_anulacion === AdminContCreditNote::SUBTIPO_CANCELADO => 'Anulación — Pedido cancelado',
            $nota->subtipo_anulacion === AdminContCreditNote::SUBTIPO_CORRECCION => 'Anulación — Corrección de datos',
            default => 'Anulación',
        };
    }

    /** REQ-482 RN3 — umbral compartido, ver docblock de la migración que lo agrega a `fiscal_settings`. */
    public function primaryApprovalThreshold(): float
    {
        return (float) AdminContFiscalSettings::current()->primary_approval_threshold;
    }

    /**
     * REQ-483 — TODAS las facturas emitidas del cliente (pagadas o no), a diferencia de
     * `PaymentService::openInvoicesForClient()` que descarta las que ya tienen saldo_pendiente=0
     * (Cobros no necesita mostrarlas, pero REQ-484 Escenario 2 sí: una factura ya pagada por
     * completo sigue siendo una `factura_origen_id` válida, cuyo excedente es el 100% del monto).
     * Mismo filtro base que Cobros (`es_incobrable=false`, sin `anulada_at`) — una nota nunca debe
     * referenciar una factura incobrable o ya anulada.
     *
     * @return list<array{id: int, numero: string, monto: float, saldo_pendiente: float, itbms_percentage: float, itbms_rate_id: int|null}>
     */
    public function invoicesForClient(int $masterClientId): array
    {
        $rows = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->where('es_incobrable', false)
            ->whereNull('anulada_at')
            ->with('itbmsRate')
            ->get()
            ->map(fn (AdminContInvoice $i): array => [
                'id' => $i->id,
                'numero' => $i->numero,
                'monto' => $i->monto,
                'saldo_pendiente' => round($i->monto - $i->saldo_aplicado, 2),
                'itbms_percentage' => $i->itbmsRate->porcentaje ?? 0.0,
                // REQ-489 — id real de la tasa ya aplicada, para que "Corrección de datos" pueda
                // excluirla de "tratamiento correcto" (`NotaCreditoFacturaOrigen.itbms_rate_id`,
                // ver docblock del tipo en el frontend — faltaba acá, Pre-QA Batch 12 2026-08-24:
                // sin este campo `facturaActualRateId` siempre resolvía null en el frontend, así
                // que ninguna tasa se excluía y "Tratamiento actual" siempre mostraba "—").
                'itbms_rate_id' => $i->itbms_rate_id,
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * REQ-482→487 — registra la nota completa. Alcance deliberado: cubre `descuento_comercial`,
     * `devolucion_mercancia`, y `anulacion_completa` con `subtipo_anulacion=cancelado`. El subtipo
     * `correccion` (REQ-480, Batch 10 ya construyó su formulario) NO se soporta acá — su submit
     * real es "revisión previa + vista previa de factura nueva", explícitamente Batch 12
     * (`docs/architecture/admin-contab-batches.md`) — `RegisterCreditNoteRequest` lo rechaza con
     * 422 antes de llegar a este método.
     *
     * Reglas de negocio (verificadas contra los 6 tickets, ver comentarios inline):
     *
     * @param array{
     *   master_client_id: int, cliente: string, tipo: string, subtipo_anulacion: ?string,
     *   factura_origen_id: int, monto: ?float, motivo: string, resultado: ?string,
     *   cuenta_bancaria_salida_id: ?int, mercancia_regresa_bodega: ?bool, devolucion_bodega_id: ?int,
     * } $data
     */
    public function register(array $data, ?UploadedFile $comprobante, User $actor): AdminContCreditNote
    {
        $note = DB::transaction(function () use ($data, $actor): AdminContCreditNote {
            /** @var AdminContInvoice $facturaOrigen */
            $facturaOrigen = AdminContInvoice::query()
                ->with('itbmsRate')
                ->lockForUpdate()
                ->findOrFail($data['factura_origen_id']);

            $saldoPendiente = round($facturaOrigen->monto - $facturaOrigen->saldo_aplicado, 2);

            // REQ-484 RN4 — Anulación completa: el monto SIEMPRE es el total exacto de la factura,
            // nunca lo que mande el cliente (si mandó algo distinto, se ignora, no es un error).
            $monto = $data['tipo'] === AdminContCreditNote::TIPO_ANULACION_COMPLETA
                ? $facturaOrigen->monto
                : (float) $data['monto'];

            // REQ-485 RN1 — desglose según la tasa ITBMS de la propia factura de origen (la misma
            // que ya se le aplicó al cliente en esa factura, no una tasa nueva).
            $rate = (float) ($facturaOrigen->itbmsRate->porcentaje ?? 0.0);
            $subtotal = round($monto / (1 + $rate / 100), 2);
            $itbms = round($monto - $subtotal, 2);

            // REQ-484 — si el monto (ya con el override de arriba aplicado) cabe en el saldo
            // pendiente, se aplica sin preguntar nada más (RN1). Esto cubre también RN3 (anulación
            // de una factura nunca cobrada: monto=saldoPendiente=factura.monto, excedente=0) sin
            // necesitar una rama aparte — es el mismo caso límite de RN1.
            $excedente = round($monto - $saldoPendiente, 2);
            if ($excedente > 0) {
                $resultado = $data['resultado']; // 'devuelto' | 'saldo_favor' — ya validado obligatorio en el Form Request
            } else {
                $resultado = AdminContCreditNote::RESULTADO_APLICADO_SALDO;
                $excedente = 0.0;
            }

            // Batch 13 (REQ-494 RN1) — cualquier Anulación completa queda pendiente de aprobación
            // de Mark SIN IMPORTAR el monto, no solo por superar el umbral (a diferencia de los
            // otros 3 tipos). Fix confirmado por Luis sobre el comportamiento de Batch 11: antes
            // de esto, una anulación completa de una factura chica quedaba `aplicada` directo, sin
            // pasar por Mark. Alcance acotado a este método — `registerCorreccion()` (subtipo
            // `correccion`, REQ-490) ya tiene su propia regla intencional de solo-umbral.
            $threshold = $this->primaryApprovalThreshold();
            $estado = ($monto > $threshold || $data['tipo'] === AdminContCreditNote::TIPO_ANULACION_COMPLETA)
                ? AdminContCreditNote::ESTADO_PENDIENTE_APROBACION
                : AdminContCreditNote::ESTADO_APLICADA;

            $note = AdminContCreditNote::create([
                'numero' => $this->folio->next(),
                'master_client_id' => $data['master_client_id'],
                'cliente' => $data['cliente'],
                'tipo' => $data['tipo'],
                'subtipo_anulacion' => $data['subtipo_anulacion'],
                'factura_origen_id' => $facturaOrigen->id,
                'monto' => $monto,
                'subtotal' => $subtotal,
                'itbms' => $itbms,
                'motivo' => $data['motivo'],
                'resultado' => $resultado,
                'cuenta_bancaria_salida_id' => $resultado === AdminContCreditNote::RESULTADO_DEVUELTO ? $data['cuenta_bancaria_salida_id'] : null,
                'estado' => $estado,
                'mercancia_regresa_bodega' => $data['mercancia_regresa_bodega'] ?? null,
                'registrado_por' => $actor->id,
                'fecha' => now()->toDateString(), // REQ-485 RN3 — nunca la fecha que mande el cliente.
                'devolucion_bodega_id' => $data['devolucion_bodega_id'] ?? null,
            ]);

            // REQ-482 — solo si NO quedó pendiente de aprobación se aplica de verdad: saldo de la
            // factura, y el destino del excedente (banco o saldo a favor). Si quedó pendiente,
            // esta decisión ya quedó persistida arriba (resultado/cuenta_bancaria_salida_id) — Batch
            // 13 (`decide()`, exclusivo Mark) llama a `applyToBalance()` para ejecutarla al aprobar,
            // mismo método que este caso inmediato para no duplicar la lógica.
            if ($estado === AdminContCreditNote::ESTADO_APLICADA) {
                $this->applyToBalance($note, $facturaOrigen);
            }

            // REQ-491 — cierra el círculo con Bodega DENTRO de esta misma transacción: si
            // `finalizeForCreditNote()` falla (ej. la devolución ya no está esperando nota), la
            // creación de la nota completa revierte — decisión explícita de Luis, ver
            // ADR-SCRUM565-570 (atómico, no evento+listener con try/catch aislado).
            if (($data['devolucion_bodega_id'] ?? null) !== null) {
                $this->bodegaReturns->finalizeForCreditNote((int) $data['devolucion_bodega_id'], $note->numero, $actor);
            }

            return $note;
        });

        // Subida a S3 (network) FUERA de la transacción — mismo criterio que
        // `PaymentService::register()`: el comprobante no participa de ningún cálculo de saldo, no
        // hace falta mantener el lockForUpdate() de la factura mientras dura la latencia de red.
        if ($comprobante !== null) {
            $key = $this->attachments->upload($comprobante, $note->id);
            $note->update(['comprobante_url' => $key]);
        }

        return $note;
    }

    /**
     * Batch 13 (REQ-482/494) — aplica el efecto real de una nota "normal" (descuento comercial,
     * devolución de mercancía, o Anulación completa vía `register()` — NUNCA "Corrección de
     * datos", esa usa `executeCorreccion()`): suma el monto al saldo aplicado de la factura de
     * origen, y resuelve el destino del excedente (banco o saldo a favor) si corresponde. Recalcula
     * `saldoPendiente`/`excedente` contra el estado ACTUAL de `$facturaOrigen` (bajo lock del
     * llamador), no contra valores guardados de un instante anterior — mismo criterio tanto si se
     * llama desde `register()` (nota recién creada, aplicación inmediata) como desde `decide()`
     * (nota que estuvo pendiente y Mark recién aprueba, posiblemente más tarde). El `resultado`
     * (devuelto/saldo_favor/aplicado_saldo) y `cuenta_bancaria_salida_id` NUNCA se recalculan acá —
     * ya quedaron decididos por el usuario al registrar la nota (REQ-484 RN2), esto solo ejecuta esa
     * decisión.
     */
    private function applyToBalance(AdminContCreditNote $note, AdminContInvoice $facturaOrigen): void
    {
        $saldoPendiente = round($facturaOrigen->monto - $facturaOrigen->saldo_aplicado, 2);
        $excedente = $note->resultado === AdminContCreditNote::RESULTADO_APLICADO_SALDO
            ? 0.0
            : max(0.0, round($note->monto - $saldoPendiente, 2));

        $facturaOrigen->update(['saldo_aplicado' => $facturaOrigen->saldo_aplicado + min($note->monto, $saldoPendiente)]);

        if ($note->resultado === AdminContCreditNote::RESULTADO_DEVUELTO && $excedente > 0) {
            AdminContBankMovement::create([
                'bank_account_id' => $note->cuenta_bancaria_salida_id,
                'fecha' => now()->toDateString(),
                'tipo' => AdminContBankMovement::TIPO_DEVOLUCION,
                'concepto' => "Excedente Nota de Crédito {$note->numero}",
                'monto' => $excedente,
                'source_type' => AdminContBankMovement::SOURCE_CREDIT_NOTE,
                'source_id' => $note->id,
            ]);
        } elseif ($note->resultado === AdminContCreditNote::RESULTADO_SALDO_FAVOR && $excedente > 0) {
            $this->creditBalance->add($note->master_client_id, $excedente);
        }
    }

    /**
     * Batch 13 (REQ-494) — Mark aprueba o rechaza una nota `pendiente_aprobacion`. RN2 bloquea la
     * aprobación si la nota exige comprobante (mismo predicado que `register()`, ver
     * `AdminContCreditNote::requiresComprobante()`) y no lo tiene adjunto. RN3/RN4: al aprobar, se
     * ejecuta el efecto real diferido — `executeCorreccion()` si era "Corrección de datos"
     * (`correccion_pendiente_aprobacion`), o `applyToBalance()` para los otros 3 tipos — y queda
     * `aplicada`. RN5: al rechazar, no se ejecuta nada (ni factura nueva ni movimiento de saldo), la
     * nota queda `rechazada` con el motivo obligatorio.
     */
    public function decide(int $id, bool $approve, ?string $motivoRechazo, User $actor): AdminContCreditNote
    {
        return DB::transaction(function () use ($id, $approve, $motivoRechazo, $actor): AdminContCreditNote {
            /** @var AdminContCreditNote $note */
            $note = AdminContCreditNote::query()->lockForUpdate()->findOrFail($id);

            if ($note->estado !== AdminContCreditNote::ESTADO_PENDIENTE_APROBACION) {
                throw new CreditNoteStateException("La nota {$note->numero} ya no está pendiente de aprobación.");
            }

            if ($approve) {
                $threshold = $this->primaryApprovalThreshold();
                if (AdminContCreditNote::requiresComprobante($note->tipo, $note->monto, $threshold) && $note->comprobante_url === null) {
                    throw new CreditNoteStateException("La nota {$note->numero} requiere comprobante de soporte adjunto antes de poder aprobarse.");
                }

                /** @var AdminContInvoice $facturaOrigen */
                $facturaOrigen = AdminContInvoice::query()->lockForUpdate()->findOrFail($note->factura_origen_id);

                if ($note->correccion_pendiente_aprobacion) {
                    $this->executeCorreccion($note, $facturaOrigen);
                    $note->correccion_pendiente_aprobacion = false;
                } else {
                    $this->applyToBalance($note, $facturaOrigen);
                }

                $note->fill([
                    'estado' => AdminContCreditNote::ESTADO_APLICADA,
                    'aprobado_por' => $actor->id,
                    'fecha_decision' => now()->toDateString(),
                ])->save();
            } else {
                $note->fill([
                    'estado' => AdminContCreditNote::ESTADO_RECHAZADA,
                    'aprobado_por' => $actor->id,
                    'fecha_decision' => now()->toDateString(),
                    'motivo_rechazo' => $motivoRechazo,
                ])->save();
            }

            return $note;
        });
    }

    /**
     * Batch 13 (REQ-495) — comprobante de soporte de una nota. `subido_por`/`fecha` reusan
     * `registrado_por`/`fecha` de la nota — el comprobante solo se sube una vez, al registrar la
     * nota (`register()`/`registerCorreccion()`), no hay flujo de re-adjuntar, así que no hace
     * falta una columna de tracking separada.
     *
     * @return array{tiene_comprobante: bool, url?: string, mime_type?: string, subido_por?: ?string, fecha?: string}|null null si la nota no existe.
     */
    public function comprobante(int $id): ?array
    {
        /** @var AdminContCreditNote|null $note */
        $note = AdminContCreditNote::with('registradoPor')->find($id);
        if ($note === null) {
            return null;
        }

        if ($note->comprobante_url === null) {
            return ['tiene_comprobante' => false];
        }

        return [
            'tiene_comprobante' => true,
            'url' => $this->attachments->presignedUrl($note->comprobante_url),
            'mime_type' => $this->attachments->guessMimeType($note->comprobante_url),
            'subido_por' => $note->registradoPor !== null ? trim("{$note->registradoPor->first_name} {$note->registradoPor->last_name}") : null,
            'fecha' => $note->fecha->toDateString(),
        ];
    }

    /**
     * REQ-480 — catálogo completo de tasas activas, sin contexto de factura todavía (la selección
     * real de factura de origen es REQ-483/Batch 11 — hasta entonces el formulario no puede excluir
     * "la tasa actual" porque no hay ninguna factura elegida, ver `RegistrarNotaCreditoModal`
     * `facturaActualRateId`). Deliberadamente un endpoint propio de Notas Crédito, distinto de
     * `GET /admin-contab/itbms-rates` (ese es `primary_approver_only` — Felix/Yaneth/Gerencia no tienen acceso
     * ahí pero sí necesitan ver el catálogo para elegir "tratamiento correcto").
     *
     * @return list<array{id: int, nombre: string, descripcion: string, porcentaje: float, es_base: bool}>
     */
    public function activeItbmsRates(): array
    {
        return array_values(ItbmsRateResolver::alternativesTo(null)
            ->map(fn (AdminContItbmsRate $rate): array => [
                'id' => $rate->id,
                'nombre' => $rate->nombre,
                'descripcion' => $rate->descripcion,
                'porcentaje' => $rate->porcentaje,
                'es_base' => $rate->es_base,
            ])
            ->all());
    }

    /**
     * REQ-480 — dado el `order_id` de una factura (mismo identificador que usa el resto de
     * Facturación, ver `AdminContInvoiceController::detail()`), el tratamiento ITBMS que tiene hoy
     * (solo lectura) y las tasas activas que puede ofrecer como "tratamiento correcto" (RN1: nunca
     * la misma que ya está aplicada).
     *
     * La selección real de "factura de origen" dentro del formulario de Notas Crédito es REQ-483
     * (Batch 11) — este endpoint solo habilita construir y probar el bloque condicional de REQ-480
     * pasando un `order_id` ya conocido, mientras ese selector no existe todavía.
     *
     * @return array{tratamiento_actual: array{id: int, nombre: string, porcentaje: float}|null, alternativas: list<array{id: int, nombre: string, porcentaje: float}>}|null null si el order no tiene factura generada
     */
    public function itbmsTreatmentForInvoice(int $orderId): ?array
    {
        $invoice = AdminContInvoice::where('order_id', $orderId)->whereNull('anulada_at')->with('itbmsRate')->first();
        if ($invoice === null) {
            return null;
        }

        /** @var AdminContItbmsRate|null $currentRate */
        $currentRate = $invoice->itbmsRate;

        /** @var list<array{id: int, nombre: string, porcentaje: float}> $alternativas */
        $alternativas = ItbmsRateResolver::alternativesTo($currentRate?->id)
            ->map(fn (AdminContItbmsRate $rate): array => ['id' => $rate->id, 'nombre' => $rate->nombre, 'porcentaje' => $rate->porcentaje])
            ->values()
            ->all();

        return [
            'tratamiento_actual' => $currentRate === null ? null : [
                'id' => $currentRate->id,
                'nombre' => $currentRate->nombre,
                'porcentaje' => $currentRate->porcentaje,
            ],
            'alternativas' => $alternativas,
        ];
    }

    /**
     * REQ-492 — historial combinado: notas reales (`AdminContCreditNote`) + filas virtuales de la
     * cola de Bodega (REQ-491, `pendingBodegaReturns()`), mezcladas y ordenadas por fecha (RN1 de
     * REQ-491 — nunca en secciones separadas). Sin paginación real — mismo criterio que
     * `InvoiceService::list()`, el resto de listados de Admin&Cont filtra en memoria sin `page`.
     *
     * @param  array{search?: string|null, cliente?: int|null, tipo?: string|null, estado?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function index(array $filters): array
    {
        $registradoPorIds = AdminContCreditNote::query()->pluck('registrado_por', 'id');
        $users = User::whereIn('id', $registradoPorIds->unique()->values())->get()->keyBy('id');

        $rows = AdminContCreditNote::query()
            ->with('facturaOrigen')
            ->get()
            ->map(function (AdminContCreditNote $note) use ($users): array {
                /** @var User|null $registradoPor */
                $registradoPor = $users->get($note->registrado_por);

                return [
                    'id' => $note->id,
                    'tipo' => $note->tipo,
                    'subtipo_anulacion' => $note->subtipo_anulacion,
                    'numero' => $note->numero,
                    'cliente' => $note->cliente,
                    'master_client_id' => $note->master_client_id,
                    'estado' => $note->estado,
                    'monto' => $note->monto,
                    'fecha' => $note->fecha->toDateString(),
                    'factura_origen_id' => $note->factura_origen_id,
                    'factura_origen_numero' => $note->facturaOrigen?->numero,
                    'registrado_por' => $registradoPor !== null ? trim("{$registradoPor->first_name} {$registradoPor->last_name}") : null,
                    'devolucion_bodega_id' => $note->devolucion_bodega_id,
                    'customer_return_id' => null,
                ];
            })
            ->all();

        $rows = array_merge($rows, $this->pendingBodegaReturns());

        $search = isset($filters['search']) && trim((string) $filters['search']) !== '' ? mb_strtolower(trim((string) $filters['search'])) : null;
        $rows = array_values(array_filter($rows, function (array $row) use ($filters, $search): bool {
            if (($filters['cliente'] ?? null) !== null && (int) $row['master_client_id'] !== (int) $filters['cliente']) {
                return false;
            }
            if (($filters['tipo'] ?? null) !== null && $row['tipo'] !== $filters['tipo']) {
                return false;
            }
            if (($filters['estado'] ?? null) !== null && $row['estado'] !== $filters['estado']) {
                return false;
            }
            if ($search !== null) {
                $haystack = mb_strtolower(implode(' ', [
                    $row['cliente'], (string) $row['numero'], (string) $row['factura_origen_numero'],
                    (string) $row['tipo'], (string) $row['registrado_por'],
                ]));
                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));

        usort($rows, fn (array $a, array $b): int => strcmp((string) $b['fecha'], (string) $a['fecha']));

        return $rows;
    }

    /**
     * REQ-493 — detalle de una nota real (nunca de una fila virtual de la cola, esas no tienen
     * `id` — el frontend abre el precargado de registro en su lugar, ver `devolucionDetail()`).
     *
     * @return array<string, mixed>|null null si no existe
     */
    public function show(int $id): ?array
    {
        /** @var AdminContCreditNote|null $note */
        $note = AdminContCreditNote::with([
            'facturaOrigen', 'facturaNueva', 'nuevoTratamientoItbms', 'registradoPor', 'aprobadoPor',
            'cuentaBancariaSalida',
        ])->find($id);
        if ($note === null) {
            return null;
        }

        $payload = [
            'id' => $note->id,
            'numero' => $note->numero,
            'master_client_id' => $note->master_client_id,
            'cliente' => $note->cliente,
            'tipo' => $note->tipo,
            'subtipo_anulacion' => $note->subtipo_anulacion,
            'factura_origen_id' => $note->factura_origen_id,
            'factura_origen_numero' => $note->facturaOrigen?->numero,
            // Batch 13 (REQ-497) — `factura_origen_id` es el PK de `AdminContInvoice`, pero
            // `GET /invoices/{order}/detail` (Facturación, ya existente, RN1: mismo formato exacto
            // que cualquier otra factura) espera el `order_id` de Bodega, NO ese PK — no resuelven
            // directo, por eso se expone acá el identificador correcto para que el frontend pegue
            // al endpoint que ya existe sin construir uno nuevo.
            'factura_origen_order_id' => $note->facturaOrigen?->order_id,
            'factura_nueva_numero' => $note->facturaNueva?->numero,
            'factura_nueva_order_id' => $note->facturaNueva?->order_id,
            'monto' => $note->monto,
            'subtotal' => $note->subtotal,
            'itbms' => $note->itbms,
            'motivo' => $note->motivo,
            'resultado' => $note->resultado,
            // Batch 13 (REQ-496 RN1) — "cuenta de salida (si aplica)" en el documento formal;
            // solo tiene sentido cuando resultado=devuelto (ver `applyToBalance()`).
            'cuenta_bancaria_salida' => $note->cuentaBancariaSalida?->label(),
            'estado' => $note->estado,
            'tiene_comprobante' => $note->comprobante_url !== null,
            'fecha' => $note->fecha->toDateString(),
            // RN1 REQ-493 — solo se exponen si aplican al estado real, nunca vacíos por default.
            'registrado_por' => $note->registradoPor !== null ? trim("{$note->registradoPor->first_name} {$note->registradoPor->last_name}") : null,
            'aprobado_por' => $note->estado !== AdminContCreditNote::ESTADO_PENDIENTE_APROBACION && $note->aprobadoPor !== null
                ? trim("{$note->aprobadoPor->first_name} {$note->aprobadoPor->last_name}")
                : null,
            'fecha_decision' => $note->fecha_decision?->toDateString(),
            'motivo_rechazo' => $note->estado === AdminContCreditNote::ESTADO_RECHAZADA ? $note->motivo_rechazo : null,
            'motivo_correccion' => $note->motivo_correccion,
            'nuevo_tratamiento_itbms' => $note->nuevoTratamientoItbms !== null
                ? ['id' => $note->nuevoTratamientoItbms->id, 'nombre' => $note->nuevoTratamientoItbms->nombre, 'porcentaje' => $note->nuevoTratamientoItbms->porcentaje]
                : null,
            'nueva_fecha_factura' => $note->nueva_fecha_factura?->toDateString(),
            // RN4 REQ-493 — el frontend decide si renderiza los botones Aprobar/Rechazar según este
            // estado; Batch 13 conecta la acción real (`decide()`, exclusivo Mark).
            'puede_aprobar_rechazar' => $note->estado === AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
            'bodega_trazabilidad' => null,
        ];

        // RN2 REQ-493 — trazabilidad de Bodega SOLO en notas de devolución de mercancía.
        if ($note->devolucion_bodega_id !== null && $note->tipo === AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA) {
            /** @var CustomerReturn|null $customerReturn */
            $customerReturn = CustomerReturn::find($note->devolucion_bodega_id);
            if ($customerReturn !== null) {
                $payload['bodega_trazabilidad'] = [
                    'return_number' => $customerReturn->return_number,
                    'historial' => $this->bodegaReturns->historyPayload($customerReturn),
                ];
            }
        }

        return $payload;
    }

    /**
     * REQ-491 — precarga del formulario de registro al hacer clic en una fila ámbar de la cola.
     * Endpoint propio de Notas Crédito (no llama a `bodega.read`, que Felix/Yaneth/Mark/Gerencia no
     * tienen) — mismo criterio de "cross-schema directo" ya usado en `pendingBodegaReturns()`.
     *
     * @return array<string, mixed>|null null si la devolución no existe o ya no está en la cola
     */
    public function devolucionDetail(int $customerReturnId): ?array
    {
        /** @var CustomerReturn|null $customerReturn */
        $customerReturn = CustomerReturn::with('lines')->find($customerReturnId);
        if ($customerReturn === null || ! $customerReturn->isAwaitingCreditNote()) {
            return null;
        }

        /** @var AdminContInvoice|null $invoice */
        $invoice = AdminContInvoice::where('order_id', $customerReturn->order_id)->whereNull('anulada_at')->first();
        if ($invoice === null) {
            return null;
        }

        return [
            'customer_return_id' => $customerReturn->id,
            'return_number' => $customerReturn->return_number,
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'factura_origen_id' => $invoice->id,
            'factura_origen_numero' => $invoice->numero,
            'productos' => $customerReturn->lines->map(fn ($line): array => [
                'reference' => $line->reference_snapshot,
                'description' => $line->description_snapshot,
                // SCRUM-786 — NULL para devoluciones de pedidos que no tienen el precio
                // snapshoteado (ver bodega:backfill-order-item-prices); el frontend cae a $0.00.
                'unit_price' => $line->unit_price,
                'qty_received' => $line->qty_received ?? $line->qty_requested,
                'reason' => $line->reasonCode(),
                'reason_detail' => $line->reasonDetail(),
            ])->values()->all(),
        ];
    }

    /**
     * REQ-491 — devoluciones ya confirmadas físicamente por Bodega (`status=esperando_nota_credito`)
     * que todavía no tienen su nota de crédito generada. Query cross-schema directa — mismo patrón
     * ya en uso entre Compras↔Bodega (`OrderBoardService::supplierEta()`), decisión confirmada por
     * Luis (ver ADR-SCRUM565-570). `AdminContInvoice.order_id` es el mismo id que
     * `CustomerReturn.order_id` — no hace falta tabla de mapeo nueva.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingBodegaReturns(): array
    {
        $alreadyLinked = AdminContCreditNote::query()->whereNotNull('devolucion_bodega_id')->pluck('devolucion_bodega_id')->all();

        /** @var Collection<int, CustomerReturn> $returns */
        $returns = CustomerReturn::query()
            ->where('status', CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO)
            ->whereNotIn('id', $alreadyLinked === [] ? [0] : $alreadyLinked)
            ->orderBy('received_at')
            ->get();

        if ($returns->isEmpty()) {
            return [];
        }

        $orderIds = $returns->pluck('order_id')->unique()->values()->all();
        $invoicesByOrder = AdminContInvoice::query()
            ->whereIn('order_id', $orderIds)
            ->whereNull('anulada_at')
            ->get()
            ->keyBy('order_id');

        $rows = [];
        foreach ($returns as $return) {
            /** @var AdminContInvoice|null $invoice */
            $invoice = $invoicesByOrder->get($return->order_id);
            if ($invoice === null) {
                // Sin factura activa para ese Order — no debería pasar en el flujo normal (una
                // devolución solo nace de una Guía de Entrega ya facturada), se omite en vez de
                // romper el listado completo por un dato inconsistente.
                continue;
            }

            $rows[] = [
                'id' => null,
                'tipo' => 'pendiente_generar_nota',
                'subtipo_anulacion' => null,
                'numero' => null,
                'cliente' => $invoice->cliente,
                'master_client_id' => $invoice->master_client_id,
                'estado' => 'pendiente_generar_nota',
                'monto' => null,
                'fecha' => ($return->received_at ?? $return->created_at)->toDateString(),
                'factura_origen_id' => $invoice->id,
                'factura_origen_numero' => $invoice->numero,
                'registrado_por' => null,
                'devolucion_bodega_id' => null,
                'customer_return_id' => $return->id,
                'return_number' => $return->return_number,
            ];
        }

        return $rows;
    }

    /**
     * REQ-489 — solo lectura, arma la revisión previa de "Corrección de datos": tarjetas + vista
     * previa de la factura nueva (RN2: mismo formato que cualquier factura del sistema, RN3:
     * mismos productos/cantidades de la original, solo cambia ITBMS/fecha). Nada se persiste acá
     * (RN1).
     *
     * @param  array{master_client_id: int, factura_origen_id: int, motivo_correccion: string, nuevo_tratamiento_itbms_rate_id: ?int, nueva_fecha: ?string, motivo: string}  $data
     * @return array{tarjetas: array<string, mixed>, factura_preview: array<string, mixed>, monto: float, requiere_aprobacion: bool}
     */
    public function previewCorreccion(array $data): array
    {
        [$facturaOrigen, $order, $quote, $rate] = $this->correccionContext($data);

        $breakdown = self::correctionBreakdown($quote, $rate);
        $guia = $order->documents()->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)->latest('created_at')->first();
        $card = $order->pipelineCard;

        return [
            // Pre-QA Batch 12 (2026-08-24) — hallazgo real contra dev.atlanticerp.ai: estas tarjetas son
            // "texto libre server-side" por diseño (ver docblock de `PreviewCorreccionTarjetas` en
            // el frontend — el frontend solo las muestra, nunca las reformatea), pero antes de este
            // fix mandaban un boolean crudo (`existe: true`) y un objeto crudo
            // (`nuevo_tratamiento_itbms: {...}`) que `CardBlock.tsx` renderiza con `String(v)` —
            // literalmente "true" y "[object Object]" en pantalla. Mismo criterio ya usado en
            // `AccountStatementExportService::rows()` (hardcodea 'Sí'/'No', sin locale) para
            // mantenerlo consistente con el resto del código de este módulo.
            'tarjetas' => [
                'factura_origen' => ['numero' => $facturaOrigen->numero, 'monto' => $facturaOrigen->monto, 'fecha' => $facturaOrigen->created_at->toDateString()],
                'proyecto' => ['nombre' => $card?->salesProject?->name],
                'cotizacion' => ['folio' => $quote->folio],
                'guia_entrega' => ['existe' => $guia !== null ? 'Sí' : 'No', 'fecha' => $guia?->created_at?->toDateString()],
                'nota_a_generar' => [
                    'tipo' => 'Anulación completa de factura',
                    'subtipo' => 'Corrección de datos — el trabajo sí se entregó, hay que refacturar',
                ],
                'motivo' => $data['motivo'],
                'correccion_aplicada' => [
                    'motivo_correccion' => self::MOTIVO_CORRECCION_LABELS[$data['motivo_correccion']] ?? $data['motivo_correccion'],
                    'nuevo_tratamiento_itbms' => $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_FECHA
                        ? "{$rate->nombre} ({$rate->porcentaje}%)"
                        : null,
                    'nueva_fecha' => $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_ITBMS ? $data['nueva_fecha'] : null,
                ],
            ],
            'factura_preview' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'cotizacion_folio' => $quote->folio,
                'cliente' => $facturaOrigen->cliente,
                'monto' => $breakdown['total'],
                ...$breakdown,
                ...InvoiceService::paymentBlock(),
            ],
            'monto' => $breakdown['total'],
            'requiere_aprobacion' => $breakdown['total'] > $this->primaryApprovalThreshold(),
        ];
    }

    /**
     * REQ-488/489/490 — registra "Corrección de datos". Método propio (no extiende `register()` —
     * el flujo es fiscal: anula+reemplaza una factura, no aplica saldo/excedente como los otros 3
     * tipos).
     *
     * @param  array{master_client_id: int, factura_origen_id: int, motivo_correccion: string, nuevo_tratamiento_itbms_rate_id: ?int, nueva_fecha: ?string, motivo: string}  $data
     */
    public function registerCorreccion(array $data, ?UploadedFile $comprobante, User $actor): AdminContCreditNote
    {
        $note = DB::transaction(function () use ($data, $actor): AdminContCreditNote {
            [$facturaOrigen, , $quote, $rate] = $this->correccionContext($data, lockForUpdate: true);
            $breakdown = self::correctionBreakdown($quote, $rate);
            $threshold = $this->primaryApprovalThreshold();
            $pendiente = $breakdown['total'] > $threshold;

            $note = AdminContCreditNote::create([
                'numero' => $this->folio->next(),
                'master_client_id' => $data['master_client_id'],
                'cliente' => $facturaOrigen->cliente,
                'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
                'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
                'factura_origen_id' => $facturaOrigen->id,
                'monto' => $breakdown['total'],
                'subtotal' => $breakdown['subtotal'],
                'itbms' => $breakdown['itbms'],
                'motivo' => $data['motivo'],
                'estado' => $pendiente ? AdminContCreditNote::ESTADO_PENDIENTE_APROBACION : AdminContCreditNote::ESTADO_APLICADA,
                'motivo_correccion' => $data['motivo_correccion'],
                'nuevo_tratamiento_itbms_rate_id' => $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_FECHA ? $rate->id : null,
                'nueva_fecha_factura' => $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_ITBMS ? $data['nueva_fecha'] : null,
                'correccion_pendiente_aprobacion' => $pendiente,
                'registrado_por' => $actor->id,
                'fecha' => now()->toDateString(),
            ]);

            // REQ-490 RN1/RN5 — solo se ejecuta de inmediato si NO quedó pendiente de aprobación.
            // Si quedó pendiente, `executeCorreccion()` se difiere hasta que Batch 13 (aprobar,
            // exclusivo Mark) la invoque — el payload para ejecutarla ya quedó persistido arriba.
            if (! $pendiente) {
                $this->executeCorreccion($note, $facturaOrigen);
            }

            return $note;
        });

        if ($comprobante !== null) {
            $key = $this->attachments->upload($comprobante, $note->id);
            $note->update(['comprobante_url' => $key]);
        }

        return $note;
    }

    /**
     * REQ-490 RN3 — ejecuta de verdad la corrección: anula la factura original y genera la
     * factura nueva. Reusable tal cual desde Batch 13 (`approve()`, todavía no implementado) para
     * el caso `pendiente_aprobacion` — no reimplementar esta lógica ahí.
     */
    private function executeCorreccion(AdminContCreditNote $note, AdminContInvoice $facturaOrigen): void
    {
        $facturaOrigen->update(['anulada_at' => now()]);

        $facturaNueva = AdminContInvoice::create([
            'numero' => $this->invoiceFolio->next(),
            'order_id' => $facturaOrigen->order_id,
            'quote_id' => $facturaOrigen->quote_id,
            'itbms_rate_id' => $note->nuevo_tratamiento_itbms_rate_id ?? $facturaOrigen->itbms_rate_id,
            'sales_project_id' => $facturaOrigen->sales_project_id,
            'master_client_id' => $facturaOrigen->master_client_id,
            'cliente' => $facturaOrigen->cliente,
            'monto' => $note->monto,
            'saldo_aplicado' => 0.0,
            'created_by' => $note->registrado_por,
        ]);

        $note->update(['factura_nueva_id' => $facturaNueva->id]);
    }

    /**
     * Contexto compartido por `previewCorreccion()`/`registerCorreccion()` — factura de origen
     * (vigente, con lock opcional para el submit real), su `Order`/`Quote` de Bodega, y la tasa de
     * ITBMS a aplicar (la nueva si el motivo la corrige, si no la misma que ya tenía la factura).
     *
     * @param  array{factura_origen_id: int, motivo_correccion: string, nuevo_tratamiento_itbms_rate_id: ?int}  $data
     * @return array{0: AdminContInvoice, 1: BodegaOrder, 2: Quote, 3: AdminContItbmsRate}
     */
    private function correccionContext(array $data, bool $lockForUpdate = false): array
    {
        $query = AdminContInvoice::query()->with('itbmsRate');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        /** @var AdminContInvoice $facturaOrigen */
        $facturaOrigen = $query->whereNull('anulada_at')->findOrFail($data['factura_origen_id']);

        /** @var BodegaOrder $order */
        $order = BodegaOrder::findOrFail($facturaOrigen->order_id);
        $quote = $order->linkedQuote();
        if ($quote === null) {
            throw new \RuntimeException("La factura {$facturaOrigen->numero} no tiene una cotización vinculada — no se puede armar la factura de corrección.");
        }

        $rateId = $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_FECHA
            ? $data['nuevo_tratamiento_itbms_rate_id']
            : $facturaOrigen->itbms_rate_id;
        /** @var AdminContItbmsRate $rate */
        $rate = AdminContItbmsRate::findOrFail($rateId);

        return [$facturaOrigen, $order, $quote, $rate];
    }

    /**
     * REQ-489 RN2/RN3 — reusa `InvoiceService::fiscalBreakdown()` (mismos ítems/subtotal/
     * descuentos que cualquier otra factura del sistema, RN2 — nunca los productos cambian, RN3),
     * pero recalcula `itbms`/`total` con la tasa RESUELTA en `correccionContext()` en vez de la
     * tasa por defecto que usa `Quote::itbms()` — es exactamente el dato que "Corrección de datos"
     * puede estar corrigiendo.
     *
     * @return array{subtotal: float, descuentos: float, itbms: float, total: float, items: list<array<string, mixed>>}
     */
    private static function correctionBreakdown(Quote $quote, AdminContItbmsRate $rate): array
    {
        $breakdown = InvoiceService::fiscalBreakdown($quote);

        $itbms = round($quote->netTotal() * ($rate->porcentaje / 100), 2);

        $breakdown['itbms'] = $itbms;
        $breakdown['total'] = round($quote->netTotal() + $itbms, 2);

        return $breakdown;
    }
}
