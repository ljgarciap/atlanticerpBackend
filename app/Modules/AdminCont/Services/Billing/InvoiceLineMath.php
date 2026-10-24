<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services\Billing;

/**
 * SCRUM-929 (B3a) — aritmética de líneas compartida por el cierre de un pedido
 * (`InvoiceBillingPlanner::cerrarPedido()`) y por la factura de reemplazo de "Corrección de datos"
 * (`CreditNoteService`): repartir una diferencia de redondeo (o un descuadre) en `subtotal` o `itbms`
 * SIN dejar ninguna línea con un importe negativo — un ITBMS o una base negativa en una línea es un dato
 * fiscal inválido para un PAC (Senior Review N-1).
 */
final class InvoiceLineMath
{
    /** Épsilon numérico: medio centavo, la mitad de la resolución de los importes (2 decimales). */
    private const EPSILON = 0.005;

    /**
     * Suma `$delta` al campo `$campo` (`subtotal` | `itbms`) de las líneas y recalcula `total` de las
     * que cambian. Un `$delta` positivo va a la línea de mayor valor de ese campo; uno negativo se resta
     * de las líneas empezando por la de mayor valor, sin bajar ninguna de 0, hasta agotarlo. Si aun así
     * no alcanza (la suma total del campo es menor que el descuento pedido), lo que sobra se resta de
     * la línea mayor — puede quedar negativa — y se devuelve en `sobrante` para que el llamador advierta.
     *
     * @template T of array{subtotal: float, itbms: float, total: float}
     * @param list<T> $lines
     * @return array{0: list<T>, 1: float} líneas ajustadas y monto que no se pudo absorber sin negativos (0.0 normalmente)
     */
    public static function repartir(array $lines, string $campo, float $delta): array
    {
        $delta = round($delta, 2);
        if ($lines === [] || abs($delta) < self::EPSILON) {
            return [$lines, 0.0];
        }

        // Índices ordenados por valor desc; a igualdad, el primero (determinista).
        $orden = array_keys($lines);
        usort($orden, static fn (int $a, int $b): int => [$lines[$b][$campo], $a] <=> [$lines[$a][$campo], $b]);

        if ($delta > 0) {
            $lines[$orden[0]][$campo] = round($lines[$orden[0]][$campo] + $delta, 2);
            $lines[$orden[0]]['total'] = round($lines[$orden[0]]['subtotal'] + $lines[$orden[0]]['itbms'], 2);

            /** @var list<T> $ajustadas */
            $ajustadas = $lines;

            return [$ajustadas, 0.0];
        }

        $porQuitar = -$delta;
        foreach ($orden as $i) {
            $quita = min($porQuitar, max(0.0, $lines[$i][$campo]));
            if ($quita <= 0.0) {
                continue;
            }
            $lines[$i][$campo]  = round($lines[$i][$campo] - $quita, 2);
            $lines[$i]['total'] = round($lines[$i]['subtotal'] + $lines[$i]['itbms'], 2);
            $porQuitar          = round($porQuitar - $quita, 2);
            if ($porQuitar < self::EPSILON) {
                /** @var list<T> $ajustadas */
                $ajustadas = $lines;

                return [$ajustadas, 0.0];
            }
        }

        // No hay de dónde quitar sin negativos: se resta igual de la línea mayor y se avisa.
        $i = $orden[0];
        $lines[$i][$campo]  = round($lines[$i][$campo] - $porQuitar, 2);
        $lines[$i]['total'] = round($lines[$i]['subtotal'] + $lines[$i]['itbms'], 2);

        /** @var list<T> $ajustadas */
        $ajustadas = $lines;

        return [$ajustadas, $porQuitar];
    }
}
