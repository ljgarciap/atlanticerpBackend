<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use Carbon\CarbonInterface;

/**
 * SCRUM-930 — extraído de `InvoiceService::agingBuckets()` (mismos 4 rangos, mismo orden) para que
 * `AccountStatementService::movements()` pueda calcular el bucket de una factura individual sin
 * duplicar los límites 0-30/31-60/61-90/+91. Pura extracción: `agingBuckets()` sigue clampeando
 * días negativos a 0 ANTES de llamar a `bucketForDays()` (su comportamiento, incluido ese `max(0,
 * ...)`, no cambia acá) — `bucketFor()`/`bucketForDays()` no clampean nada por su cuenta, así que
 * un llamador nuevo (como `movements()`, que solo bucketea `created_at` ya pasado) no hereda ese
 * comportamiento sin pedirlo explícitamente.
 */
final class AgingBucketCalculator
{
    public const BUCKETS = ['0-30', '31-60', '61-90', '91+'];

    public static function bucketForDays(int $dias): string
    {
        return match (true) {
            $dias <= 30 => '0-30',
            $dias <= 60 => '31-60',
            $dias <= 90 => '61-90',
            default => '91+',
        };
    }

    public static function bucketFor(CarbonInterface $referenceDate, ?CarbonInterface $today = null): string
    {
        $dias = (int) $referenceDate->diffInDays($today ?? now(), false);

        return self::bucketForDays($dias);
    }
}
