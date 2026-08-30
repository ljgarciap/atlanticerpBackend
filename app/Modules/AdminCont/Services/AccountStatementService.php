<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Exceptions\AccountStatementException;
use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;

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
     * @return array<string, mixed>
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
     * REQ-460 — movimientos cronológicos con saldo corrido (RN1/RN2). Débito = facturas no
     * anuladas (RN1 REQ-457 no distingue por cobrabilidad, así que una incobrable sigue siendo un
     * débito real en el historial). Crédito = líneas de cobro CONFIRMADO aplicadas a esas facturas
     * (`payment_invoices.monto_aplicado` — ya incluye tanto la parte en efectivo/transferencia como
     * la cubierta con saldo a favor, ver `PaymentService::register()`; un cobro
     * `esperando_confirmacion` no cuenta todavía, RN5 de REQ-469). Notas de crédito (tercera fuente
     * de crédito, REQ-460 DEPENDENCIAS) no existen como módulo todavía — cuando exista, se agregan
     * acá con el mismo shape de fila, sin tocar el resto del pipeline.
     *
     * "Guía de Entrega" — el sistema no tiene un folio propio para la guía (solo existencia+fecha,
     * ver `InvoiceService::detail()`), así que la columna es un booleano, no un número, a
     * diferencia del mockup estático.
     *
     * @return list<array{fecha: string, tipo: string, numero_factura: string, cotizacion_folio: ?string, guia_entrega: bool, sales_project_id: ?int, proyecto: ?string, debito: float, credito: float, saldo: float}>
     */
    private function movements(int $masterClientId, ?int $salesProjectId, ?string $desde, ?string $hasta): array
    {
        $invoicesQuery = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->whereNull('anulada_at');
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

        $rows = [];
        foreach ($invoices as $invoice) {
            /** @var Order|null $order */
            $order = $orders->get($invoice->order_id);
            $quote = $order?->linkedQuote();
            $tieneGuia = $order !== null
                && $order->documents()->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)->exists();

            $rows[] = [
                'fecha'             => $invoice->created_at->toDateString(),
                'tipo'              => 'factura',
                'numero_factura'    => $invoice->numero,
                'cotizacion_folio'  => $quote?->folio,
                'guia_entrega'      => $tieneGuia,
                'sales_project_id'  => $invoice->sales_project_id,
                'proyecto'          => $invoice->sales_project_id !== null ? $projectNames->get($invoice->sales_project_id) : null,
                'debito'            => round($invoice->monto, 2),
                'credito'           => 0.0,
            ];
        }

        $invoicesById = $invoices->keyBy('id');
        $paymentLines = AdminContPaymentInvoice::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->with('payment')
            ->get()
            ->filter(fn (AdminContPaymentInvoice $line) => $line->payment?->estado === AdminContPayment::ESTADO_CONFIRMADO);

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
                'fecha'             => $payment->created_at->toDateString(),
                'tipo'              => 'cobro',
                'numero_factura'    => $invoice->numero,
                'cotizacion_folio'  => $quote?->folio,
                'guia_entrega'      => false,
                'sales_project_id'  => $invoice->sales_project_id,
                'proyecto'          => $invoice->sales_project_id !== null ? $projectNames->get($invoice->sales_project_id) : null,
                'debito'            => 0.0,
                'credito'           => round($line->monto_aplicado, 2),
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
     * REQ-459 RN2 — primera condición que aplique, en orden de prioridad. Evaluada sobre el saldo
     * y las facturas YA filtradas por lo que se está mostrando (mismo criterio simple que
     * `movements()` — un solo cálculo, sin una segunda pasada "histórica completa" separada).
     * RN2(c) (nota de crédito aplicada) nunca aplica todavía — ese módulo no existe.
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
            // RN2(c) — nunca true todavía (Notas de Crédito no existe como módulo).
            return 'Cuenta completada y pagada en su totalidad.';
        }

        return null;
    }
}
