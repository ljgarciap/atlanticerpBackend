<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContItbmsRate;
use Illuminate\Database\Eloquent\Collection;

/**
 * Batch 10 (SCRUM-553→558, REQ-480) — fuente única de verdad de tasas de ITBMS para el resto del
 * proyecto (Facturación, Notas Crédito, Comisiones Externas y CxP de Compras — ver Modelo de Datos
 * del cliente, entidad TASA_ITBMS). Antes de este batch, `Quote::ITBMS_RATE` hardcodeaba 7% sin
 * conectarse nunca a `admin_contab.itbms_rates` (Configuración Fiscal, SCRUM-635/636) — cerrado acá,
 * ver uso en `Quote::itbms()` y `InvoiceService::confirm()`.
 *
 * Nota: `GoodsReceiptController::ITBMS_RATE_LOCAL` (Compras) tiene el mismo hardcode al 7% y NO se
 * toca en este batch — está fuera del alcance de Notas Crédito y Devoluciones, queda como deuda
 * técnica aparte a resolver cuando se retome el backlog de Compras.
 */
class ItbmsRateResolver
{
    /**
     * Tasa por defecto para cotizaciones/facturas nuevas. El catálogo no tiene todavía un flag
     * explícito de "default" (`AdminContItbmsRate` solo distingue `es_base`/`activa`) — entre las 3
     * tasas base sembradas (7% / 3.5% / 0%), la de mayor porcentaje es la general histórica que
     * `Quote::ITBMS_RATE` ya usaba. Decisión técnica dentro del alcance de este batch; si el negocio
     * necesita elegir la tasa por defecto explícitamente, es un ticket propio de Configuración
     * Fiscal, no de Notas Crédito.
     */
    public static function defaultRate(): AdminContItbmsRate
    {
        /** @var AdminContItbmsRate $rate */
        $rate = AdminContItbmsRate::query()
            ->where('es_base', true)
            ->where('activa', true)
            ->orderByDesc('porcentaje')
            ->firstOrFail();

        return $rate;
    }

    /**
     * SCRUM-1166 — tasa de una operación exenta: la base activa con porcentaje 0 («0% — Exento», sembrada junto con las otras dos tasas base).
     * Falla con un mensaje claro si Configuración Fiscal la desactivó, en vez de facturar con una tasa equivocada.
     */
    public static function exemptRate(): AdminContItbmsRate
    {
        $rate = AdminContItbmsRate::query()
            ->where('es_base', true)
            ->where('activa', true)
            ->where('porcentaje', 0)
            ->first();

        if ($rate === null) {
            throw new \DomainException('Falta la tasa base «0% — Exento» activa en Configuración Fiscal: no se puede cotizar ni facturar a un cliente exento de ITBMS.');
        }

        return $rate;
    }

    public static function defaultPercentage(): float
    {
        return self::defaultRate()->porcentaje;
    }

    /**
     * REQ-480 RN1: el selector de "tratamiento correcto" siempre excluye la tasa que la factura ya
     * tiene aplicada — nunca ofrece "corregir" seleccionando por error lo mismo que ya está mal.
     *
     * @return Collection<int, AdminContItbmsRate>
     */
    public static function alternativesTo(?int $currentRateId): Collection
    {
        return AdminContItbmsRate::query()
            ->where('activa', true)
            ->when($currentRateId !== null, fn ($query) => $query->where('id', '!=', $currentRateId))
            ->orderByDesc('porcentaje')
            ->get();
    }
}
