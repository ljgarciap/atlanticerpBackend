<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\VentasDiseno\Models\Architect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435), Grupo 3 (SCRUM-510,
 * REQ-433) — panel "Pendientes". 3 reglas de alerta, cada una reusando el cómputo ya existente de
 * otro módulo/servicio, nunca una fórmula paralela:
 * 1. Factura vencida sin ningún pago aplicado (severidad alta).
 * 2. Factura vencida con pago parcial sin completar (severidad media).
 * 3. Comisión (interna o externa) pendiente de pago hace más de 10 días (severidad media).
 *
 * @phpstan-type PendingAlertItem array{tipo: string, severidad: string, titulo: string, detalle: string, monto: float, fecha_referencia: string}
 */
final class PendingAlertsService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly CommissionOrderService $internalCommissions,
        private readonly ArchitectCommissionService $externalCommissions,
        private readonly AdminContSettingsService $settings,
    ) {}

    /** @return array{count: int, items: list<PendingAlertItem>} */
    public function alerts(): array
    {
        $items = [
            ...$this->facturasVencidasSinPago(),
            ...$this->pagosParcialesIncompletos(),
            ...$this->comisionesPendientes(),
        ];

        return ['count' => count($items), 'items' => $items];
    }

    /** @return list<PendingAlertItem> */
    private function facturasVencidasSinPago(): array
    {
        $items = [];

        foreach ($this->invoices->cobrablesConVencimientoSigned() as $row) {
            // RN2 (SCRUM-510): "más de 0 días desde su fecha de vencimiento" — estrictamente > 0,
            // una factura que vence HOY (dias_vencido === 0) todavía no genera esta alerta. Distinto
            // del umbral de REQ-428 ("Cuentas al día"), que sí trata "vence hoy" como mora — cada
            // ticket tiene su propia RN, no asumir que comparten el mismo borde.
            if ($row['dias_vencido'] <= 0) {
                continue;
            }
            // Sin pago aplicado — saldo es igual al monto original de la factura.
            if (abs($row['saldo'] - $row['invoice']->monto) > 0.001) {
                continue;
            }

            $items[] = [
                'tipo'             => 'factura_vencida_sin_pago',
                'severidad'        => 'alta',
                'titulo'           => "Factura {$row['invoice']->numero} vencida sin pago",
                'detalle'          => "Cliente {$row['invoice']->cliente} — {$row['dias_vencido']} días vencida — ".$this->money($row['saldo']),
                'monto'            => round($row['saldo'], 2),
                'fecha_referencia' => $row['fecha_vencimiento']->toDateString(),
            ];
        }

        return $items;
    }

    /** @return list<PendingAlertItem> */
    private function pagosParcialesIncompletos(): array
    {
        $items = [];

        foreach ($this->invoices->cobrablesConVencimientoSigned() as $row) {
            // RN3 (SCRUM-510): mismo disparador de fecha de vencimiento que RN2 — "> 0", no ">= 0".
            if ($row['dias_vencido'] <= 0) {
                continue;
            }
            // Pago parcial: algo se aplicó (saldo < monto) pero no se completó (saldo > 0).
            if ($row['saldo'] <= 0.001 || $row['saldo'] >= $row['invoice']->monto - 0.001) {
                continue;
            }

            $items[] = [
                'tipo'             => 'pago_parcial_incompleto',
                'severidad'        => 'media',
                'titulo'           => "Factura {$row['invoice']->numero} con pago parcial sin completar",
                'detalle'          => "Cliente {$row['invoice']->cliente} — {$row['dias_vencido']} días vencida — resta ".$this->money($row['saldo']),
                'monto'            => round($row['saldo'], 2),
                'fecha_referencia' => $row['fecha_vencimiento']->toDateString(),
            ];
        }

        return $items;
    }

    /** @return list<PendingAlertItem> */
    private function comisionesPendientes(): array
    {
        return [...$this->comisionesInternasPendientes(), ...$this->comisionesExternasPendientes()];
    }

    /**
     * Internas — `mes_cierre` es la única fecha disponible (granularidad de mes, siempre día 1),
     * usada como aproximación razonable de "desde cuándo quedó pendiente de pago" para este
     * umbral de alerta (no una SLA de precisión diaria).
     *
     * @return list<PendingAlertItem>
     */
    private function comisionesInternasPendientes(): array
    {
        $roleId = Role::where('key', RoleKeys::VENDEDOR)->value('id');
        if ($roleId === null) {
            return [];
        }

        $today = CarbonImmutable::now();
        $items = [];

        /** @var Collection<int, User> $vendedores */
        $vendedores = User::where('role_id', $roleId)->get();

        foreach ($vendedores as $vendedor) {
            $orders = $this->internalCommissions->ordersForVendor($vendedor->id)
                ->filter(fn (array $o) => $o['estado'] === CommissionOrderService::ESTADO_POR_PAGAR && $o['mes_cierre'] !== null);

            if ($orders->isEmpty()) {
                continue;
            }

            /** @var CarbonImmutable $mesCierreMasAntiguo */
            $mesCierreMasAntiguo = $orders->min('mes_cierre');
            // Carbon 3 devuelve float en diffInDays() — sin el cast, la UI mostraba
            // "27.607690584572 dias" (hallazgo QA SCRUM-510, 2026-08-28), mismo patrón ya
            // resuelto en ComprasHomeService::pendingOrders().
            $diasPendiente       = (int) $mesCierreMasAntiguo->diffInDays($today);
            if ($diasPendiente <= $this->settings->comisionPendienteDias()) {
                continue;
            }

            $summary = $this->internalCommissions->summaryForVendor($vendedor, $today->startOfMonth());
            $monto   = (float) $summary['por_pagar'];
            if ($monto <= 0) {
                continue;
            }

            $items[] = [
                'tipo'             => 'comision_pendiente',
                'severidad'        => 'media',
                'titulo'           => "Comisión interna de {$summary['vendedor_nombre']} pendiente de pago",
                'detalle'          => "Pendiente hace {$diasPendiente} días — ".$this->money($monto),
                'monto'            => round($monto, 2),
                'fecha_referencia' => $mesCierreMasAntiguo->toDateString(),
            ];
        }

        return $items;
    }

    /**
     * Externas — `fecha_cobro_completo` es la fecha real (granularidad de día) en que el proyecto
     * terminó de cobrarse, momento en que la comisión del arquitecto queda pendiente de factura.
     *
     * @return list<PendingAlertItem>
     */
    private function comisionesExternasPendientes(): array
    {
        $today = CarbonImmutable::now();

        $pendientesPorArquitecto = $this->externalCommissions->allProjects()
            ->filter(fn (array $p) => $p['estado'] === ArchitectCommissionService::ESTADO_PENDIENTE_FACTURA && $p['fecha_cobro_completo'] !== null)
            ->groupBy('architect_id');

        if ($pendientesPorArquitecto->isEmpty()) {
            return [];
        }

        /** @var Collection<int, Architect> $architects */
        $architects = Architect::whereIn('id', $pendientesPorArquitecto->keys())->get()->keyBy('id');

        // La misma tabla de arquitectos que ya calcula `summary()['arquitectos']` — reusada acá
        // para el monto `pendiente` real (respeta régimen fiscal/ITBMS por arquitecto), en vez de
        // recalcular con `montoFor()` una fórmula que ya existe.
        /** @var list<array{architect_id: int, pendiente: float}> $arquitectosRows */
        $arquitectosRows   = $this->externalCommissions->summary()['arquitectos'];
        $arquitectosSummary = collect($arquitectosRows)->keyBy('architect_id');

        $items = [];

        foreach ($pendientesPorArquitecto as $architectId => $proyectos) {
            $architect = $architects->get($architectId);
            if ($architect === null) {
                continue;
            }

            /** @var CarbonImmutable $fechaMasAntigua */
            $fechaMasAntigua = $proyectos->min('fecha_cobro_completo');
            $diasPendiente   = (int) $fechaMasAntigua->diffInDays($today);
            if ($diasPendiente <= $this->settings->comisionPendienteDias()) {
                continue;
            }

            $monto = (float) ($arquitectosSummary->get($architectId)['pendiente'] ?? 0.0);
            if ($monto <= 0) {
                continue;
            }

            $items[] = [
                'tipo'             => 'comision_pendiente',
                'severidad'        => 'media',
                'titulo'           => "Comisión externa de {$architect->name} pendiente de pago",
                'detalle'          => "Pendiente hace {$diasPendiente} días — ".$this->money($monto),
                'monto'            => round($monto, 2),
                'fecha_referencia' => $fechaMasAntigua->toDateString(),
            ];
        }

        return $items;
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
