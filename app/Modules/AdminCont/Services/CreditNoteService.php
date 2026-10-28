<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\Exceptions\CreditNoteStateException;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\UnmappableItbmsRateException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsCodeMapper;
use App\Modules\AdminCont\Jobs\SendCreditNoteConfirmedEmailJob;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\Billing\InvoiceLineMath;
use App\Modules\AdminCont\Support\GuiaEntregaResolver;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\CustomerReturnLine;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Services\CustomerReturnDocumentService;
use App\Modules\Bodega\Services\CustomerReturnService as BodegaCustomerReturnService;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Batch 10 (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones (solo lectura,
 * `summary()`/`activeItbmsRates()`/`itbmsTreatmentForInvoice()`). Batch 11 (SCRUM-559→564,
 * REQ-482→487) agrega el escritor real: `register()`. Ver su docblock para el alcance exacto
 * (deliberadamente NO cubre el subtipo "Corrección de datos" — eso es Batch 12).
 */
final class CreditNoteService
{
    /** Labels de `previewCorreccion()` (tarjeta "Corrección aplicada") — ver docblock ahí. */
    public const MSG_FACTURA_NO_CORREGIBLE = 'La factura ya fue anulada o no existe: no se puede corregir.';

    /** @return array<string, string> Fase 6d: el nombre del impuesto sale del país de la empresa. */
    private static function motivoCorreccionLabels(): array
    {
        return [
            AdminContCreditNote::MOTIVO_CORRECCION_ITBMS => 'Tratamiento de '.\App\Shared\Multitenancy\TenantRegion::taxLabel().' incorrecto',
            AdminContCreditNote::MOTIVO_CORRECCION_FECHA => 'Fecha de facturación incorrecta',
            AdminContCreditNote::MOTIVO_CORRECCION_AMBOS => 'Ambos',
        ];
    }

    public function __construct(
        private readonly AdminContCreditNoteFolioService $folio,
        private readonly AdminContCreditNoteAttachmentService $attachments,
        private readonly AdminContInvoiceFolioService $invoiceFolio,
        private readonly BodegaCustomerReturnService $bodegaReturns,
        private readonly CustomerReturnDocumentService $bodegaReturnDocuments,
        private readonly FiscalCertificationService $certification,
        // SCRUM-1116 — fuente única del valor acreditable restante de una factura.
        private readonly CreditableBalanceService $creditable,
        // SCRUM-1168 F1 — único escritor de `invoices.saldo_aplicado`.
        private readonly InvoiceBalanceWriter $balance,
        // SCRUM-1183 — el pago de una factura corregida pasa a la factura de reemplazo.
        private readonly InvoiceReplacementPaymentTransfer $paymentTransfer,
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
     * SCRUM-932 — `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` agregados para que el
     * modal de creación pueda filtrar Proyecto/# Cotización en el frontend sobre esta misma lista
     * (sin endpoint de cascada nuevo, mismo criterio que `PaymentService::openInvoicesForClient()`
     * en SCRUM-931, pero query independiente — este método SIGUE trayendo facturas ya pagadas,
     * Cobros no). Cotización resuelta vía `Order::linkedQuote()`, no vía `AdminContInvoice.quote_id`
     * directo, mismo criterio ya establecido en Cobros/Estado de Cuenta.
     *
     * @return list<array{id: int, numero: string, monto: float, saldo_pendiente: float, itbms_percentage: float, itbms_rate_id: int|null, tipo_factura: string, order_id: int, sales_project_id: ?int, proyecto: ?string, quote_id: ?int, cotizacion_folio: ?string}>
     */
    public function invoicesForClient(int $masterClientId): array
    {
        $invoices = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->where('es_incobrable', false)
            ->whereNull('anulada_at')
            ->with('itbmsRate')
            ->get();

        $projectNames = SalesProject::query()
            ->whereIn('id', $invoices->pluck('sales_project_id')->filter()->unique())
            ->pluck('name', 'id');

        $orders = BodegaOrder::query()
            ->whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        // SCRUM-1116 — valor acreditable y notas previas de todas las facturas en 2 consultas (sin N+1).
        $acreditables = $this->creditable->valoresAcreditables(array_values($invoices->pluck('id')->map(fn ($id): int => (int) $id)->all()));

        $rows = $invoices
            ->map(function (AdminContInvoice $i) use ($projectNames, $orders, $acreditables): array {
                $quote = $orders->get($i->order_id)?->linkedQuote();

                return [
                    'id' => $i->id,
                    'numero' => $i->numero,
                    'monto' => $i->monto,
                    'saldo_pendiente' => round($i->monto - $i->saldo_aplicado, 2),
                    // SCRUM-1116 — lo que AÚN se puede acreditar (total − notas aplicadas o pendientes, todos los tipos).
                    // No es el saldo por cobrar: los cobros no lo reducen. Tope del monto y base de la anulación completa.
                    'monto_acreditable' => $acreditables[$i->id]['acreditable'] ?? round((float) $i->monto, 2),
                    'notas_previas_total' => $acreditables[$i->id]['notas_previas'] ?? 0.0,
                    'itbms_percentage' => $i->itbmsRate->porcentaje ?? 0.0,
                    // REQ-489 — id real de la tasa ya aplicada, para que "Corrección de datos" pueda
                    // excluirla de "tratamiento correcto" (`NotaCreditoFacturaOrigen.itbms_rate_id`,
                    // ver docblock del tipo en el frontend — faltaba acá, Pre-QA Batch 12 2026-08-24:
                    // sin este campo `facturaActualRateId` siempre resolvía null en el frontend, así
                    // que ninguna tasa se excluía y "Tratamiento actual" siempre mostraba "—").
                    'itbms_rate_id' => $i->itbms_rate_id,
                    // SCRUM-929 (B3a) — un pedido puede tener varias facturas activas (parcial por entrega
                    // + completo): cada una es una fila propia; `tipo_factura`/`order_id` las distinguen.
                    'tipo_factura' => $i->tipo,
                    'order_id' => $i->order_id,
                    'sales_project_id' => $i->sales_project_id,
                    'proyecto' => $i->sales_project_id !== null ? $projectNames->get($i->sales_project_id) : null,
                    'quote_id' => $quote?->id,
                    'cotizacion_folio' => $quote?->folio,
                ];
            })
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

            // SCRUM-1116 — valor que TODAVÍA se puede acreditar: total de la factura menos las notas previas (aplicadas y
            // pendientes de aprobación, de todos los tipos). Se calcula DENTRO de la transacción y con la factura ya
            // bloqueada, así dos registros simultáneos sobre la misma factura no pueden sumar más que su valor. NO es el
            // saldo por cobrar: los cobros no reducen lo acreditable (anular una factura cobrada devuelve lo cobrado).
            $acreditable = $this->creditable->valorAcreditableRestante($facturaOrigen);
            $notasPrevias = $this->creditable->notasPrevias((int) $facturaOrigen->id);

            if ($data['tipo'] === AdminContCreditNote::TIPO_ANULACION_COMPLETA) {
                // REQ-484 RN4 (corregido por SCRUM-1116) — Anulación completa: el monto SIEMPRE es lo que queda por
                // acreditar (el total de la factura si no hay notas previas), nunca lo que mande el cliente (si mandó
                // algo distinto se ignora, no es un error). Sin nada por acreditar no hay anulación posible.
                if ($acreditable <= 0.0) {
                    throw ValidationException::withMessages(['factura_origen_id' => [$this->creditable->mensajeSinSaldo($facturaOrigen->numero)]]);
                }
                $monto = $acreditable;
            } else {
                $monto = (float) $data['monto'];
                // SCRUM-1116 RN4 — en NINGÚN tipo el acumulado de notas puede superar el valor de la factura.
                if (round($monto - $acreditable, 2) > 0.0) {
                    throw ValidationException::withMessages(['monto' => [
                        $this->creditable->mensajeExceso($facturaOrigen->numero, $monto, $acreditable, $notasPrevias, (float) $facturaOrigen->monto),
                    ]]);
                }
            }

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
                // 'devuelto' | 'saldo_favor' — el Form Request lo exige, pero lo valida ANTES de bloquear la factura: si
                // entre medio otra operación cambió el saldo y aparece un excedente nuevo, se pide acá con un 422
                // claro en vez de romper por un índice inexistente.
                $resultado = $data['resultado'] ?? null;
                if ($resultado === null) {
                    throw ValidationException::withMessages(['resultado' => ['Indique si el excedente se devuelve al cliente o queda como saldo a favor.']]);
                }
            } else {
                $resultado = AdminContCreditNote::RESULTADO_APLICADO_SALDO;
                $excedente = 0.0;
            }

            // Batch 13 (REQ-494 RN1) — cualquier Anulación completa queda pendiente de aprobación
            // de Gerencia SIN IMPORTAR el monto, no solo por superar el umbral (a diferencia de los
            // otros 3 tipos). Fix confirmado por Luis sobre el comportamiento de Batch 11: antes
            // de esto, una anulación completa de una factura chica quedaba `aplicada` directo, sin
            // pasar por Gerencia. Alcance acotado a este método — `registerCorreccion()` (subtipo
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
            // 13 (`decide()`, exclusivo Gerencia) llama a `applyToBalance()` para ejecutarla al aprobar,
            // mismo método que este caso inmediato para no duplicar la lógica.
            if ($estado === AdminContCreditNote::ESTADO_APLICADA) {
                $this->applyToBalance($note, $facturaOrigen);
            }

            // REQ-491 — cierra el círculo con Bodega DENTRO de esta misma transacción: si
            // `finalizeForCreditNote()` falla (ej. la devolución ya no está esperando nota), la
            // creación de la nota completa revierte — decisión explícita de Luis, ver
            // ADR-SCRUM565-570 (atómico, no evento+listener con try/catch aislado).
            if (($data['devolucion_bodega_id'] ?? null) !== null) {
                $this->bodegaReturns->finalizeForCreditNote((int) $data['devolucion_bodega_id'], $note->id, $note->numero, $actor);
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

        $this->dispatchConfirmationEmail($note);
        $this->certificarSiCorresponde($note);

        return $note;
    }

    /**
     * REQ-486 — encola el correo de confirmación al cliente (Nota de Crédito + Estado de Cuenta)
     * solo cuando la nota quedó `aplicada` de verdad — nunca para `pendiente_aprobacion` (se
     * dispara recién cuando `decide()` la aprueba, ver ahí) ni `rechazada`. Encolado (nunca
     * síncrono) para que un fallo de envío no bloquee ni revierta la nota ya aplicada — mismo
     * criterio que `SendPurchaseOrderEmailJob`.
     */
    private function dispatchConfirmationEmail(AdminContCreditNote $note): void
    {
        if ($note->estado === AdminContCreditNote::ESTADO_APLICADA) {
            SendCreditNoteConfirmedEmailJob::dispatch($note->id);
        }
    }

    /**
     * SCRUM-943 — mismo gate que `dispatchConfirmationEmail()`: certificar ante el PAC solo tiene
     * sentido cuando la nota quedó `aplicada` de verdad, nunca mientras sigue `pendiente_aprobacion`
     * (podría rechazarse y no llegar a tener efecto real) ni si Gerencia la rechazó. Síncrono (no
     * `ShouldQueue`) — mismo criterio que `CertifyInvoiceOnCreated` sobre `InvoiceCreated`;
     * `FiscalCertificationService` ya atrapa cualquier error como `error_configuracion`, nunca
     * bloquea ni revierte la nota ya aplicada.
     */
    private function certificarSiCorresponde(AdminContCreditNote $note): void
    {
        if ($note->estado === AdminContCreditNote::ESTADO_APLICADA) {
            $this->certification->certificarNotaCredito($note);
        }
    }

    /**
     * SCRUM-943 — `executeCorreccion()` genera una factura de REEMPLAZO (`factura_nueva_id`), que
     * necesita su propia certificación como factura — no como parte de la nota. Reusa el mismo
     * evento de dominio `InvoiceCreated` que `InvoiceService::confirm()` ya dispara (mismo
     * listener `CertifyInvoiceOnCreated`, cero código nuevo de FE para este caso). Se dispara FUERA
     * de la transacción de `registerCorreccion()`/`decide()` — mismo criterio que `InvoiceCreated`
     * en `InvoiceService::confirm()`, nunca certificar con una transacción todavía abierta.
     */
    private function certificarFacturaDeReemplazoSiCorresponde(AdminContCreditNote $note): void
    {
        if ($note->factura_nueva_id === null) {
            return;
        }

        /** @var AdminContInvoice|null $facturaNueva */
        $facturaNueva = AdminContInvoice::find($note->factura_nueva_id);
        if ($facturaNueva === null) {
            return;
        }

        // SCRUM-1009 (D2) — el reemplazo NO se certifica mientras la NC de anulación no esté
        // certificada: si la DGI rechazó la NC, la original sigue vigente ante la DGI y emitir el
        // reemplazo dejaría DOS facturas vigentes por el mismo hecho (doble facturación). Se retiene
        // en `en_espera_nc`; `FiscalCertificationService::persistirResultado()` lo libera cuando la NC
        // certifica, por cualquier camino (inicial, reintento manual o polling). `no_aplica` (FE
        // apagada / tipo deshabilitado) sigue su curso: el listener deja el reemplazo `no_aplica`.
        // Cualquier otro estado, incluido null inesperado, falla cerrado (retiene).
        $estadoNota = $note->fresh()?->fe_estado;
        if (! in_array($estadoNota, ['certificado', 'no_aplica'], true)) {
            $facturaNueva->forceFill(['fe_estado' => FiscalCertificationService::ESTADO_EN_ESPERA_NC])->save();
            Log::warning('Facturación electrónica: reemplazo retenido hasta que la nota de anulación certifique', [
                'nota_credito_id' => $note->id,
                'factura_nueva_id' => $facturaNueva->id,
                'estado_nota' => $estadoNota,
            ]);

            // Senior Review S1 — si el polling o un reintento certificó la NC ENTRE la lectura de arriba y la escritura
            // de `en_espera_nc`, el compare-and-set de `persistirResultado()` corrió con el reemplazo aún sin retener
            // (no-op) y nadie lo liberaría nunca. Se relee la NC y, si ya certificó, se libera acá (mismo CAS: seguro
            // de correr dos veces).
            $notaActual = $note->fresh();
            if ($notaActual !== null && $notaActual->fe_estado === 'certificado') {
                $this->certification->liberarReemplazoRetenido($notaActual);
            }

            return;
        }

        Event::dispatch(new InvoiceCreated(
            invoiceId: $facturaNueva->id,
            orderId: $facturaNueva->order_id,
            numero: $facturaNueva->numero,
            tenant: Tenant::current()->slug ?? '',
        ));
    }

    /**
     * Batch 13 (REQ-482/494) — aplica el efecto real de una nota "normal" (descuento comercial,
     * devolución de mercancía, o Anulación completa vía `register()` — NUNCA "Corrección de
     * datos", esa usa `executeCorreccion()`): suma el monto al saldo aplicado de la factura de
     * origen, y resuelve el destino del excedente (banco o saldo a favor) si corresponde. Recalcula
     * `saldoPendiente`/`excedente` contra el estado ACTUAL de `$facturaOrigen` (bajo lock del
     * llamador), no contra valores guardados de un instante anterior — mismo criterio tanto si se
     * llama desde `register()` (nota recién creada, aplicación inmediata) como desde `decide()`
     * (nota que estuvo pendiente y Gerencia recién aprueba, posiblemente más tarde). El `resultado`
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

        // SCRUM-877 p1 — monto que REALMENTE redujo el saldo de la factura (lo demás, si lo hay,
        // fue excedente devuelto/saldo a favor). El Estado de Cuenta lo lee tal cual (decisión D-6).
        $montoAplicadoSaldo = round(min($note->monto, max(0.0, $saldoPendiente)), 2);
        $note->update(['monto_aplicado_saldo' => $montoAplicadoSaldo]);

        // SCRUM-1168 F1 — única puerta de escritura de `saldo_aplicado`; la nota conserva su regla propia (valor acreditable), por eso sin tope.
        $this->balance->apply($facturaOrigen, $montoAplicadoSaldo, permitirExceso: true);

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
        }
        // SCRUM-902/903 — cuando resultado=saldo_favor, no hace falta escribir el excedente a
        // ninguna tabla aparte: `AdminContCreditNote::montoDisponibleSaldoFavor()` ya lo deriva de
        // `monto`/`monto_aplicado_saldo`/`monto_usado` de esta misma nota (única fuente de verdad,
        // consumida por Cobros/Saldo a Favor — ver `AbonoService::saldoClienteDisponible()`).
        // Antes de este batch escribía a `CreditBalanceService::add()` (retirado, ver docblock de
        // la migración de drop de `customer_credit_balances`).
    }

    /**
     * Batch 13 (REQ-494) — Gerencia aprueba o rechaza una nota `pendiente_aprobacion`. RN2 bloquea la
     * aprobación si la nota exige comprobante (mismo predicado que `register()`, ver
     * `AdminContCreditNote::requiresComprobante()`) y no lo tiene adjunto. RN3/RN4: al aprobar, se
     * ejecuta el efecto real diferido — `executeCorreccion()` si era "Corrección de datos"
     * (`correccion_pendiente_aprobacion`), o `applyToBalance()` para los otros 3 tipos — y queda
     * `aplicada`. RN5: al rechazar, no se ejecuta nada (ni factura nueva ni movimiento de saldo), la
     * nota queda `rechazada` con el motivo obligatorio.
     */
    public function decide(int $id, bool $approve, ?string $motivoRechazo, User $actor): AdminContCreditNote
    {
        $note = DB::transaction(function () use ($id, $approve, $motivoRechazo, $actor): AdminContCreditNote {
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

                // SCRUM-929 (Senior Review B3a-1) — sin `whereNull('anulada_at')`, dos notas pendientes sobre la
                // MISMA factura (F1) podían aprobarse una tras otra: la 1ª anula F1 y crea F2; la 2ª volvía a
                // cargar F1 (ya anulada) sin error, la re-anulaba (pisando `anulada_at`) e intentaba crear F3 con
                // el mismo `(order_id, tipo)` que F2 activa → 500 crudo del índice único en vez de un 422 claro.
                $facturaOrigen = AdminContInvoice::query()->lockForUpdate()->whereNull('anulada_at')->find($note->factura_origen_id);
                if ($facturaOrigen === null) {
                    throw new CreditNoteStateException("La factura de origen de la nota {$note->numero} ya no está vigente — no se puede aprobar.");
                }

                // SCRUM-1116 (D3) — al aprobar se revalida contra el valor acreditable SIN contar a la propia nota: una nota
                // registrada antes de este fix (o con otra pendiente en paralelo) que haría pasar el acumulado del valor de
                // la factura no se puede aprobar; Gerencia solo puede rechazarla. Las de corrección de datos ya lo cubren al
                // registrarse (no admiten notas previas ni pendientes), por eso quedan fuera.
                if (! $note->correccion_pendiente_aprobacion) {
                    $acreditable = $this->creditable->valorAcreditableRestante($facturaOrigen, (int) $note->id);
                    // Una anulación completa es por TODO lo que quedaba: si el valor restante cambió desde que se registró
                    // (otra nota pendiente que se rechazó, por ejemplo), aprobarla dejaría una "anulación completa" parcial.
                    // Descuentos y devoluciones solo no pueden pasarse del valor restante.
                    $desactualizada = $note->tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA
                        ? abs(round((float) $note->monto - $acreditable, 2)) > 0.0
                        : round((float) $note->monto - $acreditable, 2) > 0.0;
                    if ($desactualizada) {
                        throw new CreditNoteStateException(
                            "La nota {$note->numero} no se puede aprobar: su monto USD ".number_format((float) $note->monto, 2, '.', ',')
                            .($note->tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA ? ' ya no coincide con' : ' supera')
                            ." el valor que aún se puede acreditar de la factura {$facturaOrigen->numero} (USD "
                            .number_format($acreditable, 2, '.', ',').'), porque cambiaron las notas de crédito de esa factura. Recházala y regístrala de nuevo por el valor correcto.'
                        );
                    }
                }

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

        $this->dispatchConfirmationEmail($note);
        $this->certificarSiCorresponde($note);
        $this->certificarFacturaDeReemplazoSiCorresponde($note);

        return $note;
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
     * `GET /admin-contab/itbms-rates` (ese es `primary_approver_only` — Contabilidad/Asistente/Gerencia no tienen acceso
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
        // SCRUM-929 (B3a) — `$orderId` es la ENTREGA: su factura según la regla central (la parcial vigente
        // de esa entrega o el `completo` vigente que la cubre), no una factura cualquiera del pedido.
        $invoice = InvoiceService::pickInvoiceForDelivery(
            AdminContInvoice::forOrder($orderId)->with('itbmsRate')->orderBy('id')->get(),
            $orderId,
        );
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

        // SCRUM-932 — Cotización/Guía de Entrega de la factura de origen (una sola, no una
        // colección como en Cobros/SCRUM-931) — mismo `GuiaEntregaResolver` ya extraído en
        // SCRUM-930, reusado tal cual.
        $creditNoteQuoteId = null;
        $creditNoteCotizacionFolio = null;
        $creditNoteGuiaNumero = null;
        $creditNoteGuiaOrderId = null;
        if ($note->facturaOrigen !== null) {
            $facturaOrigenOrder = BodegaOrder::find($note->facturaOrigen->order_id);
            $creditNoteQuote = $facturaOrigenOrder?->linkedQuote();
            $creditNoteQuoteId = $creditNoteQuote?->id;
            $creditNoteCotizacionFolio = $creditNoteQuote?->folio;
            $facturaOrigenOrdersById = $facturaOrigenOrder !== null
                ? collect([$facturaOrigenOrder->id => $facturaOrigenOrder])
                : collect();
            [$creditNoteGuiaNumero, $creditNoteGuiaOrderId] = GuiaEntregaResolver::resolve(
                $note->facturaOrigen, $facturaOrigenOrder, $facturaOrigenOrdersById,
            );
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
            // SCRUM-932 — Cotización/Guía de Entrega de la factura de origen, para que el detalle
            // pueda enlazarlas (mismo patrón que `AdminContPaymentController::presentDetail()`,
            // SCRUM-931): la cotización se resuelve vía `Order::linkedQuote()`, no vía
            // `AdminContInvoice.quote_id` directo, mismo criterio ya establecido en Cobros/Estado
            // de Cuenta. `null` si la factura no tiene pedido o el pedido no tiene proyecto/guía.
            'quote_id' => $creditNoteQuoteId,
            'cotizacion_folio' => $creditNoteCotizacionFolio,
            'guia_entrega_numero' => $creditNoteGuiaNumero,
            'guia_entrega_order_id' => $creditNoteGuiaOrderId,
            'factura_nueva_numero' => $note->facturaNueva?->numero,
            'factura_nueva_order_id' => $note->facturaNueva?->order_id,
            // SCRUM-1060 — la factura de reemplazo conserva `order_id` de la original, así que el
            // `order_id` solo no basta para abrir SU detalle: el modal necesita el PK (`invoice_id`).
            'factura_nueva_id' => $note->factura_nueva_id,
            'monto' => $note->monto,
            'subtotal' => $note->subtotal,
            'itbms' => $note->itbms,
            'motivo' => $note->motivo,
            'resultado' => $note->resultado,
            // Batch 13 (REQ-496 RN1) — "cuenta de salida (si aplica)" en el documento formal;
            // solo tiene sentido cuando resultado=devuelto (ver `applyToBalance()`).
            'cuenta_bancaria_salida' => $note->cuentaBancariaSalida?->label(),
            'estado' => $note->estado,
            // SCRUM-943 — mismo criterio que InvoiceService::detail(): null | no_aplica | pendiente
            // | certificado | rechazado | error_configuracion (nunca "rechazado por la DGI").
            'fe_estado' => $note->fe_estado,
            'cufe' => $note->cufe,
            'qr_content' => $note->qr_content,
            'pac_provider_usado' => $note->pac_provider_usado,
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
            // estado; Batch 13 conecta la acción real (`decide()`, exclusivo Gerencia).
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
     * Endpoint propio de Notas Crédito (no llama a `bodega.read`, que Contabilidad/Asistente/Aprobador/Gerencia no
     * tienen) — mismo criterio de "cross-schema directo" ya usado en `pendingBodegaReturns()`.
     *
     * @return array<string, mixed>|null null si la devolución no existe o ya no está en la cola
     */
    public function devolucionDetail(int $customerReturnId): ?array
    {
        /** @var CustomerReturn|null $customerReturn */
        $customerReturn = CustomerReturn::with(['lines', 'order.pipelineCard.salesProject'])->find($customerReturnId);
        if ($customerReturn === null || ! $customerReturn->isAwaitingCreditNote()) {
            return null;
        }

        // SCRUM-929 (B3a) — la devolución es de UNA entrega: apunta a la factura de SU entrega (regla central).
        $invoice = InvoiceService::pickInvoiceForDelivery(
            AdminContInvoice::forOrder($customerReturn->order_id)->orderBy('id')->get(),
            $customerReturn->order_id,
        );
        if ($invoice === null) {
            return null;
        }

        // `unit_price` de Bodega es el precio de cotización SIN ITBMS (snapshot de `OrderItem.unit_price`), pero el
        // monto de una NC es el total CON ITBMS (de ahí se deriva el desglose). Se entrega ya convertido con la tasa de
        // la factura de origen para que ningún consumidor tenga que adivinar que el precio era neto (NC-0001: se acreditó
        // 128.76, el neto, en vez de 137.77, y quedaron 9.01 sin acreditar).
        $invoice->loadMissing('itbmsRate');
        $itbmsPercentage = (float) ($invoice->itbmsRate->porcentaje ?? 0.0);
        $montoSugerido = $this->montoSugeridoDevolucion($customerReturn->lines, $itbmsPercentage);
        // SCRUM-1116 — la sugerencia nunca supera lo que aún se puede acreditar de la factura.
        $acreditable = $this->creditable->valorAcreditableRestante($invoice);
        if ($montoSugerido !== null) {
            $montoSugerido = min($montoSugerido, $acreditable);
        }

        return [
            'customer_return_id' => $customerReturn->id,
            'return_number' => $customerReturn->return_number,
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'factura_origen_id' => $invoice->id,
            'factura_origen_numero' => $invoice->numero,
            // Referencia para quien registra la nota: total de la factura y lo que aún se puede acreditar.
            'factura_monto' => round((float) $invoice->monto, 2),
            'factura_saldo_pendiente' => round((float) $invoice->monto - (float) $invoice->saldo_aplicado, 2),
            'monto_acreditable' => $acreditable,
            'notas_previas_total' => $this->creditable->notasPrevias((int) $invoice->id),
            'itbms_percentage' => $itbmsPercentage,
            // Σ cantidad × precio sin ITBMS × (1 + tasa). NULL si alguna línea no tiene precio (pedidos previos al
            // backfill de SCRUM-786): el formulario no debe sugerir un total parcial.
            'monto_sugerido' => $montoSugerido,
            // SCRUM-865 punto 2 — estos 3 campos SÍ se capturan en Bodega (mismo shape que
            // `CustomerReturnController::formatRow()`) pero nunca se traían acá; el formulario
            // mostraba "No disponible" aunque el dato existiera.
            'project' => $customerReturn->order->pipelineCard?->salesProject?->name,
            'contact_name' => $customerReturn->contact_name,
            'contact_phone' => $customerReturn->contact_phone,
            'has_signed_document' => $customerReturn->hasSignedDocument(),
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
     * Total CON ITBMS sugerido para una devolución: Σ (qty_received ?? qty_requested) × unit_price (neto) × (1 + tasa).
     * NULL si alguna línea no tiene `unit_price`.
     *
     * @param  Collection<int, CustomerReturnLine>  $lines
     */
    private function montoSugeridoDevolucion(Collection $lines, float $itbmsPercentage): ?float
    {
        $neto = 0.0;
        foreach ($lines as $line) {
            if ($line->unit_price === null) {
                return null;
            }
            $neto += ($line->qty_received ?? $line->qty_requested) * $line->unit_price;
        }

        return round($neto * (1 + $itbmsPercentage / 100), 2);
    }

    /**
     * SCRUM-865 punto 2 — `CustomerReturnController::signedDocumentUrl()` (Bodega) está gateado
     * por `permission:bodega.read`, que un usuario de Notas de Crédito (Contabilidad/Asistente/Aprobador/
     * Gerencia) no necesariamente tiene. Mismo mecanismo de presign, gateado acá por el permiso ya
     * exigido a toda la ruta de `notas-credito/*`.
     */
    public function devolucionSignedDocumentUrl(int $customerReturnId): ?string
    {
        /** @var CustomerReturn|null $customerReturn */
        $customerReturn = CustomerReturn::find($customerReturnId);
        if ($customerReturn === null || $customerReturn->signed_document_path === null) {
            return null;
        }

        return $this->bodegaReturnDocuments->presignUrl($customerReturn->signed_document_path);
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

        /** @var \Illuminate\Database\Eloquent\Collection<int, CustomerReturn> $returns */
        $returns = CustomerReturn::query()
            ->where('status', CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO)
            ->whereNotIn('id', $alreadyLinked === [] ? [0] : $alreadyLinked)
            ->orderBy('received_at')
            ->get();

        if ($returns->isEmpty()) {
            return [];
        }

        // SCRUM-876 — la factura vive en la tarjeta raíz del pedido; una devolución sobre una
        // entrega hermana debe resolverse contra esa raíz.
        $rootByOrder = BodegaOrder::whereIn('id', $returns->pluck('order_id')->unique())
            ->get()
            ->mapWithKeys(fn (BodegaOrder $o): array => [$o->id => $o->familyRootId()]);
        // SCRUM-929 (B3a) — varias facturas activas por pedido: se agrupan por raíz y la de cada
        // devolución se elige con la regla central (la de SU entrega), no `keyBy('order_id')`.
        $invoicesByRoot = AdminContInvoice::query()
            ->whereIn('family_root_id', $rootByOrder->values()->unique()->values())
            ->whereNull('anulada_at')
            ->orderBy('id')
            ->get()
            ->groupBy('family_root_id');

        $rows = [];
        foreach ($returns as $return) {
            /** @var Collection<int, AdminContInvoice> $familia */
            $familia = $invoicesByRoot->get($rootByOrder->get($return->order_id), new Collection);
            $invoice = InvoiceService::pickInvoiceForDelivery($familia, $return->order_id);
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

        $breakdown = self::correctionBreakdown($facturaOrigen, $quote, $rate);
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
                    'motivo_correccion' => self::motivoCorreccionLabels()[$data['motivo_correccion']] ?? $data['motivo_correccion'],
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
            $breakdown = self::correctionBreakdown($facturaOrigen, $quote, $rate);
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
            // exclusivo Gerencia) la invoque — el payload para ejecutarla ya quedó persistido arriba.
            if (! $pendiente) {
                $this->executeCorreccion($note, $facturaOrigen);
            }

            return $note;
        });

        if ($comprobante !== null) {
            $key = $this->attachments->upload($comprobante, $note->id);
            $note->update(['comprobante_url' => $key]);
        }

        $this->dispatchConfirmationEmail($note);
        $this->certificarSiCorresponde($note);
        $this->certificarFacturaDeReemplazoSiCorresponde($note);

        return $note;
    }

    /**
     * REQ-490 RN3 — ejecuta de verdad la corrección: anula la factura original y genera la
     * factura nueva. Reusable tal cual desde Batch 13 (`approve()`, todavía no implementado) para
     * el caso `pendiente_aprobacion` — no reimplementar esta lógica ahí.
     *
     * SCRUM-929 (B3a) — la factura de REEMPLAZO conserva `order_id`, `family_root_id` y `tipo` de la que
     * reemplaza (una parcial sigue colgando de SU entrega y siendo `parcial`; un completo, `completo`) y
     * copia sus `invoice_lines` con la corrección aplicada (`correctedLines()`), de modo que el pedido
     * sigue viendo lo ya facturado por ítem y el próximo cierre cuadra. La original se anula ANTES de
     * crear el reemplazo (mismo `order_id` + `tipo`: el índice único `(order_id, tipo) WHERE anulada_at
     * IS NULL` solo admite uno activo) y todo corre en la transacción de `registerCorreccion()`. Una
     * factura legada (sin líneas) se reemplaza como siempre, sin líneas.
     *
     * SCRUM-929 (B3b Punto 4, decisión de Luis 2026-09-19) — `ajuste_itbms_delta` de la nueva factura
     * = el de la que reemplaza (se acumula a través de correcciones encadenadas) + el delta de ESTE
     * evento (`note->monto − facturaOrigen->monto`). Una corrección por FECHA (motivo que no cambia
     * la tasa) da delta 0 automáticamente: `correctedLines()` copia las líneas tal cual, así que
     * `note->monto` sale igual a `facturaOrigen->monto`. `InvoiceBillingPlanner` suma este campo de
     * las facturas activas de la familia para dejar de comparar/cerrar contra `card->amount` crudo
     * (ver ADR §"Decisiones de Luis" y el docblock de la migración `add_ajuste_itbms_delta_...`).
     */
    private function executeCorreccion(AdminContCreditNote $note, AdminContInvoice $facturaOrigen): void
    {
        $facturaOrigen->update(['anulada_at' => now()]);

        $facturaNueva = AdminContInvoice::create([
            'numero' => $this->invoiceFolio->next(),
            'order_id' => $facturaOrigen->order_id,
            'family_root_id' => $facturaOrigen->family_root_id,
            'tipo' => $facturaOrigen->tipo,
            'quote_id' => $facturaOrigen->quote_id,
            'itbms_rate_id' => $note->nuevo_tratamiento_itbms_rate_id ?? $facturaOrigen->itbms_rate_id,
            'sales_project_id' => $facturaOrigen->sales_project_id,
            'master_client_id' => $facturaOrigen->master_client_id,
            'cliente' => $facturaOrigen->cliente,
            'monto' => $note->monto,
            'saldo_aplicado' => 0.0,
            'ajuste_itbms_delta' => round((float) $facturaOrigen->ajuste_itbms_delta + ((float) $note->monto - (float) $facturaOrigen->monto), 2),
            'created_by' => $note->registrado_por,
            // SCRUM-1140 (Senior Review M1) — la NC de anulación lleva el receptor de la factura original CERTIFICADA (la DGI exige
            // el mismo, 9026); el reemplazo hereda ese mismo receptor congelado. Si la original nunca se certificó (rechazada,
            // error de configuración, FE apagada) no hay nada que igualar: el reemplazo queda sin receptor congelado y toma el
            // cliente vigente, que es justo lo que se suele corregir en ese caso.
            ...($facturaOrigen->cufe !== null && $facturaOrigen->receptor_tipo !== null
                ? [
                    'receptor_tipo' => $facturaOrigen->receptor_tipo,
                    'receptor_ruc' => $facturaOrigen->receptor_ruc,
                    'receptor_dv' => $facturaOrigen->receptor_dv,
                    'receptor_nombre' => $facturaOrigen->receptor_nombre,
                    'receptor_validado_at' => $facturaOrigen->receptor_validado_at,
                ]
                : []),
        ]);

        $facturaOrigen->loadMissing(['lines', 'itbmsRate']);
        if ($facturaOrigen->lines->isNotEmpty()) {
            $rate = $note->nuevo_tratamiento_itbms_rate_id !== null
                ? AdminContItbmsRate::findOrFail($note->nuevo_tratamiento_itbms_rate_id)
                : ($facturaOrigen->itbmsRate ?? ItbmsRateResolver::defaultRate());
            $lines = self::correctedLines($facturaOrigen, $rate);

            // Σ líneas == monto de la factura (que es el de la nota, ya calculado con estas mismas líneas
            // al registrarla; si entre el registro y la ejecución algo cambió, la diferencia va a la base).
            $diff = round((float) $note->monto - (float) array_sum(array_column($lines, 'total')), 2);
            [$lines] = InvoiceLineMath::repartir($lines, 'subtotal', $diff);

            foreach ($lines as $line) {
                $facturaNueva->lines()->create($line + ['itbms_rate_id' => $rate->id]);
            }
        }

        $note->update(['factura_nueva_id' => $facturaNueva->id]);

        // SCRUM-1183 — el dinero cobrado es del cliente: lo aplicado a la original (ya anulada) se asigna al reemplazo, hasta su monto.
        $this->paymentTransfer->transfer(
            $facturaOrigen, $facturaNueva, "Corrección de datos {$note->numero}: el pago pasa a {$facturaNueva->numero}", $note->aprobado_por ?? $note->registrado_por,
        );
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
        $query = AdminContInvoice::query()->with('itbmsRate', 'lines');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        /** @var AdminContInvoice|null $facturaOrigen */
        $facturaOrigen = $query->whereNull('anulada_at')->find($data['factura_origen_id']);
        if ($facturaOrigen === null) {
            // SCRUM-1019 (menor de 957) — una factura ya anulada (o inexistente) no se corrige: 404 con mensaje en español
            // (el controller lo traduce), en vez del 404 genérico "No query results for model" del framework.
            throw (new ModelNotFoundException(self::MSG_FACTURA_NO_CORREGIBLE))->setModel(AdminContInvoice::class, [$data['factura_origen_id']]);
        }

        // SCRUM-929 (Senior Review B3a-3) — una `parcial` SIN líneas (hoy solo posible por datos manuales/
        // seeders: `confirm()` siempre crea líneas para una parcial) caería a la ruta legada de
        // `correctionBreakdown()` y se "corregiría" con el monto de la cotización ENTERA, inflando Σ del
        // pedido muy por encima de lo que esa entrega factura. Fail closed en vez de adivinar.
        if ($facturaOrigen->tipo === AdminContInvoice::TIPO_PARCIAL && $facturaOrigen->lines->isEmpty()) {
            throw new CreditNoteStateException("La factura {$facturaOrigen->numero} es una entrega parcial sin detalle de líneas — no se puede corregir automáticamente. Contacte a Administración.");
        }

        // SCRUM-929 (Senior Review B3b, hallazgo ALTO 2026-09-19) — una segunda corrección registrada
        // mientras la primera sigue `pendiente_aprobacion` sobre la MISMA factura queda condenada a
        // fallar: cuando Gerencia aprueba la primera, la factura de origen se anula y nace su reemplazo: la
        // segunda corrección, que sigue apuntando a la factura YA ANULADA, ahora se rechaza limpio en
        // `decide()` (ver el `whereNull('anulada_at')` de esa consulta — B3a-1) en vez de un 500, pero
        // sigue siendo una nota que nunca podrá aprobarse (Gerencia solo puede rechazarla y volver a
        // registrarla contra el reemplazo). Se corta en el origen: no se admite una segunda corrección
        // pendiente sobre una factura que ya tiene una sin resolver.
        $pendientes = AdminContCreditNote::where('factura_origen_id', $facturaOrigen->id)
            ->where('estado', AdminContCreditNote::ESTADO_PENDIENTE_APROBACION)
            ->orderBy('id')
            ->get(['numero', 'correccion_pendiente_aprobacion']);
        if ($pendientes->isNotEmpty()) {
            // Pre-QA SCRUM-1116 (obs. 2): el mensaje distingue una corrección pendiente de cualquier otra nota pendiente
            // (descuento, devolución, anulación), que también bloquea corregir pero no es "una corrección".
            throw new CreditNoteStateException(
                $pendientes->contains(fn (AdminContCreditNote $n): bool => (bool) $n->correccion_pendiente_aprobacion)
                    ? "La factura {$facturaOrigen->numero} ya tiene una corrección pendiente de aprobación de Gerencia — resuelve esa antes de registrar otra."
                    : "La factura {$facturaOrigen->numero} tiene notas de crédito pendientes de aprobación de Gerencia ({$pendientes->pluck('numero')->implode(', ')}) — resuélvelas antes de corregirla."
            );
        }

        // SCRUM-1009 (D1, decisión de Luis 2026-09-30) — una factura que YA tiene notas de crédito
        // aplicadas no se corrige: la NC de anulación se calcula sobre el total original y no
        // descuenta lo ya acreditado (la DGI la rechaza por saldo, VALIDATE_B606_BALANCE), y el
        // reemplazo se emitiría por el total entero. Las `rechazada` por Gerencia no cuentan (nunca tuvieron
        // efecto). Una NC aplicada en AtlanticERP pero rechazada por la DGI SÍ bloquea: hay que resolverla
        // primero (Reintentar certificación). Las `pendiente_aprobacion` ya las cubre `$pendientes`.
        $notasAplicadas = AdminContCreditNote::where('factura_origen_id', $facturaOrigen->id)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->orderBy('id')
            ->pluck('numero');
        if ($notasAplicadas->isNotEmpty()) {
            throw new CreditNoteStateException(
                "La factura {$facturaOrigen->numero} ya tiene notas de crédito ({$notasAplicadas->implode(', ')}) — no se puede corregir. "
                .'Resuélvelas primero, o acredita el saldo y emite una factura nueva.'
            );
        }

        // SCRUM-1009 (Senior Review B1/S3) — dos configuraciones en las que la corrección no puede hacerse de forma
        // fiscalmente segura, y se bloquean en vez de dejar la factura de reemplazo en un limbo:
        //  · la original está `pendiente` de certificación (202 sin resolver): puede certificarse después y el
        //    reemplazo la duplicaría ante la DGI — hay que esperar a que se resuelva.
        //  · la FE está activa para facturas pero DESHABILITADA para notas de crédito: la NC de anulación quedaría
        //    `no_aplica`, la original seguiría vigente ante la DGI y el reemplazo sí se certificaría (doble factura).
        $settings = AdminContFiscalSettings::current();
        if (! $settings->sinFacturacionElectronica()) {
            if ($facturaOrigen->fe_estado === 'pendiente') {
                throw new CreditNoteStateException("La factura {$facturaOrigen->numero} todavía está pendiente de certificación ante la DGI — espera a que se resuelva antes de corregirla.");
            }
            // SCRUM-1009 (b) / SCRUM-1019 — la original figura rechazada / con error pero MSL puede YA tenerla (se certificó y la
            // respuesta se perdió, o contestó 9022): la NC de anulación quedaría `no_aplica` (nada que anular según AtlanticERP) y el
            // reemplazo se certificaría → DOBLE factura ante la DGI. Se bloquea hasta reconciliar con MSL.
            if ($facturaOrigen->cufe === null && $settings->pac_doc_factura_habilitado
                && in_array($facturaOrigen->fe_estado, ['rechazado', 'error_configuracion'], true)) {
                $mslLaTiene = $this->certification->mslYaTieneLaFactura($facturaOrigen);
                if ($mslLaTiene === true) {
                    throw new CreditNoteStateException("MSL ya tiene la factura {$facturaOrigen->numero} aunque figura como {$facturaOrigen->fe_estado} — no se puede corregir. Usa «Reconciliar con MSL» en la factura para recuperar su CUFE y luego corrígela.");
                }
                if ($mslLaTiene === null) {
                    throw new CreditNoteStateException("No se pudo verificar con MSL si la factura {$facturaOrigen->numero} ya existe ante la DGI — inténtalo de nuevo en unos minutos.");
                }
            }
            if ($facturaOrigen->cufe !== null && $settings->pac_doc_factura_habilitado && ! $settings->pac_doc_nota_credito_habilitado) {
                throw new CreditNoteStateException('No se puede corregir una factura ya certificada mientras las notas de crédito electrónicas estén deshabilitadas en Configuración Fiscal.');
            }
        }

        /** @var BodegaOrder $order */
        $order = BodegaOrder::findOrFail($facturaOrigen->order_id);
        // SCRUM-929 (B3a) — una factura con líneas se corrige desde SUS líneas (la cotización solo se usa
        // para mostrar); se prefiere la cotización con la que se emitió (`quote_id`) a la vigente hoy.
        $quote = $facturaOrigen->lines->isNotEmpty() && $facturaOrigen->quote_id !== null
            ? (Quote::query()->with('parts.items')->find($facturaOrigen->quote_id) ?? $order->linkedQuote())
            : $order->linkedQuote();
        if ($quote === null) {
            throw new \RuntimeException("La factura {$facturaOrigen->numero} no tiene una cotización vinculada — no se puede armar la factura de corrección.");
        }

        $rateId = $data['motivo_correccion'] !== AdminContCreditNote::MOTIVO_CORRECCION_FECHA
            ? $data['nuevo_tratamiento_itbms_rate_id']
            : $facturaOrigen->itbms_rate_id;
        /** @var AdminContItbmsRate $rate */
        $rate = AdminContItbmsRate::findOrFail($rateId);

        // Guarda de la opción A (2026-10-05) — si la factura de origen ya está certificada ante la DGI, la NC de
        // anulación se certificaría pero la factura de REEMPLAZO con una tasa sin código DGI (hoy la retención 3.5%)
        // no podría emitirse: quedaría anulada fiscalmente sin reemplazo. Se rechaza ANTES de crear nada.
        if ($facturaOrigen->cufe !== null) {
            try {
                (new ItbmsCodeMapper)->map($rate->porcentaje);
            } catch (UnmappableItbmsRateException $e) {
                throw new CreditNoteStateException('No se puede corregir a esta tasa: la factura ya está certificada ante la DGI y su reemplazo no se podría emitir. '.$e->getMessage());
            }
        }

        return [$facturaOrigen, $order, $quote, $rate];
    }

    /**
     * REQ-489 RN2/RN3 — reusa `InvoiceService::fiscalBreakdown()` (mismos ítems/subtotal/
     * descuentos que cualquier otra factura del sistema, RN2 — nunca los productos cambian, RN3),
     * pero recalcula `itbms`/`total` con la tasa RESUELTA en `correccionContext()` en vez de la
     * tasa por defecto que usa `Quote::itbms()` — es exactamente el dato que "Corrección de datos"
     * puede estar corrigiendo.
     *
     * SCRUM-929 (B3a) — si la factura de origen tiene `invoice_lines` (una `parcial`, o un `completo`
     * emitido desde SCRUM-929), el desglose sale de ESAS líneas (`correctedLines()`), no de la
     * cotización entera: una parcial de una entrega no se "corrige" al total del pedido.
     *
     * @return array{subtotal: float, descuentos: float, itbms: float, total: float, items: list<array<string, mixed>>}
     */
    private static function correctionBreakdown(AdminContInvoice $facturaOrigen, Quote $quote, AdminContItbmsRate $rate): array
    {
        if ($facturaOrigen->lines->isNotEmpty()) {
            $quoteItems = $quote->parts->flatMap(fn ($part) => $part->items)->keyBy('id');

            return InvoiceService::breakdownFromLines(self::correctedLines($facturaOrigen, $rate), $quoteItems, $quote);
        }

        $breakdown = InvoiceService::fiscalBreakdown($quote);

        $itbms = round($quote->netTotal() * ($rate->porcentaje / 100), 2);

        $breakdown['itbms'] = $itbms;
        $breakdown['total'] = round($quote->netTotal() + $itbms, 2);

        return $breakdown;
    }

    /**
     * Líneas de la factura de reemplazo: copia de las de la original. Si la corrección no cambia la tasa
     * (`$rate` es la que la factura ya tenía: motivo "fecha"), se copian tal cual — mismo monto, así Σ
     * facturas del pedido no se mueve. Si cambia la tasa, el ITBMS de cada línea se recalcula sobre su
     * base (que no cambia) y el redondeo del ITBMS de la factura entera se reparte sin líneas negativas.
     *
     * @return list<array{quote_item_id: int|null, order_item_id: int|null, descripcion: string, cantidad: float, precio_unitario: float, subtotal: float, itbms: float, total: float}>
     */
    private static function correctedLines(AdminContInvoice $facturaOrigen, AdminContItbmsRate $rate): array
    {
        $lines = array_values($facturaOrigen->lines->map(fn ($l): array => [
            'quote_item_id' => $l->quote_item_id,
            'order_item_id' => $l->order_item_id,
            'descripcion' => $l->descripcion,
            'cantidad' => $l->cantidad,
            'precio_unitario' => $l->precio_unitario,
            'subtotal' => $l->subtotal,
            'itbms' => $l->itbms,
            'total' => $l->total,
        ])->all());

        if ($rate->id === (int) $facturaOrigen->itbms_rate_id) {
            return $lines;
        }

        /** @var list<array{quote_item_id: int|null, order_item_id: int|null, descripcion: string, cantidad: float, precio_unitario: float, subtotal: float, itbms: float, total: float}> $lines */
        $lines = array_map(static function (array $l) use ($rate): array {
            $itbms = round($l['subtotal'] * $rate->porcentaje / 100, 2);

            return [...$l, 'itbms' => $itbms, 'total' => round($l['subtotal'] + $itbms, 2)];
        }, $lines);

        $objetivo = round((float) array_sum(array_column($lines, 'subtotal')) * $rate->porcentaje / 100, 2);
        [$lines] = InvoiceLineMath::repartir($lines, 'itbms', round($objetivo - (float) array_sum(array_column($lines, 'itbms')), 2));

        return $lines;
    }
}
