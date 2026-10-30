<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Models\AdminContPaymentAttachment;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — registro de cobros. Batch 6
 * (SCRUM-545→548, REQ-468→471) agrega comprobante/recibo/estado real/historial/detalle. Batch 7
 * (SCRUM-549→552, REQ-472→475) agrega ver comprobante/recibo formal/apertura automática (sin
 * cambio de servicio, ver `CobrosPage.tsx`)/confirmación manual.
 */
final class PaymentService
{
    public function __construct(
        private readonly AbonoService $abonos,
        private readonly AdminContPaymentFolioService $folio,
        private readonly AdminContPaymentAttachmentService $attachments,
        private readonly AdminContPaymentConfirmationFolioService $confirmationFolio,
        // SCRUM-1168 F1 — bloqueo por cliente, único escritor de `saldo_aplicado` y libro de movimientos (modo sombra).
        private readonly ClientLock $clientLock,
        private readonly InvoiceBalanceWriter $balance,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * REQ-462 (buscador de cliente para el formulario, sin endpoint propio de VentasDiseno —
     * este módulo no tiene `ventas_diseno.read`, así que reusa el modelo directo).
     *
     * SCRUM-865 — `$onlyWithInvoice` (default false) restringe a clientes con al menos UNA
     * factura emitida (pagada o no) — usado por "Registrar nueva nota" de Notas de Crédito.
     * SCRUM-905 — `$onlyWithPendingInvoice` (default false) es más angosto: solo clientes con
     * alguna factura con SALDO PENDIENTE > 0 (mismo criterio que `openInvoicesForClient()`) — lo
     * usa el buscador de "Registrar nuevo cobro", donde un cliente sin nada pendiente no tiene
     * sentido de negocio como destino de un cobro. Los 2 flags son independientes a propósito
     * (nunca reusar uno para el caso del otro): "cualquier factura" y "factura pendiente" son
     * conjuntos de clientes distintos.
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchMasterClients(string $search, bool $onlyWithInvoice = false, bool $onlyWithPendingInvoice = false): array
    {
        $query = MasterClient::query()->orderBy('name');
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }
        if ($onlyWithInvoice) {
            // Sin relación Eloquent entre módulos (MasterClient es de VentasDiseno, AdminContInvoice
            // de AdminCont) — subquery directa, mismo criterio ya usado en el resto del módulo para
            // cruces cross-schema sin FK declarada.
            $query->whereIn('id', AdminContInvoice::query()->select('master_client_id')->whereNotNull('master_client_id'));
        }
        if ($onlyWithPendingInvoice) {
            $query->whereIn('id', AdminContInvoice::query()->select('master_client_id')
                ->where('es_incobrable', false)
                ->whereNull('anulada_at')
                ->whereColumn('saldo_aplicado', '<', 'monto')
                ->whereNotNull('master_client_id'));
        }

        return array_values($query->limit(20)->get(['id', 'name'])
            ->map(fn (MasterClient $c): array => ['id' => $c->id, 'name' => $c->name])
            ->all());
    }

    /**
     * REQ-464 RN1 — facturas con saldo pendiente > 0 del cliente, no incobrables ni anuladas.
     * Misma fórmula de saldo pendiente que `InvoiceService::agingBuckets()` (`monto - saldo_aplicado`).
     *
     * SCRUM-902 §4.2/4.3 — `abono_obligatorio` (null si no aplica) muestra el abono de nivel
     * Factura/Proyecto ya re-asociado a esta factura: el frontend lo usa para bloquear el monto a
     * cobrar y mostrar el desglose (monto original / abono aplicado / neto), nunca como una opción
     * que el usuario pueda desactivar. Es un PREVIEW de solo lectura — `register()` vuelve a
     * calcularlo bajo lock, nunca confía en lo que mande el cliente HTTP.
     *
     * SCRUM-931 — `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` agregados para que
     * "Registrar Cobro" pueda filtrar Proyecto/# Cotización en el frontend sobre esta misma lista
     * (sin endpoint de cascada nuevo, ver ADR-SCRUM931) en vez de traer siempre TODAS las facturas
     * abiertas del cliente sin poder acotar a una combinación específica.
     *
     * @return list<array{id: int, numero: string, monto: float, saldo_pendiente: float, abono_obligatorio: ?array{abono_id: int, monto: float}, neto_a_cobrar: float, sales_project_id: ?int, proyecto: ?string, quote_id: ?int, cotizacion_folio: ?string}>
     */
    public function openInvoicesForClient(int $masterClientId): array
    {
        $invoices = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->where('es_incobrable', false)
            ->whereNull('anulada_at')
            ->get();

        $projectNames = SalesProject::query()
            ->whereIn('id', $invoices->pluck('sales_project_id')->filter()->unique())
            ->pluck('name', 'id');

        $orders = Order::query()
            ->whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($invoices as $invoice) {
            $pendiente = round($invoice->monto - $invoice->saldo_aplicado, 2);
            if ($pendiente <= 0) {
                continue;
            }

            $abono = $this->abonoObligatorioPreview($invoice, $pendiente);
            $quote = $orders->get($invoice->order_id)?->linkedQuote();
            $rows[] = [
                'id'                => $invoice->id,
                'numero'            => $invoice->numero,
                'monto'             => $invoice->monto,
                'saldo_pendiente'   => $pendiente,
                'abono_obligatorio' => $abono,
                'neto_a_cobrar'     => round($pendiente - ($abono['monto'] ?? 0.0), 2),
                'sales_project_id'  => $invoice->sales_project_id,
                'proyecto'          => $invoice->sales_project_id !== null ? $projectNames->get($invoice->sales_project_id) : null,
                'quote_id'          => $quote?->id,
                'cotizacion_folio'  => $quote?->folio,
            ];
        }

        return $rows;
    }

