<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Support;

/**
 * SCRUM-944/945 (Pre-QA hallazgo A) — reglas de coherencia de los tramos de comisión de ventas.
 * Trabaja en centavos enteros (nunca floats) para que la convención sembrada — `monto_maximo` en
 * ".99" y siguiente mínimo = máximo + 0.01 — se compare sin errores de redondeo.
 *
 * - `overlapErrors()`: lo que validan SIEMPRE las operaciones individuales (POST/PUT/DELETE):
 *   solo solapamientos y mínimo <= máximo — nunca cobertura completa, para poder reestructurar
 *   tramo por tramo.
 * - `fullSetErrors()`: lo que valida el reemplazo atómico (PUT colección): cobertura completa
 *   desde 0, sin huecos ni solapamientos, único tramo final abierto.
 */
final class CommissionTierRules
{
    private const OPEN_END = PHP_INT_MAX;

    public static function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
    }

    /**
     * @param  array<string, mixed>  $candidate  monto_minimo, monto_maximo (null = abierto)
     * @param  array<int, array<string, mixed>>  $others
     * @return list<string>
     */
    public static function overlapErrors(array $candidate, array $others): array
    {
        $errors = [];
        $min = self::cents($candidate['monto_minimo'] ?? null);
        $max = self::endCents($candidate);

        if ($max < $min) {
            $errors[] = trans('parametric_settings.tiers.min_gt_max', ['min' => self::fmt($min)]);

            return $errors;
        }

        foreach ($others as $other) {
            $otherMin = self::cents($other['monto_minimo'] ?? null);
            $otherMax = self::endCents($other);
            if ($min <= $otherMax && $otherMin <= $max) {
                $errors[] = trans('parametric_settings.tiers.overlap', [
                    'min'   => self::fmt($min),
                    'other' => self::fmt($otherMin),
                ]);
                break;
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     * @return list<string>
     */
    public static function fullSetErrors(array $tiers): array
    {
        if ($tiers === []) {
            return [trans('parametric_settings.tiers.min_one')];
        }

        usort($tiers, static fn (array $a, array $b): int => self::cents($a['monto_minimo'] ?? null) <=> self::cents($b['monto_minimo'] ?? null));

        $errors = [];
        if (self::cents($tiers[0]['monto_minimo'] ?? null) !== 0) {
            $errors[] = trans('parametric_settings.tiers.first_zero');
        }

        $lastIndex = count($tiers) - 1;
        foreach ($tiers as $i => $tier) {
            $min = self::cents($tier['monto_minimo'] ?? null);
            $isOpen = ($tier['monto_maximo'] ?? null) === null;

            if ($i < $lastIndex && $isOpen) {
                $errors[] = trans('parametric_settings.tiers.only_last_open', ['min' => self::fmt($min)]);
            }
            if ($i === $lastIndex && ! $isOpen) {
                $errors[] = trans('parametric_settings.tiers.last_open');
            }
            if (! $isOpen && self::endCents($tier) < $min) {
                $errors[] = trans('parametric_settings.tiers.min_gt_max', ['min' => self::fmt($min)]);

                continue;
            }
            if ($i === 0 || ($tiers[$i - 1]['monto_maximo'] ?? null) === null) {
                continue;
            }

            $prevMax = self::endCents($tiers[$i - 1]);
            if ($min <= $prevMax) {
                $errors[] = trans('parametric_settings.tiers.overlap', [
                    'min'   => self::fmt($min),
                    'other' => self::fmt(self::cents($tiers[$i - 1]['monto_minimo'] ?? null)),
                ]);
            } elseif ($min > $prevMax + 1) {
                $errors[] = trans('parametric_settings.tiers.gap', [
                    'max'      => self::fmt($prevMax),
                    'min'      => self::fmt($min),
                    'expected' => self::fmt($prevMax + 1),
                ]);
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $tier */
    private static function endCents(array $tier): int
    {
        $max = $tier['monto_maximo'] ?? null;

        return $max === null ? self::OPEN_END : self::cents($max);
    }

    private static function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
