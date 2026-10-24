<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\AdminCont\Support\ReportPeriodResolver;
use App\Modules\VentasDiseno\Models\Architect;
use Carbon\CarbonImmutable;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-520→523) — ver
 * docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md para el diseño completo y las reconciliaciones
 * spec-vs-código. `bankBalance()` y `header()` no dependen de ninguna ventana de tiempo (RN1
 * REQ-520); `projected()`/`real()` sí.
 */
final class CashPositionService
{
    public function __construct(
        private readonly CommissionOrderService $commissionOrders,
        private readonly ArchitectCommissionService $architectCommissions,
    ) {}

    /**
     * RN1 REQ-520 — saldo real de bancos activos + caja menuda, nunca depende de la ventana elegida.
     * @return array{saldo_disponible_hoy: float, saldo_bancos: float, saldo_caja_menuda: float}
     */
    public function header(): array
    {
        $bancos     = $this->bankBalance();
        $cajaMenuda = (float) AdminContFiscalSettings::current()->petty_cash_balance;

        return [
            'saldo_disponible_hoy' => round($bancos + $cajaMenuda, 2),
            'saldo_bancos'         => round($bancos, 2),
            'saldo_caja_menuda'    => round($cajaMenuda, 2),
        ];
    }

    /**
     * Reconciliación punto 2 del ADR — sin columna `saldo` persistida, se deriva en vivo del
     * ledger `bank_movements` (entrada=cobro, salida=devolucion|comision) de cuentas activas.
     */
    public function bankBalance(): float
    {
        $activeAccountIds = AdminContBankAccount::where('activa', true)->pluck('id');

        $entradas = (float) AdminContBankMovement::whereIn('bank_account_id', $activeAccountIds)
            ->where('tipo', AdminContBankMovement::TIPO_COBRO)->sum('monto');
        // SCRUM-879 — las comisiones sin cuenta asignada igual salieron de la empresa.
        $salidas = (float) AdminContBankMovement::realCashImpact($activeAccountIds)
            ->whereIn('tipo', [AdminContBankMovement::TIPO_DEVOLUCION, AdminContBankMovement::TIPO_COMISION])
            ->sum('monto');

        return round($entradas - $salidas, 2);
    }