    /** REQ-462 RN2 — el nombre del cliente cuando el formulario llega preseleccionado desde
     *  Facturación (query string solo trae el id, nunca el nombre). */
    public function masterClientName(int $masterClientId): ?string
    {
        return MasterClient::find($masterClientId)?->name;
    }

    /** SCRUM-902 §4.4 — saldo a favor de nivel Cliente master disponible (abonos + notas de
     *  crédito `saldo_favor`), fuente única compartida con Saldo a Favor de Clientes (903). */
    public function creditBalanceForClient(int $masterClientId): float
    {
        return $this->abonos->saldoClienteDisponible($masterClientId);
    }

    /**
     * Fase 13 (2.2) — desglose por nivel del saldo a favor (ver `AbonoService::desgloseSaldoCliente()`).
     *
     * @return array{aplicable: float, a_cuenta: float, reservado: float, total: float}
     */
    public function creditBreakdownForClient(int $masterClientId): array
    {
        return $this->abonos->desgloseSaldoCliente($masterClientId);
    }

    /**
     * SCRUM-902 §4.2/4.3 — abono obligatorio (nivel Factura, o Proyecto ya re-asociado) que cubre
     * esta factura, capado a lo pendiente. Si hubiera más de un abono apuntando a la misma factura
     * (caso raro, el ticket no lo prohíbe), se consume el más antiguo primero; el resto queda
     * disponible para un cobro futuro sobre la misma factura — nunca se pierde, solo se difiere.
     *
     * @return array{abono_id: int, monto: float}|null
     */
    private function abonoObligatorioPreview(AdminContInvoice $invoice, float $pendiente): ?array
    {
        $candidatos = $this->abonos->abonosObligatoriosParaFactura($invoice->id);
        if ($candidatos === []) {
            return null;
        }

        $primero = $candidatos[0];
        $monto   = round(min($primero['disponible'], $pendiente), 2);

        return $monto > 0 ? ['abono_id' => $primero['abono_id'], 'monto' => $monto] : null;
    }

    /**
     * REQ-466 RN1/RN2/RN4/RN5 — auto-selección de cuenta bancaria destino por método. Métodos sin
     * cuenta bancaria (RN4) devuelven null directo, sin consultar nada. El match es por nombre real
     * de banco+tipo — si la cuenta esperada todavía no existe en Cuentas Bancarias (creada a mano
     * por Contabilidad/Asistente), devuelve null y el campo queda vacío para completar manualmente (RN3: el
     * campo sigue siendo editable, esto nunca bloquea el registro).
     */
    public function defaultBankAccountFor(string $metodoPago): ?AdminContBankAccount
    {
        if (PaymentMethods::sinCuentaBancaria($metodoPago)) {
            return null;
        }

        if ($metodoPago === AdminContPayment::METODO_TARJETA) {
            return AdminContBankAccount::query()
                ->where('activa', true)
                ->where('tipo_cuenta', AdminContBankAccount::TIPO_TARJETA_CREDITO)
                ->orderBy('id')
                ->first();
        }

        // Fase 4a: antes se elegía por NOMBRE de banco (tarjeta → un banco fijo, el resto → otro, cuentas del cliente anterior). Ahora es la
        // primera cuenta activa del tipo que corresponde: con una sola cuenta por tipo (el caso real) da el mismo resultado.
        return AdminContBankAccount::query()
            ->where('activa', true)
            ->where('tipo_cuenta', AdminContBankAccount::TIPO_CORRIENTE)
            ->orderBy('id')
            ->first();
    }

