<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Exceptions\AccountStatementException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Support\AgingBucketCalculator;
use App\Modules\AdminCont\Support\GuiaEntregaResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Support\Collection;

/**
 * Batch 8 de Admin&Cont — Estado de Cuenta (SCRUM-529→533, REQ-452→456): el "encabezado" de
 * resultado (cliente/proyecto/tarifa/régimen/términos, REQ-455). Batch 9 (SCRUM-534→538,
 * REQ-457→461) agrega `detail()` — saldo, bloque de pago, nota automática y movimientos.
 */
final class AccountStatementService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * REQ-453 — mismo buscador de cliente que Cobros (REQ-462), reusado tal cual.
     * @return list<array{id: int, name: string}>
     */
    public function searchClients(string $search): array
    {
        return $this->payments->searchMasterClients($search);
    }

    /**
     * REQ-453 RN1 — proyectos del cliente (a través de sus subclientes, único dueño real de
     * `sales_projects` — ver docblock de `SalesProject::subClient()`).
     *
     * @return list<array{id: int, name: string}>
     */
    public function projectsForClient(int $masterClientId): array
    {
        return array_values($this->projectsQuery($masterClientId)
            ->get(['id', 'name'])
            ->map(fn (SalesProject $p): array => ['id' => $p->id, 'name' => $p->name])
            ->all());
    }

    /**
     * REQ-455 — encabezado del resultado. RN1: "Cliente" solo, o "Cliente · Proyecto" si se
     * filtró por uno. RN2: tarifa/régimen/términos siempre los vigentes (no histórico) — se leen
     * en el momento, nunca se copian/cachean en ninguna otra tabla.
     *
     * @throws AccountStatementException RN2 REQ-453 — cliente inexistente, o proyecto que no
     *     pertenece a ese cliente (defensa en profundidad — el selector de proyecto del frontend
     *     ya solo ofrece los del cliente elegido).
     *
     * @return array{master_client_id: int, cliente: string, sales_project_id: ?int, proyecto: ?string, tarifa: ?string, regimen_fiscal: string, terminos_pago: string}
     */
    public function generate(int $masterClientId, ?int $salesProjectId): array
    {
        $client = MasterClient::find($masterClientId);
        if ($client === null) {
            throw new AccountStatementException('Cliente no encontrado.');
        }

        $project = null;
        if ($salesProjectId !== null) {
            $project = $this->projectsQuery($masterClientId)->find($salesProjectId);

            if ($project === null) {
                throw new AccountStatementException('El proyecto seleccionado no pertenece a este cliente.');
            }
        }

        $profile = AdminContClientBillingProfile::forMasterClient($masterClientId);

        return [
            'master_client_id' => $client->id,
            'cliente'          => $client->name,
            'sales_project_id' => $project?->id,
            'proyecto'         => $project?->name,
            'tarifa'           => $client->default_price_type,
            'regimen_fiscal'   => $profile->regimen_fiscal,
            'terminos_pago'    => $profile->terminos_pago,
        ];
    }

    /**
     * REQ-457/458/459/460 — el resultado completo: encabezado (`generate()`) + saldo + bloque de
     * pago + nota automática + movimientos con saldo corrido.
     *
     * @throws AccountStatementException — mismo que `generate()`.
     * @return array{master_client_id: int, cliente: string, sales_project_id: ?int, proyecto: ?string, tarifa: ?string, regimen_fiscal: string, terminos_pago: string, saldo: float, saldo_a_favor: bool, pago_a: ?string, cuenta_pago: ?string, banco: ?string, tipo_cuenta: ?string, numero_cuenta: ?string, responsable: ?string, nota_contexto: ?string, proyectos_count: ?int, movimientos: list<array{fecha: string, tipo: string, numero_factura: ?string, invoice_id: ?int, order_id: ?int, cotizacion_folio: ?string, quote_id: ?int, guia_entrega_numero: ?string, guia_entrega_order_id: ?int, nota_id: ?int, antiguedad_cartera: ?string, sales_project_id: ?int, proyecto: ?string, debito: float, credito: float, saldo: float, recibo: ?array{tipo: string, id: int, numero_recibo: string}}>}
     */
    public function detail(int $masterClientId, ?int $salesProjectId, ?string $desde, ?string $hasta): array
    {
        $header    = $this->generate($masterClientId, $salesProjectId);
        $movements = $this->movements($masterClientId, $salesProjectId, $desde, $hasta);

        $totalDebito  = round(array_sum(array_column($movements, 'debito')), 2);
        $totalCredito = round(array_sum(array_column($movements, 'credito')), 2);
        $saldo        = round($totalDebito - $totalCredito, 2);

        // RN4 REQ-459 — la nota (contexto + cantidad de proyectos) solo se muestra sin filtro de proyecto.
        $nota            = null;
        $proyectosCount  = null;
        if ($salesProjectId === null) {
            $nota           = $this->contextNote($masterClientId, $saldo);
            $proyectosCount = $this->projectsQuery($masterClientId)->count();
        }

        return $header + [
            'saldo'          => $saldo,
            'saldo_a_favor'  => $saldo < 0,
            'pago_a'         => AdminContFiscalSettings::current()->nombre_comercial,
            ...InvoiceService::paymentBlock(),
            'nota_contexto'  => $nota,
            'proyectos_count' => $proyectosCount,
            'movimientos'    => $movements,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<SalesProject> */
    private function projectsQuery(int $masterClientId)
    {
        return SalesProject::query()
            ->whereHas('subClient', fn ($q) => $q->where('master_client_id', $masterClientId))
            ->orderBy('name');
    }

    /**
     * REQ-460 — movimientos cronológicos con saldo corrido (RN1/RN2).
     *
     * Débito = facturas (incl. incobrables — RN1 REQ-457 no distingue por cobrabilidad). Crédito =
     * líneas de cobro CONFIRMADO (`payment_invoices.monto_aplicado`; un cobro
     * `esperando_confirmacion` no cuenta, RN5 de REQ-469).
     *
     * SCRUM-877 parte 1 — se agregan además:
     *   - Notas de crédito APLICADAS de tipo `descuento_comercial` / `devolucion_mercancia` como
     *     crédito. Monto: el completo de la nota, salvo devolución con excedente devuelto en
     *     efectivo, donde solo cuenta lo que redujo la factura (`monto_aplicado_saldo`, D-6).
     *     Fecha: la de la decisión de Mark (`fecha_decision ?? fecha`, D-3). Notas
     *     `pendiente_aprobacion`/`rechazada` NO aparecen (D-2).
     *   - Facturas ANULADAS (antes se excluían): se muestran con su fila de Factura (débito) + una
     *     fila de Anulación (crédito por el mismo monto) → efecto neto cero (D-5).
     *
     * "Guía de Entrega" — con SCRUM-876 se busca sobre toda la familia de entregas del pedido,
     * salvo en una factura `parcial` (SCRUM-929), que solo mira la guía de SU entrega. SCRUM-930:
     * el sistema no tiene folio de negocio propio para la guía — `guia_entrega_numero` es
     * `"{order_number}-G"` del pedido que efectivamente tiene el documento (no necesariamente el
     * mismo `order_id` de la factura, ver `GuiaEntregaResolver::resolve()`), y `guia_entrega_order_id` es ese
     * `order_id` real, para poder abrir el modal correcto. Ambos `null` si no hay guía.
     *
     * SCRUM-930 — `invoice_id`/`order_id`/`quote_id`/`nota_id` se agregan para que el frontend pueda
     * enlazar cada documento a su modal de detalle existente (`order_id` es el que necesita
     * `InvoiceDetailModal`, path param de `GET /admin-contab/invoices/{order_id}/detail`);
     * `antiguedad_cartera` (mismos 4 rangos que
     * `InvoiceService::agingBuckets()`, ver `AgingBucketCalculator`) solo se calcula en filas
     * `tipo=factura`, bucketeado desde `created_at` (criterio distinto al de `agingBuckets()`, que
     * bucketea desde vencimiento para su panel global) — `null` en el resto de los tipos de fila.
     *
     * @return list<array{fecha: string, tipo: string, numero_factura: ?string, invoice_id: ?int, order_id: ?int, cotizacion_folio: ?string, quote_id: ?int, guia_entrega_numero: ?string, guia_entrega_order_id: ?int, nota_id: ?int, antiguedad_cartera: ?string, sales_project_id: ?int, proyecto: ?string, debito: float, credito: float, saldo: float, recibo: ?array{tipo: string, id: int, numero_recibo: string}}>
     */
    private function movements(int $masterClientId, ?int $salesProjectId, ?string $desde, ?string $hasta): array
    {
        $invoicesQuery = AdminContInvoice::query()->where('master_client_id', $masterClientId);
        if ($salesProjectId !== null) {
            $invoicesQuery->where('sales_project_id', $salesProjectId);
        }
        $invoices = $invoicesQuery->get();

        $projectNames = SalesProject::query()
            ->whereIn('id', $invoices->pluck('sales_project_id')->filter()->unique())
            ->pluck('name', 'id');

        $orders = Order::query()
            ->whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $proyectoDe = fn (?int $spId): ?string => $spId !== null ? $projectNames->get($spId) : null;

        // Notas de crédito aplicadas sobre facturas de este cliente/proyecto (D-1/D-2/D-3/D-4/D-6).
        $notas = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->whereIn('factura_origen_id', $invoices->pluck('id'))
            ->get();
        $notasPorFactura = $notas->groupBy('factura_origen_id');

        $rows = [];
        foreach ($invoices as $invoice) {
            /** @var Order|null $order */
            $order = $orders->get($invoice->order_id);
            $quote = $order?->linkedQuote();
            // SCRUM-929 (B3a) — la guía de una factura `parcial` es la de SU entrega; la de un `completo`
            // (que cubre el resto del pedido), la de cualquier entrega de la familia (SCRUM-876).
            [$guiaEntregaNumero, $guiaEntregaOrderId] = GuiaEntregaResolver::resolve($invoice, $order, $orders);

            $rows[] = [
                'fecha'                 => $invoice->created_at->toDateString(),
                'tipo'                  => 'factura',
                'numero_factura'        => $invoice->numero,
                'invoice_id'            => $invoice->id,
                'order_id'              => $order?->id,
                'cotizacion_folio'      => $quote?->folio,
                'quote_id'              => $quote?->id,
                'guia_entrega_numero'   => $guiaEntregaNumero,
                'guia_entrega_order_id' => $guiaEntregaOrderId,
                'nota_id'               => null,
                'antiguedad_cartera'    => AgingBucketCalculator::bucketFor($invoice->created_at),
                'sales_project_id'      => $invoice->sales_project_id,
                'proyecto'              => $proyectoDe($invoice->sales_project_id),
                'debito'                => round($invoice->monto, 2),
                'credito'               => 0.0,
                // SCRUM-955 punto 5 — referencia al recibo de cobro/abono asociado (columna
                // "Cobro" del Estado de Cuenta). Una fila de factura en sí no tiene recibo propio.
                'recibo'                => null,
            ];

            // D-5 — factura anulada: fila de reversa (crédito por el mismo monto), fechada por la
            // nota de anulación (o `anulada_at` si no hay nota asociada).
            if ($invoice->anulada_at !== null) {
                /** @var AdminContCreditNote|null $notaAnulacion */
                $notaAnulacion = ($notasPorFactura->get($invoice->id) ?? collect())
                    ->firstWhere('tipo', AdminContCreditNote::TIPO_ANULACION_COMPLETA);
                $fechaReversa = $notaAnulacion !== null
                    ? ($notaAnulacion->fecha_decision ?? $notaAnulacion->fecha)->toDateString()
                    : $invoice->anulada_at->toDateString();

                $rows[] = [
                    'fecha'                 => $fechaReversa,
                    'tipo'                  => 'anulacion',
                    'numero_factura'        => $invoice->numero,
                    'invoice_id'            => $invoice->id,
                    'order_id'              => $order?->id,
                    'cotizacion_folio'      => $quote?->folio,
                    'quote_id'              => $quote?->id,
                    'guia_entrega_numero'   => null,
                    'guia_entrega_order_id' => null,
                    'nota_id'               => $notaAnulacion?->id,
                    'antiguedad_cartera'    => null,
                    'sales_project_id'      => $invoice->sales_project_id,
                    'proyecto'              => $proyectoDe($invoice->sales_project_id),
                    'debito'                => 0.0,
                    'credito'               => round($invoice->monto, 2),
                    'recibo'                => null,
                ];

                // Anulación de una factura ya cobrada con el excedente devuelto en efectivo: el cobro sigue figurando por su monto completo, así que la
                // salida del dinero es un débito (igual que una devolución de saldo), atribuido al proyecto de la factura.
                if ($notaAnulacion !== null && $notaAnulacion->resultado === AdminContCreditNote::RESULTADO_DEVUELTO) {
                    $devuelto = round(max(0.0, (float) $notaAnulacion->monto - (float) ($notaAnulacion->monto_aplicado_saldo ?? 0.0)), 2);
                    if ($devuelto > 0.004) {
                        $rows[] = [
                            'fecha'                 => $fechaReversa,
                            'tipo'                  => 'devolucion_saldo',
                            'numero_factura'        => $invoice->numero,
                            'invoice_id'            => $invoice->id,
                            'order_id'              => $order?->id,
                            'cotizacion_folio'      => $quote?->folio,
                            'quote_id'              => $quote?->id,
                            'guia_entrega_numero'   => null,
                            'guia_entrega_order_id' => null,
                            'nota_id'               => $notaAnulacion->id,
                            'antiguedad_cartera'    => null,
                            'sales_project_id'      => $invoice->sales_project_id,
                            'proyecto'              => $proyectoDe($invoice->sales_project_id),
                            'debito'                => $devuelto,
                            'credito'               => 0.0,
                            'recibo'                => null,
                        ];
                    }
                }
            }

            // D-4/D-6 — descuentos comerciales y devoluciones de mercancía aplicadas.
            foreach (($notasPorFactura->get($invoice->id) ?? collect()) as $nota) {
                if (! in_array($nota->tipo, [AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA], true)) {
                    continue;
                }
                $credito = $nota->resultado === AdminContCreditNote::RESULTADO_DEVUELTO
                    ? round((float) ($nota->monto_aplicado_saldo ?? min($nota->monto, $invoice->monto)), 2)
                    : round($nota->monto, 2);
                if ($credito <= 0) {
                    continue;
                }

                $rows[] = [
                    'fecha'                 => ($nota->fecha_decision ?? $nota->fecha)->toDateString(),
                    'tipo'                  => $nota->tipo === AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL ? 'descuento' : 'devolucion',
                    'numero_factura'        => $invoice->numero,
                    'invoice_id'            => $invoice->id,
                    'order_id'              => $order?->id,
                    'cotizacion_folio'      => $quote?->folio,
                    'quote_id'              => $quote?->id,
                    'guia_entrega_numero'   => null,
                    'guia_entrega_order_id' => null,
                    'nota_id'               => $nota->id,
                    'antiguedad_cartera'    => null,
                    'sales_project_id'      => $invoice->sales_project_id,
                    'proyecto'              => $proyectoDe($invoice->sales_project_id),
                    'debito'                => 0.0,
                    'credito'               => $credito,
                    'recibo'                => null,
                ];
            }
        }

        // Cobros — sobre todas las facturas, anuladas incluidas: anular una factura neutraliza su débito (fila de anulación), no el dinero que el
        // cliente ya pagó (queda aplicado a ella; una corrección de datos crea el reemplazo sin pago).
        $facturasDelAlcance = $invoices;
        $invoicesById       = $facturasDelAlcance->keyBy('id');
        $paymentLines = AdminContPaymentInvoice::query()
            ->whereIn('invoice_id', $facturasDelAlcance->pluck('id'))
            ->with('payment')
            ->get()
            // SCRUM-1168 — los cobros del concepto nuevo no se muestran por línea de aplicación: salen del libro (más abajo), con su monto completo.
            ->filter(fn (AdminContPaymentInvoice $line) => $line->payment?->estado === AdminContPayment::ESTADO_CONFIRMADO && ! $line->payment->cobros_v2);

        foreach ($paymentLines as $line) {
            /** @var AdminContInvoice $invoice */
            $invoice = $invoicesById->get($line->invoice_id);
            $order   = $orders->get($invoice->order_id);
            $quote   = $order?->linkedQuote();
            $payment = $line->payment;
            if ($payment === null) {
                continue;
            }

            $rows[] = [
                // SCRUM-1168 F2 — manda la fecha en que el dinero se aplicó a la factura; lo histórico (sin fecha de aplicación) conserva la del cobro.
                'fecha'                 => ($line->fecha_aplicacion ?? $payment->created_at)->toDateString(),
                'tipo'                  => 'cobro',
                'numero_factura'        => $invoice->numero,
                'invoice_id'            => $invoice->id,
                'order_id'              => $order?->id,
                'cotizacion_folio'      => $quote?->folio,
                'quote_id'              => $quote?->id,
                'guia_entrega_numero'   => null,
                'guia_entrega_order_id' => null,
                'nota_id'               => null,
                'antiguedad_cartera'    => null,
                'sales_project_id'      => $invoice->sales_project_id,
                'proyecto'              => $proyectoDe($invoice->sales_project_id),
                'debito'                => 0.0,
                'credito'               => round($line->monto_aplicado, 2),
                // SCRUM-955 punto 5 — recibo de cobro real, siempre disponible (todo AdminContPayment
                // confirmado tiene numero_recibo, ver Batch 6). Un abono aplicado obligatoriamente a
                // esta factura queda incluido en `monto_aplicado`/`monto_abono_aplicado` del mismo
                // cobro (ver PaymentReceiptPdfService) — no genera una fila propia todavía; si el
                // negocio pide distinguir "recibo de abono" acá como fila independiente, es una
                // decisión de Arquitecto aparte (hoy no hay ningún movimiento de abono sin cobro
                // asociado en este builder).
                'recibo'                => $payment->numero_recibo !== null
                    ? ['tipo' => 'cobro', 'id' => $payment->id, 'numero_recibo' => $payment->numero_recibo]
                    : null,
            ];
        }

        // SCRUM-1168 — el estado muestra facturas, cobros (con su monto completo, sin importar cómo se compensaron) y saldo a favor; una compensación
        // es solo la forma de asignar el dinero a facturas y no es una fila. Los cobros del concepto nuevo y los abonos con ingreso en el libro salen de él.
        ['rows' => $filasDeLibro, 'abonos' => $abonosDelLibro] = $this->ledgerRows($masterClientId, $salesProjectId, $invoices, $orders, $proyectoDe);
        array_push($rows, ...$filasDeLibro);

        // Compensaciones desde un abono que el libro no reconoce como ingreso (p. ej. el excedente de un cobro anterior, creado por el sistema): siguen
        // como fila propia, porque no hay otro lugar donde figuren. Las que salen del saldo de una nota de crédito no son fila: la nota ya acredita su
        // monto completo en su propia fila, y repetirlo lo duplicaría.
        $compensationLines = AdminContCompensationLine::query()
            ->whereIn('invoice_id', $facturasDelAlcance->pluck('id'))
            ->where('source_type', AdminContCompensationLine::SOURCE_ABONO)
            ->whereNotIn('source_id', $abonosDelLibro)
            ->get();
        $compensations = AdminContCompensation::query()
            ->whereIn('id', $compensationLines->pluck('compensation_id')->unique())
            ->where('estado', AdminContCompensation::ESTADO_CONFIRMADO)
            ->get()->keyBy('id');
        foreach ($compensationLines as $cl) {
            $compensation = $compensations->get($cl->compensation_id);
            /** @var AdminContInvoice|null $invoice */
            $invoice = $invoicesById->get($cl->invoice_id);
            if ($compensation === null || $invoice === null) {
                continue;
            }
            $order = $orders->get($invoice->order_id);
            $quote = $order?->linkedQuote();

            $rows[] = [
                'fecha'                 => $compensation->confirmed_at->toDateString(),
                'tipo'                  => 'compensacion',
                'numero_factura'        => $invoice->numero,
                'invoice_id'            => $invoice->id,
                'order_id'              => $order?->id,
                'cotizacion_folio'      => $quote?->folio,
                'quote_id'              => $quote?->id,
                'guia_entrega_numero'   => null,
                'guia_entrega_order_id' => null,
                'nota_id'               => null,
                'antiguedad_cartera'    => null,
                'sales_project_id'      => $invoice->sales_project_id,
                'proyecto'              => $proyectoDe($invoice->sales_project_id),
                'debito'                => 0.0,
                'credito'               => round($cl->monto, 2),
                'recibo'                => ['tipo' => 'compensacion', 'id' => $compensation->id, 'numero_recibo' => $compensation->folio],
            ];
        }

        // RN5 REQ-460 — filtro de fechas sobre la fecha de cada movimiento.
        if ($desde !== null) {
            $rows = array_filter($rows, fn (array $r) => $r['fecha'] >= $desde);
        }
        if ($hasta !== null) {
            $rows = array_filter($rows, fn (array $r) => $r['fecha'] <= $hasta);
        }

        // RN1 — orden cronológico ANTES de calcular el saldo corrido.
        usort($rows, fn (array $a, array $b) => $a['fecha'] <=> $b['fecha']);

        // RN2 — saldo corrido (acumulado), no el de la fila aislada.
        $running = 0.0;
        foreach ($rows as &$row) {
            $running    = round($running + $row['debito'] - $row['credito'], 2);
            $row['saldo'] = $running;
        }
        unset($row);

        return $rows;
    }

    /**
     * SCRUM-1168 — filas de cobro y saldo a favor a partir del libro de movimientos (`payment_movements`), una por fuente de dinero y proyecto.
     *
     * Fuentes: cobros del concepto nuevo (`cobros_v2`, confirmados) y abonos confirmados con ingreso en el libro (`ingreso` o `historico`). Por cada una:
     *   - lo aplicado a facturas de un proyecto sale como crédito de ese proyecto, con la factura si es una sola;
     *   - lo que sigue sin aplicar (su saldo a favor) sale como crédito «sin proyecto», a nivel de cliente;
     *   - lo devuelto en efectivo sale como débito.
     * La suma de los créditos de una fuente es el dinero que AtlanticERP conserva de ella. Los abonos consumidos por cobros anteriores ya figuran en esas
     * líneas, por eso un abono histórico solo cuenta lo que el libro todavía le reconoce.
     *
     * Con filtro de proyecto solo cuenta lo aplicado a ese proyecto: el saldo a favor y las devoluciones no pertenecen a ninguno. Lo aplicado a una
     * factura anulada también cuenta: anularla neutraliza su débito, no el dinero cobrado.
     *
     * @param Collection<int, AdminContInvoice> $invoices        facturas del cliente en el alcance (con o sin filtro de proyecto), anuladas incluidas
     * @param Collection<int|string, Order>     $orders
     * @param \Closure(?int): ?string           $proyectoDe
     * @return array{rows: list<array{fecha: string, tipo: string, numero_factura: ?string, invoice_id: ?int, order_id: ?int, cotizacion_folio: ?string, quote_id: ?int, guia_entrega_numero: ?string, guia_entrega_order_id: ?int, nota_id: ?int, antiguedad_cartera: ?string, sales_project_id: ?int, proyecto: ?string, debito: float, credito: float, recibo: ?array{tipo: string, id: int, numero_recibo: string}}>, abonos: list<int>} `abonos`: los abonos que el libro ya cubre (sus compensaciones no son fila aparte)
     */
    private function ledgerRows(int $masterClientId, ?int $salesProjectId, Collection $invoices, Collection $orders, \Closure $proyectoDe): array
    {
        $movimientos = AdminContPaymentMovement::query()->where('master_client_id', $masterClientId)->orderBy('id')->get();
        if ($movimientos->isEmpty()) {
            return ['rows' => [], 'abonos' => []];
        }

        $esRecepcion = fn (AdminContPaymentMovement $m): bool => in_array($m->tipo, [AdminContPaymentMovement::TIPO_INGRESO, AdminContPaymentMovement::TIPO_HISTORICO], true)
            && $m->delta_disponible > 0;

        // Fuente de cada movimiento: el cobro si lo tiene; si no, el abono. Un movimiento sin ninguno (saldo de nota de crédito) no es de nadie.
        $porFuente = $movimientos->filter(fn (AdminContPaymentMovement $m): bool => $m->payment_id !== null || $m->abono_id !== null)
            ->groupBy(fn (AdminContPaymentMovement $m): string => $m->payment_id !== null ? "p{$m->payment_id}" : "a{$m->abono_id}");

        $payments = AdminContPayment::query()
            ->whereIn('id', $movimientos->pluck('payment_id')->filter()->unique())
            ->where('cobros_v2', true)->where('estado', AdminContPayment::ESTADO_CONFIRMADO)
            ->get()->keyBy('id');
        $abonos = AdminContAbono::query()
            ->whereIn('id', $movimientos->whereNull('payment_id')->pluck('abono_id')->filter()->unique())
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
            ->get()->keyBy('id');

        // Vigentes y anuladas: anular una factura neutraliza su débito, pero el efectivo cobrado sigue siendo del cliente.
        $todas = $invoices->keyBy('id');

        $abonosCubiertos = [];
        $fuentes         = [];

        foreach ($porFuente as $clave => $movs) {
            $esCobro = str_starts_with((string) $clave, 'p');
            $id      = (int) substr((string) $clave, 1);

            $recepcion = $movs->filter($esRecepcion);
            if ($recepcion->isEmpty()) {
                continue; // sin ingreso reconocido en el libro: no hay a qué fecha ni por cuánto mostrarlo
            }
            if ($esCobro) {
                /** @var AdminContPayment|null $fuente */
                $fuente = $payments->get($id);
                $recibo = $fuente?->numero_recibo !== null ? ['tipo' => 'cobro', 'id' => $id, 'numero_recibo' => $fuente->numero_recibo] : null;
            } else {
                /** @var AdminContAbono|null $fuente */
                $fuente = $abonos->get($id);
                $recibo = $fuente?->numero_recibo !== null ? ['tipo' => 'abono', 'id' => $id, 'numero_recibo' => $fuente->numero_recibo] : null;
                if ($fuente !== null) {
                    $abonosCubiertos[] = $id;
                }
            }
            if ($fuente === null) {
                continue;
            }

            // Lo aplicado a cada factura (aplicaciones menos reversos), agrupado por proyecto.
            $aplicadoPorFactura = $movs->whereNotNull('invoice_id')->groupBy('invoice_id')
                ->map(fn (Collection $g): float => round((float) $g->sum('delta_factura'), 2))
                ->filter(fn (float $monto): bool => $monto > 0.004);
            $porProyecto = [];
            foreach ($aplicadoPorFactura as $invoiceId => $monto) {
                $invoice = $todas->get($invoiceId);
                if ($invoice === null) {
                    continue; // factura de otro proyecto (con filtro de proyecto)
                }
                $llave = $invoice->sales_project_id ?? 'ninguno';
                $porProyecto[$llave]['monto']      = round(($porProyecto[$llave]['monto'] ?? 0.0) + $monto, 2);
                $porProyecto[$llave]['facturas'][] = $invoice;
            }

            $devuelto = round((float) $movs->where('tipo', AdminContPaymentMovement::TIPO_DEVOLUCION)->sum('monto'), 2);
            $fuentes[] = [
                'fecha'       => $recepcion->sortBy('fecha_movimiento')->firstOrFail()->fecha_movimiento->toDateString(),
                'recibo'      => $recibo,
                'porProyecto' => $porProyecto,
                'devoluciones' => $movs->where('tipo', AdminContPaymentMovement::TIPO_DEVOLUCION)->values(),
                'sinAplicar'  => round((float) $movs->sum('delta_disponible') + $devuelto, 2),
            ];
        }

        // Un cobro anterior (no v2) que gastó saldo de un abono deja su consumo en el libro sobre el cobro, no sobre el abono: el abono seguiría por su
        // monto completo y el dinero se contaría dos veces. Se descuenta, de lo más antiguo a lo más reciente, de lo que las fuentes tienen sin aplicar.
        $consumo = 0.0;
        foreach ($movimientos->whereNotNull('payment_id')->groupBy('payment_id') as $paymentId => $movsCobro) {
            if (! $payments->has($paymentId)) {
                $consumo += max(0.0, -round((float) $movsCobro->sum('delta_disponible'), 2));
            }
        }
        usort($fuentes, fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);
        foreach ($fuentes as &$f) {
            if ($consumo <= 0.004) {
                break;
            }
            $quita          = min(max($f['sinAplicar'], 0.0), $consumo);
            $f['sinAplicar'] = round($f['sinAplicar'] - $quita, 2);
            $consumo         = round($consumo - $quita, 2);
        }
        unset($f);

        $rows = [];
        foreach ($fuentes as $f) {
            $porProyecto = $f['porProyecto'];
            if ($salesProjectId === null && $f['sinAplicar'] > 0.004) {
                $porProyecto['sin_aplicar']['monto'] = $f['sinAplicar'];
            }

            foreach ($porProyecto as $llave => $grupo) {
                $facturas = collect($grupo['facturas'] ?? [])->unique('id')->values();
                $unica    = $facturas->count() === 1 ? $facturas->first() : null;
                $order    = $unica !== null ? $orders->get($unica->order_id) : null;
                $quote    = $order?->linkedQuote();
                $spId     = is_int($llave) ? $llave : null;

                $rows[] = [
                    'fecha'                 => $f['fecha'],
                    'tipo'                  => 'cobro',
                    'numero_factura'        => $unica?->numero,
                    'invoice_id'            => $unica?->id,
                    'order_id'              => $order?->id,
                    'cotizacion_folio'      => $quote?->folio,
                    'quote_id'              => $quote?->id,
                    'guia_entrega_numero'   => null,
                    'guia_entrega_order_id' => null,
                    'nota_id'               => null,
                    'antiguedad_cartera'    => null,
                    'sales_project_id'      => $spId,
                    'proyecto'              => $spId !== null ? $proyectoDe($spId) : null,
                    'debito'                => 0.0,
                    'credito'               => $grupo['monto'],
                    'recibo'                => $f['recibo'],
                ];
            }

            // Dinero devuelto al cliente en efectivo: una fila por devolución, con su fecha.
            if ($salesProjectId === null) {
                foreach ($f['devoluciones'] as $dev) {
                    $rows[] = [
                        'fecha'                 => $dev->fecha_movimiento->toDateString(),
                        'tipo'                  => 'devolucion_saldo',
                        'numero_factura'        => null,
                        'invoice_id'            => null,
                        'order_id'              => null,
                        'cotizacion_folio'      => null,
                        'quote_id'              => null,
                        'guia_entrega_numero'   => null,
                        'guia_entrega_order_id' => null,
                        'nota_id'               => null,
                        'antiguedad_cartera'    => null,
                        'sales_project_id'      => null,
                        'proyecto'              => null,
                        'debito'                => round((float) $dev->monto, 2),
                        'credito'               => 0.0,
                        'recibo'                => $f['recibo'],
                    ];
                }
            }
        }

        return ['rows' => $rows, 'abonos' => array_values(array_unique($abonosCubiertos))];
    }

    /**
     * REQ-459 RN2 — primera condición que aplique, en orden de prioridad. Evaluada sobre el saldo
     * y las facturas YA filtradas por lo que se está mostrando (mismo criterio simple que
     * `movements()` — un solo cálculo, sin una segunda pasada "histórica completa" separada).
     */
    private function contextNote(int $masterClientId, float $saldo): ?string
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;
        $hoy         = now();

        $masVencida = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->where('es_incobrable', false)
            ->whereNull('anulada_at')
            ->where('saldo_aplicado', 0)
            ->get()
            ->map(fn (AdminContInvoice $i): int => max(0, (int) $i->created_at->copy()->addDays($diasCredito)->diffInDays($hoy, false)))
            ->filter(fn (int $dias): bool => $dias > 90)
            ->max();

        if ($masVencida !== null) {
            return "Factura vencida hace {$masVencida} días, sin ningún abono aplicado todavía.";
        }

        if ($saldo < 0) {
            $monto = number_format(abs($saldo), 2);

            return "El cliente tiene saldo a favor de \${$monto} pendiente de aplicar a la próxima factura o devolver.";
        }

        if ($saldo === 0.0) {
            // SCRUM-877 p1 — ahora sí puede darse legítimamente: una nota de crédito llevó el
            // saldo a 0 (RN2c de REQ-459), no solo un cobro completo.
            return 'Cuenta completada y pagada en su totalidad.';
        }

        return null;
    }
}