    /**
     * REQ-521 — entradas desde Facturación (cartera cobrable con saldo pendiente, misma fórmula de
     * vencimiento que `InvoiceService::agingBuckets()`) + salidas desde Comisiones (agregado
     * interno vía `CommissionOrderService::pendingAutoPayBanner()`, individual por proyecto
     * externo vía `ArchitectCommissionService::allProjects()`) + Notas Crédito pendientes de
     * aprobación con `resultado=devuelto`. Ver ADR reconciliación puntos 6/7 para el porqué de cada
     * fórmula — comisiones (ambos tipos) siempre `dias: null`, se incluyen sin importar la ventana
     * (mismo criterio que el mockup real).
     *
     * @return array{window_days: int, entradas: list<array<string, mixed>>, salidas: list<array<string, mixed>>, total_entradas: float, total_salidas: float, neto: float}
     */
    public function projected(int $windowDays): array
    {
        $today = CarbonImmutable::now();

        $entradas = $this->projectedEntradas($today, $windowDays);
        $salidas  = $this->projectedSalidas($today, $windowDays);

        $totalEntradas = round(array_sum(array_column($entradas, 'monto')), 2);
        $totalSalidas  = round(array_sum(array_column($salidas, 'monto')), 2);

        return [
            'window_days'    => $windowDays,
            'entradas'       => $entradas,
            'salidas'        => $salidas,
            'total_entradas' => $totalEntradas,
            'total_salidas'  => $totalSalidas,
            'neto'           => round($totalEntradas - $totalSalidas, 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function projectedEntradas(CarbonImmutable $today, int $windowDays): array
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        $rows = [];
        foreach (AdminContInvoice::where('es_incobrable', false)->whereNull('anulada_at')->get() as $invoice) {
            $saldo = round($invoice->monto - $invoice->saldo_aplicado, 2);
            if ($saldo <= 0) {
                continue;
            }

            $vencimiento = CarbonImmutable::parse($invoice->created_at)->addDays($diasCredito);
            $dias        = $this->diasHasta($vencimiento, $today);
            if (! ($dias < 0 || $dias <= $windowDays)) {
                continue;
            }

            $rows[] = [
                'nombre'      => $invoice->cliente,
                'referencia'  => $invoice->numero,
                'monto'       => $saldo,
                'dias'        => $dias,
                'vencimiento' => $dias < 0 ? 'atrasado' : 'proximo',
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function projectedSalidas(CarbonImmutable $today, int $windowDays): array
    {
        $rows = [];

        $internalBanner = $this->commissionOrders->pendingAutoPayBanner();
        if ($internalBanner['total'] > 0) {
            $rows[] = [
                'nombre'      => 'Comisiones internas pendientes',
                'referencia'  => 'Ver Comisiones Internas',
                'monto'       => $internalBanner['total'],
                'dias'        => null,
                'vencimiento' => null,
                'tipo'        => 'comision_interna',
            ];
        }

        $rows = [...$rows, ...$this->projectedComisionesExternas()];
        $rows = [...$rows, ...$this->projectedNotasCredito($today, $windowDays)];

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function projectedComisionesExternas(): array
    {
        $projects = $this->architectCommissions->allProjects()
            ->filter(fn (array $p) => $p['estado'] === ArchitectCommissionService::ESTADO_PENDIENTE_FACTURA)
            ->values();
        if ($projects->isEmpty()) {
            return [];
        }

        $architectIds = $projects->pluck('architect_id')->unique()->values();
        $profiles     = ArchitectFiscalProfile::whereIn('architect_id', $architectIds)->get()->keyBy('architect_id');
        $architects   = Architect::whereIn('id', $architectIds)->get()->keyBy('id');

        $rows = [];
        foreach ($projects as $p) {
            $profile = $profiles->get($p['architect_id']);
            if ($profile === null) {
                continue; // sin datos fiscales completos, no calculable — mismo criterio que buildCards()
            }

            $montos = $this->architectCommissions->montoFor($p['monto_proyecto'], $profile->regimen_fiscal, $p['porcentaje_aprobado']);
            if ($montos['total'] === null) {
                continue;
            }

            $architect = $architects->get($p['architect_id']);
            if ($architect === null) {
                continue; // arquitecto borrado entre el join y este punto — mismo criterio defensivo que summary()
            }

            $rows[] = [
                'nombre'      => 'Comisión externa — Arq. '.$architect->name,
                'referencia'  => $p['cliente'] ?? $p['numero_pedido'] ?? '',
                'monto'       => $montos['total'],
                'dias'        => null,
                'vencimiento' => null,
                'tipo'        => 'comision_externa',
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function projectedNotasCredito(CarbonImmutable $today, int $windowDays): array
    {
        $notes = AdminContCreditNote::where('resultado', AdminContCreditNote::RESULTADO_DEVUELTO)
            ->where('estado', AdminContCreditNote::ESTADO_PENDIENTE_APROBACION)
            ->with('facturaOrigen')
            ->get();

        $rows = [];
        foreach ($notes as $note) {
            $factura = $note->facturaOrigen;
            if ($factura === null) {
                continue;
            }

            // Mismo cálculo que CreditNoteService::applyToBalance() — solo proyectado, nunca
            // persistido (ADR reconciliación punto 6).
            $saldoPendiente = round($factura->monto - $factura->saldo_aplicado, 2);
            $excedente      = max(0.0, round($note->monto - $saldoPendiente, 2));
            if ($excedente <= 0) {
                continue;
            }

            $dias = $this->diasHasta(CarbonImmutable::parse($note->fecha), $today);
            if (! ($dias < 0 || $dias <= $windowDays)) {
                continue;
            }

            $rows[] = [
                'nombre'      => 'Devolución de dinero — '.$note->cliente,
                'referencia'  => $note->numero,
                'monto'       => $excedente,
                'dias'        => $dias,
                'vencimiento' => $dias < 0 ? 'atrasado' : 'proximo',
                'tipo'        => 'nota_credito',
            ];
        }

        return $rows;
    }

    /**
     * Batch 22 (SCRUM-647, REQ-570) — tarjeta "Arqueo de Caja" de Reportes. Flujo NETO REAL por
     * mes (entradas − salidas de `bank_movements`, cuentas activas, mismo criterio que
     * `bankBalance()`) — deliberadamente distinto de `projected()`, que es hacia adelante sobre
     * facturas/comisiones pendientes, no histórico de movimientos ya ocurridos. RN1: 1 sola serie
     * (neto), nunca entradas/salidas por separado. RN2 (saldo hoy/proyectado 30d) vive en el
     * controller, no acá — esos 2 números nunca dependen de este período.
     *
     * @return array{periodo: string, tipo: 'hoy', hoy: array{entradas: float, salidas: float, neto: float}}|array{periodo: string, tipo: 'meses', meses: list<array{mes: string, anio: int, entradas: float, salidas: float, neto: float}>}
     */
    public function netFlowSeries(string $periodo): array
    {
        $activeAccountIds = AdminContBankAccount::where('activa', true)->pluck('id');

        if ($periodo === 'hoy') {
            $today = CarbonImmutable::now();
            $hoy   = $this->netFlowForWindow($activeAccountIds, $today->startOfDay(), $today->endOfDay());

            return ['periodo' => 'hoy', 'tipo' => 'hoy', 'hoy' => $hoy];
        }

        $meses = ReportPeriodResolver::meses($periodo);

        return [
            'periodo' => $periodo,
            'tipo'    => 'meses',
            'meses'   => array_map(function (array $m) use ($activeAccountIds): array {
                $flujo = $this->netFlowForWindow($activeAccountIds, $m['inicio'], $m['fin']);

                return ['mes' => $m['mes'], 'anio' => $m['anio']] + $flujo;
            }, $meses),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, int> $activeAccountIds
     *  @return array{entradas: float, salidas: float, neto: float} */
    private function netFlowForWindow($activeAccountIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $entradas = (float) AdminContBankMovement::whereIn('bank_account_id', $activeAccountIds)
            ->where('tipo', AdminContBankMovement::TIPO_COBRO)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->sum('monto');
        $salidas = (float) AdminContBankMovement::realCashImpact($activeAccountIds)
            ->whereIn('tipo', [AdminContBankMovement::TIPO_DEVOLUCION, AdminContBankMovement::TIPO_COMISION])
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->sum('monto');

        return ['entradas' => round($entradas, 2), 'salidas' => round($salidas, 2), 'neto' => round($entradas - $salidas, 2)];
    }

    /** Negativo = atrasado, positivo = próximo a vencer (convención del mockup real, ADR §7). */
    private function diasHasta(CarbonImmutable $fecha, CarbonImmutable $today): int
    {
        return -1 * (int) $fecha->diffInDays($today, false);
    }

    /**
     * REQ-523 — filas cronológicas de `bank_movements` (cuentas activas) dentro de la ventana,
     * con saldo acumulado. El saldo de partida (antes de la primera fila) es el saldo actual menos
     * el neto de la ventana, para que la última fila cierre exactamente en `bankBalance()` — mismo
     * criterio que el mockup real (`SALDO_HOY - netoVentana`, acumulando hacia adelante).
     *
     * @return array{window_days: int, saldo_actual: float, movimientos: list<array<string, mixed>>}
     */
    public function real(int $windowDays): array
    {
        $since            = now()->subDays($windowDays)->startOfDay();
        $activeAccountIds = AdminContBankAccount::where('activa', true)->pluck('id');

        $movements = AdminContBankMovement::realCashImpact($activeAccountIds)
            ->where('fecha', '>=', $since->toDateString())
            ->orderBy('fecha')->orderBy('id')
            ->get();

        $saldoActual = $this->bankBalance();
        $netoVentana = round($movements->sum(fn (AdminContBankMovement $m) => $m->tipo === AdminContBankMovement::TIPO_COBRO ? $m->monto : -$m->monto), 2);
        $running     = round($saldoActual - $netoVentana, 2);

        $rows = [];
        foreach ($movements as $m) {
            $entrada = $m->tipo === AdminContBankMovement::TIPO_COBRO ? $m->monto : 0.0;
            $salida  = $m->tipo === AdminContBankMovement::TIPO_COBRO ? 0.0 : $m->monto;
            $running = round($running + $entrada - $salida, 2);

            $rows[] = [
                'fecha'           => $m->fecha->toDateString(),
                'concepto'        => $m->concepto,
                'origen'          => $m->tipo,
                'entrada'         => $entrada,
                'salida'          => $salida,
                'saldo_acumulado' => $running,
            ];
        }

        return [
            'window_days'  => $windowDays,
            'saldo_actual' => $saldoActual,
            'movimientos'  => $rows,
        ];
    }
}