    /**
     * REQ-463 — 4 tarjetas del mes calendario en curso, solo cobros `estado = confirmado` (RN1).
     *
     * @return array{total_cobrado: float, cantidad: int, promedio: float, metodo_principal: array{metodo: string, porcentaje: float}|null}
     */
    public function summaryForCurrentMonth(): array
    {
        $payments = AdminContPayment::query()
            ->where('estado', AdminContPayment::ESTADO_CONFIRMADO)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();

        $cantidad = $payments->count();
        $total    = round((float) $payments->sum('monto_recibido'), 2);

        $metodoPrincipal = null;
        if ($cantidad > 0) {
            $porMetodo = $payments->countBy('metodo_pago')->sortDesc();
            /** @var string $metodo */
            $metodo    = $porMetodo->keys()->first();
            $metodoPrincipal = [
                'metodo'     => $metodo,
                'porcentaje' => round(($porMetodo->first() / $cantidad) * 100, 1),
            ];
        }

        return [
            'total_cobrado'    => $total,
            'cantidad'         => $cantidad,
            'promedio'         => $cantidad > 0 ? round($total / $cantidad, 2) : 0.0,
            'metodo_principal' => $metodoPrincipal,
        ];
    }

    /**
     * SCRUM-1168 F2 — registra un cobro con el concepto nuevo: DINERO QUE ENTRA, con o sin factura. `$aplicaciones` (opcional) es la aplicación directa
     * que el usuario decidió en ese momento, con monto por factura (parcial permitido); lo que no se aplica queda como saldo a favor del cliente
     * (el libro lo reconoce como disponible), a la espera de la compensación. Con un método que espera confirmación, el dinero no se aplica hasta
     * confirmarse (igual que hoy); con efectivo/retención/ajuste, se aplica en el acto.
     *
     * Retención de impuestos y Ajuste de cuenta no son dinero que entra a una cuenta: deben aplicarse completos a facturas (nunca dejan saldo).
     *
     * @param list<array{invoice_id: int, monto: float}> $aplicaciones
     * @throws InvoiceStateException si una factura no admite ese monto (saldada, anulada, incobrable, o el monto supera lo pendiente).
     * @throws PaymentStateException si lo aplicado supera el cobro o las facturas no son del cliente.
     */
    public function registerV2(
        int $masterClientId,
        float $monto,
        string $metodoPago,
        ?int $bankAccountId,
        ?string $numeroDocumentoRetencion,
        ?string $comentarioAjuste,
        ?string $referencia,
        ?UploadedFile $comprobante,
        array $aplicaciones,
        User $actor,
    ): AdminContPayment {
        $monto = round($monto, 2);
        $sinCuenta = PaymentMethods::sinCuentaBancaria($metodoPago);
        $totalAplicado = round(array_sum(array_column($aplicaciones, 'monto')), 2);

        if ($totalAplicado > $monto + 0.005) {
            throw new PaymentStateException('Lo que se aplica a facturas ($'.number_format($totalAplicado, 2).') supera el monto del cobro ($'.number_format($monto, 2).').');
        }
        if ($sinCuenta && abs($totalAplicado - $monto) > 0.005) {
            throw new PaymentStateException('Una retención de impuestos o un ajuste de cuenta debe aplicarse completo a facturas: no genera saldo a favor.');
        }

        $payment = DB::transaction(function () use ($masterClientId, $monto, $metodoPago, $bankAccountId, $numeroDocumentoRetencion, $comentarioAjuste, $referencia, $aplicaciones, $actor, $sinCuenta): AdminContPayment {
            $this->clientLock->lock($masterClientId);

            $ids = array_column($aplicaciones, 'invoice_id');
            /** @var \Illuminate\Support\Collection<int, AdminContInvoice> $invoices */
            $invoices = $ids === [] ? collect() : AdminContInvoice::query()
                ->whereIn('id', $ids)->where('master_client_id', $masterClientId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($invoices->count() !== count($ids)) {
                throw new PaymentStateException('Alguna de las facturas indicadas no existe o no pertenece al cliente.');
            }
            foreach ($aplicaciones as $a) {
                $invoice   = $invoices->get($a['invoice_id']) ?? throw new PaymentStateException('Factura no encontrada.');
                $pendiente = round($invoice->monto - $invoice->saldo_aplicado, 2);
                if ($invoice->anulada_at !== null || $invoice->es_incobrable) {
                    throw new InvoiceStateException("La factura {$invoice->numero} está anulada o marcada como incobrable: no admite cobros.");
                }
                if ($a['monto'] > $pendiente + 0.005) {
                    throw new InvoiceStateException(
                        "La factura {$invoice->numero} tiene pendiente $".number_format(max($pendiente, 0.0), 2).' y se intenta aplicar $'.number_format($a['monto'], 2).'.'
                    );
                }
            }

            $estadoInicial = PaymentMethods::inmediato($metodoPago)
                ? AdminContPayment::ESTADO_CONFIRMADO
                : AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION;

            $payment = AdminContPayment::create([
                'numero_recibo'              => $this->folio->next(),
                'master_client_id'           => $masterClientId,
                'monto_recibido'             => $monto,
                'saldo_favor_aplicado'       => 0,
                'metodo_pago'                => $metodoPago,
                'referencia'                 => $referencia,
                'bank_account_id'            => $sinCuenta ? null : $bankAccountId,
                'numero_documento_retencion' => $numeroDocumentoRetencion,
                'comentario_ajuste'          => $comentarioAjuste,
                'estado'                     => $estadoInicial,
                'cobros_v2'                  => true,
                'created_by'                 => $actor->id,
            ]);

            foreach ($aplicaciones as $a) {
                $payment->invoiceLines()->create([
                    'invoice_id'           => $a['invoice_id'],
                    'monto_aplicado'       => $a['monto'],
                    'abono_id'             => null,
                    'monto_abono_aplicado' => 0,
                    'fecha_aplicacion'     => $estadoInicial === AdminContPayment::ESTADO_CONFIRMADO ? now() : null,
                ]);
            }

            if ($estadoInicial === AdminContPayment::ESTADO_CONFIRMADO) {
                foreach ($aplicaciones as $a) {
                    $this->balance->apply($invoices->get($a['invoice_id']) ?? throw new PaymentStateException('Factura no encontrada.'), $a['monto']);
                }

                // En el concepto nuevo el libro ES la fuente de verdad del disponible: se escribe estricto (si falla, el cobro no se registra).
                $this->ledger->ingreso($payment, $monto, AdminContPaymentMovement::ORIGEN_DIRECTA, $actor->id);
                foreach ($aplicaciones as $a) {
                    $this->ledger->aplicacion($payment, $invoices->get($a['invoice_id']) ?? throw new PaymentStateException('Factura no encontrada.'), $a['monto'], AdminContPaymentMovement::ORIGEN_DIRECTA, $actor->id);
                }

                if (! $sinCuenta && $payment->bank_account_id !== null) {
                    AdminContBankMovement::create([
                        'bank_account_id' => $payment->bank_account_id,
                        'fecha'           => now()->toDateString(),
                        'tipo'            => AdminContBankMovement::TIPO_COBRO,
                        'concepto'        => "Cobro {$payment->numero_recibo}",
                        'monto'           => $monto,
                        'source_type'     => AdminContBankMovement::SOURCE_PAYMENT,
                        'source_id'       => $payment->id,
                    ]);
                }
            }

            return $payment;
        });

        if ($comprobante !== null) {
            $this->attachments->upload($comprobante, $payment, $actor->id);
        }

        return $payment;
    }

    /**
     * REQ-470 — historial de cobros con búsqueda + filtros combinados (AND). El total general
     * (`total`) nunca se filtra, sostiene el "Mostrando X de Y" del RN3 sin otro roundtrip.
     *
     * @param array{search?: ?string, cliente?: ?string, metodo?: ?string, estado?: ?string, desde?: ?string, hasta?: ?string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function historial(array $filters): array
    {
        $total = AdminContPayment::query()->count();

        $query = AdminContPayment::query()
            ->with(['invoiceLines.invoice', 'attachment'])
            ->orderByDesc('created_at');

        if (($filters['metodo'] ?? null) !== null) {
            $query->where('metodo_pago', $filters['metodo']);
        }
        if (($filters['estado'] ?? null) !== null) {
            $query->where('estado', $filters['estado']);
        }
        if (($filters['desde'] ?? null) !== null) {
            $query->whereDate('created_at', '>=', $filters['desde']);
        }
        if (($filters['hasta'] ?? null) !== null) {
            $query->whereDate('created_at', '<=', $filters['hasta']);
        }

        $payments = $query->get();

        $clientNames = MasterClient::query()
            ->whereIn('id', $payments->pluck('master_client_id')->unique())
            ->pluck('name', 'id');

        $userNames = User::query()
            ->whereIn('id', $payments->pluck('created_by')->filter()->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $rows = $payments->map(function (AdminContPayment $p) use ($clientNames, $userNames): array {
            $cliente = $clientNames->get($p->master_client_id) ?? '';

            return [
                'id'                => $p->id,
                'numero_recibo'     => $p->numero_recibo,
                'fecha'             => $p->created_at->toIso8601String(),
                'cliente'           => $cliente,
                'facturas'          => $p->invoiceLines->map(fn ($l) => $l->invoice?->numero)->filter()->values()->all(),
                'monto_recibido'    => $p->monto_recibido,
                'metodo_pago'       => $p->metodo_pago,
                'estado'            => $p->estado,
                'referencia'        => $p->referencia,
                'registrado_por'    => $p->created_by !== null ? ($userNames->get($p->created_by) ?? '') : '',
                'tiene_comprobante' => $p->attachment !== null,
            ];
        });

        if (($filters['cliente'] ?? null) !== null) {
            $rows = $rows->filter(fn (array $r) => $r['cliente'] === $filters['cliente']);
        }

        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $rows = $rows->filter(function (array $r) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $r['cliente'], implode(' ', $r['facturas']), $r['numero_recibo'], (string) $r['referencia'],
                ]));

                return str_contains($haystack, $needle);
            });
        }

        return ['rows' => array_values($rows->all()), 'total' => $total];
    }

    /**
     * REQ-471 — detalle completo de un cobro, incluyendo las facturas que cubrió con el monto
     * aplicado a cada una (RN1). REQ-475 Escenario 2 — incluye la confirmación (si existe), con
     * quién la hizo, para la trazabilidad del detalle.
     */
    public function find(int $id): ?AdminContPayment
    {
        return AdminContPayment::query()
            ->with(['invoiceLines.invoice', 'bankAccount', 'attachment', 'confirmation.confirmedBy'])
            ->find($id);
    }

    /** SCRUM-1168 F5 — reemplaza el comprobante de un cobro ya registrado (el archivo anterior queda en el almacenamiento como traza). */
    public function replaceComprobante(AdminContPayment $payment, UploadedFile $file, int $actorId): void
    {
        AdminContPaymentAttachment::query()->where('payment_id', $payment->id)->delete();
        $this->attachments->upload($file, $payment, $actorId);
    }

    /**
     * REQ-472 — comprobante adjunto de un cobro, con URL firmada de 15 min, o `null` si no tiene
     * ninguno (RN2 — nunca un error, un estado explícito).
     *
     * @return array{url: string, nombre_archivo: string, mime_type: string, uploaded_by: ?string, created_at: string}|null
     */
    public function attachmentDetail(AdminContPayment $payment): ?array
    {
        $attachment = $payment->attachment ?? AdminContPaymentAttachment::query()->where('payment_id', $payment->id)->first();
        if ($attachment === null) {
            return null;
        }

        $uploader = $attachment->uploader;

        return [
            'url'            => $this->attachments->presignedUrl($attachment),
            'nombre_archivo' => $attachment->nombre_archivo,
            'mime_type'      => $attachment->mime_type,
            'uploaded_by'    => $uploader !== null ? trim("{$uploader->first_name} {$uploader->last_name}") : null,
            'created_at'     => $attachment->created_at->toIso8601String(),
        ];
    }

    /**
     * REQ-475 — confirma manualmente un cobro "Esperando confirmación" (RN5, sin validación
     * bancaria automática, depende 100% de quien la presiona). Aplica acá lo que `register()`
     * difirió cuando el cobro arrancó `esperando_confirmacion` (RN3): reduce el saldo pendiente de
     * cada factura cubierta, aplica el saldo a favor si lo hubo, y deja el movimiento en el ledger
     * `bank_movements` — mismo cálculo que el camino "confirmado de inmediato" de `register()`,
     * solo que diferido hasta este momento. Genera su propio folio CONF-XXX (RN2), independiente
     * del REC-XXX, con quién confirmó y cuándo.
     *
     * @throws PaymentStateException RN1 — el cobro no existe o ya no está `esperando_confirmacion`
     *     (confirmarlo 2 veces, o confirmar uno que arrancó `confirmado`, nunca es válido).
     */
    public function confirm(int $paymentId, User $actor): AdminContPayment
    {
        return $this->confirmWithAdjustments($paymentId, $actor)['payment'];
    }

    /**
     * SCRUM-1168 F0 — confirmar NUNCA se rechaza por el estado de las facturas: el dinero ya entró al banco, es un hecho. Lo que
     * `register()` dejó diferido se aplica ahora **hasta donde cabe**; si entre el registro y la confirmación otro cobro o una nota de
     * crédito dejó a una factura sin lugar (o el saldo a favor / abono que este cobro iba a usar ya lo consumió otro), lo que no se puede
     * aplicar **queda como saldo a favor del cliente**, esperando la compensación, y el resultado se devuelve para mostrárselo al usuario.
     * Antes se sumaba a ciegas y la factura quedaba con más cobrado que su valor.
     *
     * @return array{payment: AdminContPayment, ajustes: ?array{excedente_a_saldo_favor: float, saldo_insuficiente: float, facturas: list<array{numero: string, solicitado: float, aplicado: float}>}}
     * @throws PaymentStateException RN1 — el cobro no existe o ya no está `esperando_confirmacion`.
     */
    public function confirmWithAdjustments(int $paymentId, User $actor): array
    {
        return DB::transaction(function () use ($paymentId, $actor): array {
            /** @var AdminContPayment|null $payment */
            $payment = AdminContPayment::query()->where('id', $paymentId)->lockForUpdate()->first();

            if ($payment === null) {
                throw new PaymentStateException('Cobro no encontrado.');
            }
            if ($payment->estado !== AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION) {
                throw new PaymentStateException('Este cobro ya no está esperando confirmación.');
            }

            // SCRUM-1168 F1 — orden de bloqueos: cobro (arriba) → cliente → facturas por id → abonos/saldo.
            $this->clientLock->lock($payment->master_client_id);

            // Las facturas se bloquean todas juntas y en orden por id (sin bloqueos cruzados entre confirmaciones simultáneas).
            /** @var \Illuminate\Support\Collection<int, AdminContInvoice> $invoicesLocked */
            $invoicesLocked = AdminContInvoice::query()
                ->whereIn('id', $payment->invoiceLines->pluck('invoice_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $plan = $this->planConfirmation($payment, $invoicesLocked, bloquear: true);

            $lines = [];
            foreach ($plan['lines'] as $item) {
                $line    = $item['line'];
                $invoice = $item['invoice'];
                if ($invoice !== null) {
                    $this->balance->apply($invoice, $item['aplicable']);
                }

                $cambios = ['fecha_aplicacion' => now()];
                if (abs($item['aplicable'] - (float) $line->monto_aplicado) > 0.005) {
                    $cambios['monto_aplicado'] = $item['aplicable'];
                }
                if (abs($item['abono_real'] - (float) $line->monto_abono_aplicado) > 0.005) {
                    $cambios['monto_abono_aplicado'] = $item['abono_real'];
                }
                $line->update($cambios);
                $lines[$line->invoice_id] = ['abono_id' => $line->abono_id, 'monto_abono' => $item['abono_real']];
            }

            if (abs($plan['saldo_real'] - (float) $payment->saldo_favor_aplicado) > 0.005) {
                $payment->update(['saldo_favor_aplicado' => $plan['saldo_real']]);
            }

            $notasConsumo = $this->concretarAbonosYSaldo($payment, $lines);

            // Concepto nuevo: el excedente ya es disponible en el libro (ingreso menos lo aplicado), no hace falta un abono aparte.
            if ($plan['excedente'] > 0.005 && ! $payment->cobros_v2) {
                $this->abonos->registrarExcedenteDeCobro($payment, $plan['excedente']);
            }

            $this->registrarEnLibro(
                $payment,
                array_values(array_filter(array_map(
                    fn (array $item): ?array => $item['invoice'] !== null ? ['invoice' => $item['invoice'], 'monto' => $item['aplicable']] : null,
                    $plan['lines'],
                ))),
                $notasConsumo,
                $actor->id,
            );

            if ($payment->monto_recibido > 0 && $payment->bank_account_id !== null) {
                AdminContBankMovement::create([
                    'bank_account_id' => $payment->bank_account_id,
                    'fecha'           => now()->toDateString(),
                    'tipo'            => AdminContBankMovement::TIPO_COBRO,
                    'concepto'        => "Cobro {$payment->numero_recibo}",
                    'monto'           => $payment->monto_recibido,
                    'source_type'     => AdminContBankMovement::SOURCE_PAYMENT,
                    'source_id'       => $payment->id,
                ]);
            }

            $payment->update(['estado' => AdminContPayment::ESTADO_CONFIRMADO]);

            $payment->confirmation()->create([
                'numero_confirmacion' => $this->confirmationFolio->next(),
                'confirmed_by'        => $actor->id,
                'confirmed_at'        => now(),
            ]);

            return ['payment' => $payment, 'ajustes' => $this->presentAdjustments($plan)];
        });
    }

    /**
     * SCRUM-1168 F0 — qué pasaría al confirmar este cobro AHORA, sin tocar nada (para avisarle al usuario antes de que confirme).
     * `null` = la confirmación se aplica tal cual se registró; si no, el detalle de lo que se aplica y de lo que pasa a saldo a favor.
     *
     * @return array{excedente_a_saldo_favor: float, saldo_insuficiente: float, facturas: list<array{numero: string, solicitado: float, aplicado: float}>}|null
     * @throws PaymentStateException si el cobro no existe o no está esperando confirmación.
     */
    public function previewConfirmation(int $paymentId): ?array
    {
        /** @var AdminContPayment|null $payment */
        $payment = AdminContPayment::query()->find($paymentId);
        if ($payment === null) {
            throw new PaymentStateException('Cobro no encontrado.');
        }
        if ($payment->estado !== AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION) {
            throw new PaymentStateException('Este cobro ya no está esperando confirmación.');
        }

        $invoices = AdminContInvoice::query()->whereIn('id', $payment->invoiceLines->pluck('invoice_id')->all())->get()->keyBy('id');

        return $this->presentAdjustments($this->planConfirmation($payment, $invoices, bloquear: false));
    }

    /**
     * Reparto del dinero del cobro entre sus facturas, con el estado ACTUAL. Fondos = efectivo recibido + saldo a favor del cliente realmente
     * disponible (hasta lo solicitado) + abonos obligatorios realmente disponibles (hasta lo solicitado). Cada factura recibe lo que cabe
     * (`pendiente actual`) y lo solicitado, en orden; lo que sobra es excedente. Sin carreras todo coincide con lo registrado (cero ajustes).
     *
     * @param \Illuminate\Support\Collection<int, AdminContInvoice> $invoices
     * @return array{lines: list<array{line: \App\Modules\AdminCont\Models\AdminContPaymentInvoice, invoice: ?AdminContInvoice, solicitado: float, aplicable: float, abono_real: float}>, saldo_real: float, saldo_insuficiente: float, abono_insuficiente: float, excedente: float}
     */
    private function planConfirmation(AdminContPayment $payment, \Illuminate\Support\Collection $invoices, bool $bloquear): array
    {
        $saldoSolicitado = round((float) $payment->saldo_favor_aplicado, 2);
        $saldoReal = $saldoSolicitado > 0
            ? min($saldoSolicitado, max(0.0, $this->abonos->saldoClienteDisponible($payment->master_client_id, $bloquear)))
            : 0.0;

        $abonoIds = $payment->invoiceLines->pluck('abono_id')->filter()->unique()->values()->all();
        $abonosQuery = AdminContAbono::query()->whereIn('id', $abonoIds);
        if ($bloquear) {
            $abonosQuery->orderBy('id')->lockForUpdate();
        }
        $abonos = $abonosQuery->get()->keyBy('id');

        $fondos = round((float) $payment->monto_recibido + $saldoReal, 2);
        $abonoInsuficiente = 0.0;
        $items = [];
        foreach ($payment->invoiceLines->sortBy('id') as $line) {
            $abonoReal = 0.0;
            if ($line->abono_id !== null && (float) $line->monto_abono_aplicado > 0) {
                $disponible = (float) ($abonos->get($line->abono_id)?->montoDisponible() ?? 0.0);
                $abonoReal  = round(min((float) $line->monto_abono_aplicado, max(0.0, $disponible)), 2);
                $abonoInsuficiente += (float) $line->monto_abono_aplicado - $abonoReal;
            }
            $fondos += $abonoReal;
            $items[] = ['line' => $line, 'invoice' => $invoices->get($line->invoice_id), 'solicitado' => round((float) $line->monto_aplicado, 2), 'abono_real' => $abonoReal];
        }

        $restante = round($fondos, 2);
        foreach ($items as $i => $item) {
            $invoice = $item['invoice'];
            $cabe    = $invoice === null ? 0.0 : max(0.0, round($invoice->monto - $invoice->saldo_aplicado, 2));
            $aplica  = round(min($item['solicitado'], $cabe, $restante), 2);
            $restante = round($restante - $aplica, 2);
            $items[$i]['aplicable'] = $aplica;
        }

        return [
            'lines'              => $items,
            'saldo_real'         => $saldoReal,
            'saldo_insuficiente' => round($saldoSolicitado - $saldoReal, 2),
            'abono_insuficiente' => round($abonoInsuficiente, 2),
            'excedente'          => max(0.0, $restante),
        ];
    }

    /**
     * @param array{lines: list<array{line: \App\Modules\AdminCont\Models\AdminContPaymentInvoice, invoice: ?AdminContInvoice, solicitado: float, aplicable: float, abono_real: float}>, saldo_real: float, saldo_insuficiente: float, abono_insuficiente: float, excedente: float} $plan
     * @return array{excedente_a_saldo_favor: float, saldo_insuficiente: float, facturas: list<array{numero: string, solicitado: float, aplicado: float}>}|null
     */
    private function presentAdjustments(array $plan): ?array
    {
        $faltante = round($plan['saldo_insuficiente'] + $plan['abono_insuficiente'], 2);
        $facturas = [];
        foreach ($plan['lines'] as $item) {
            if (abs($item['aplicable'] - $item['solicitado']) > 0.005) {
                $facturas[] = [
                    'numero'     => (string) ($item['invoice']->numero ?? '—'),
                    'solicitado' => $item['solicitado'],
                    'aplicado'   => $item['aplicable'],
                ];
            }
        }

        if ($plan['excedente'] <= 0.005 && $faltante <= 0.005 && $facturas === []) {
            return null;
        }

        return [
            'excedente_a_saldo_favor' => round($plan['excedente'], 2),
            'saldo_insuficiente'      => $faltante,
            'facturas'                => $facturas,
        ];
    }

    /**
     * Concreta, en el momento en que un cobro de verdad se confirma (inmediato en `register()`, o
     * diferido en `confirm()`), los 2 efectos de saldo a favor de SCRUM-902 §4 que hasta acá solo
     * estaban decididos/guardados: consumir cada abono obligatorio por su monto ya fijado en la
     * línea (`AbonoService::registrarUsoObligatorio()`, que además resuelve el excedente §4.5), y
     * consumir FIFO el saldo Cliente master aplicado (`AbonoService::aplicarSaldoCliente()`).
     *
     * @param array<int, array{abono_id: ?int, monto_abono: float}> $lines keyed por invoice_id
     */
    private function concretarAbonosYSaldo(AdminContPayment $payment, array $lines): float
    {
        $notasConsumo = 0.0;
        // SCRUM-1168 F0 — si el abono o el saldo ya no alcanzan (los consumió otro cobro), el servicio de abonos lo
        // reporta con `AbonoStateException`; acá se traduce a `PaymentStateException` (422 en el controller) y la
        // transacción completa del cobro se revierte, en vez de dejar el cobro aplicado con saldo que no existe.
        try {
            foreach ($lines as $line) {
                if ($line['abono_id'] !== null && $line['monto_abono'] > 0) {
                    $this->abonos->registrarUsoObligatorio($line['abono_id'], $line['monto_abono']);
                }
            }

            if ($payment->saldo_favor_aplicado > 0) {
                $consumo      = $this->abonos->aplicarSaldoCliente($payment->master_client_id, $payment->saldo_favor_aplicado);
                $notasConsumo = $consumo['notas'];
            }
        } catch (\App\Modules\AdminCont\Exceptions\AbonoStateException $e) {
            throw new PaymentStateException($e->getMessage(), 0, $e);
        }

        return $notasConsumo;
    }

    /**
     * SCRUM-1168 F1 — asienta en el libro (modo sombra) el cobro ya concretado: el efectivo que entró y lo aplicado a cada factura. Lo que
     * consumió de saldo a favor de NOTAS DE CRÉDITO (que siguen fuera del libro) se compensa con una reclasificación, para que el disponible
     * del libro no reste dinero que nunca sumó. Una falla aquí no puede tumbar el cobro: se registra en el log (ver `PaymentLedger::shadow`).
     *
     * @param list<array{invoice: AdminContInvoice, monto: float}> $aplicaciones
     */
    private function registrarEnLibro(AdminContPayment $payment, array $aplicaciones, float $notasConsumo, int $actorId): void
    {
        // Cobro del concepto nuevo: el libro es la fuente de verdad, se escribe estricto; en el flujo anterior es solo sombra.
        $escribir = $payment->cobros_v2 ? fn (callable $w) => $w() : fn (callable $w) => $this->ledger->shadow($w);

        $escribir(function () use ($payment, $aplicaciones, $notasConsumo, $actorId): void {
            $this->ledger->ingreso($payment, (float) $payment->monto_recibido, \App\Modules\AdminCont\Models\AdminContPaymentMovement::ORIGEN_DIRECTA, $actorId);
            foreach ($aplicaciones as $a) {
                $this->ledger->aplicacion($payment, $a['invoice'], $a['monto'], \App\Modules\AdminCont\Models\AdminContPaymentMovement::ORIGEN_DIRECTA, $actorId);
            }
            if ($notasConsumo > 0.004) {
                $this->ledger->reclasificacion(
                    $payment->master_client_id, $notasConsumo,
                    'Saldo de notas de crédito consumido por el cobro (las notas siguen fuera del libro)',
                    $payment->id, null, $notasConsumo,
                );
            }
        });
    }
}
