<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\ItbmsBalanceResult;

/**
 * SCRUM-1185 (hotfix) — balanceo de centavos entre líneas para que la factura pase la validación de totales de la DGI.
 *
 * El problema: el PAC (MSL/Digifact) declara el ITBMS de cada ítem con 4 decimales exactos pero el total de cada ítem
 * (`dValTotItem`) redondeado a centavos, y `dVTot` es la SUMA de esos totales, mientras que `dTotITBMS` es la suma exacta
 * redondeada. La DGI rechaza (códigos 2507 «Valor total de la factura inválido» y 2511 «Vuelto…») cuando esas dos cifras
 * difieren más de «una unidad sobre el último dígito decimal declarado» (Ficha Técnica de la FE para PAC, v1.00 abril 2025,
 * §8.4.1: 0,01). Con muchas líneas, o con empates de medio centavo (+0,005 cada uno), la suma de los redondeos se aleja del
 * ITBMS exacto más de un centavo y el documento no certifica. Verificado en el sandbox de MSL con las líneas reales de
 * F-0087, F-0116 y F-0139 (docs: memoria del proyecto `project-fe-rechazos-9032-cedulas-extranjeros-20261021`).
 *
 * La regla medida: |Σ round(ITBMS de cada línea) − Σ ITBMS exacto| <= 1 centavo, en ambos sentidos.
 *
 * La solución: mover centavos de a PARES (+1 a una línea, −1 a otra de la MISMA tasa) hasta cumplirla. Como los pares son de la
 * misma tasa, el neto de cada tasa y por lo tanto el ITBMS exacto, el neto total y los impuestos del documento NO cambian:
 * solo se redistribuye el redondeo entre líneas.
 *
 * Aritmética entera, sin flotantes: el ITBMS de una línea es `intdiv(neto × bp + 5000, 10000)` (neto en centavos, bp en puntos
 * base, 7 % = 700). Un flotante resuelve los empates de medio centavo de forma impredecible (4,165 → 4,17 pero 7,595 → 7,59),
 * que fue justo lo que descuadraba los totales.
 *
 * Las dos cifras de abajo son constantes TÉCNICAS del protocolo (no umbrales de negocio): `LIMITE` es la tolerancia de la
 * ficha de la DGI (se actúa solo si se supera) y `OBJETIVO` deja un margen para no quedar justo en el borde al balancear.
 */
final class ItbmsLineBalancer
{
    /** Escala del delta: 1 centavo = 10000 unidades (neto en centavos × puntos base). */
    private const ESCALA = 10000;

    /** Tolerancia de la DGI («una unidad sobre el último dígito declarado») = 1 centavo. */
    private const LIMITE = 10000;

    /** Margen de seguridad: se busca quedar en 0,8 centavos o menos. */
    private const OBJETIVO = 8000;

    /** Máximo de centavos que una misma línea puede alejarse de su neto original: primero 1; si no alcanza, hasta 3. */
    private const DESPLAZAMIENTOS = [1, 3];

    /** ITBMS de una línea, en centavos, con el redondeo aritmético de la DGI (empate hacia arriba). */
    public static function itbmsDeLinea(int $netoCentavos, int $puntosBase): int
    {
        return intdiv($netoCentavos * $puntosBase + 5000, self::ESCALA);
    }

    /**
     * @param list<array{neto: int, bp: int}> $lineas neto de cada línea en centavos (>= 0) y su tasa en puntos base
     */
    public function balancear(array $lineas): ItbmsBalanceResult
    {
        $netos = array_map(static fn (array $l): int => $l['neto'], array_values($lineas));
        $bps   = array_map(static fn (array $l): int => $l['bp'], array_values($lineas));

        $inicial = $this->delta($netos, $bps);
        $final   = $netos;
        $pares   = 0;

        // Solo se actúa cuando la factura SUPERA la tolerancia: en el sandbox de MSL un δ de exactamente 1,0 ¢ certificó (casos E y M1), así que
        // las facturas que hoy ya certifican viajan idénticas. Al actuar se apunta a OBJETIVO (0,8 ¢) para no quedar en el borde.
        if (abs($inicial) > self::LIMITE) {
            foreach (self::DESPLAZAMIENTOS as $maxDesplazamiento) {
                [$candidato, $paresCandidato] = $this->intentar($netos, $bps, $inicial, $maxDesplazamiento);
                $final = $candidato;
                $pares = $paresCandidato;
                if (abs($this->delta($final, $bps)) <= self::LIMITE) {
                    break;
                }
            }
        }

        $deltaFinal = $this->delta($final, $bps);
        if (abs($deltaFinal) > self::LIMITE && abs($deltaFinal) >= abs($inicial)) {
            // Ningún movimiento acercó el documento al límite: se devuelve la entrada intacta (el PAC la rechazará con su propio motivo).
            $final      = $netos;
            $deltaFinal = $inicial;
            $pares      = 0;
        }

        $modificadas = [];
        foreach ($final as $i => $neto) {
            if ($neto !== $netos[$i]) {
                $modificadas[] = $i;
            }
        }

        return new ItbmsBalanceResult(
            netos: array_values($final),
            itbms: array_values(array_map(static fn (int $n, int $bp): int => self::itbmsDeLinea($n, $bp), $final, $bps)),
            modificadas: $modificadas,
            deltaInicial: $inicial / self::ESCALA,
            deltaFinal: $deltaFinal / self::ESCALA,
            pares: $pares,
            dentroDelLimite: abs($deltaFinal) <= self::LIMITE,
        );
    }

    /**
     * δ escalado = Σ (ITBMS redondeado × ESCALA) − Σ (neto × bp). Positivo: la suma por línea excede al ITBMS exacto.
     *
     * @param array<int, int> $netos
     * @param array<int, int> $bps
     */
    private function delta(array $netos, array $bps): int
    {
        $delta = 0;
        foreach ($netos as $i => $neto) {
            $delta += self::itbmsDeLinea($neto, $bps[$i]) * self::ESCALA - $neto * $bps[$i];
        }

        return $delta;
    }

    /**
     * Mueve pares +1/−1 entre líneas de la misma tasa. Cada par cambia δ en exactamente ±1 centavo (±ESCALA): sube el ITBMS
     * redondeado de una línea o baja el de otra, sin tocar el ITBMS exacto. Si δ > 0 busca un par que lo baje; si δ < 0, uno que lo suba.
     *
     * @param array<int, int> $netos
     * @param array<int, int> $bps
     * @return array{0: array<int, int>, 1: int} netos balanceados y pares movidos
     */
    private function intentar(array $netos, array $bps, int $delta, int $maxDesplazamiento): array
    {
        $n            = count($netos);
        $desplaza     = array_fill(0, $n, 0);
        $pares        = 0;
        $iteraciones  = 0;
        $maxIteracion = 4 * $n + 50;

        while (abs($delta) > self::OBJETIVO && $iteraciones++ < $maxIteracion) {
            $sube = $delta < 0;
            // Preferencia (no obligación): un par que no deje a nadie en un empate de medio centavo; solo si no existe se acepta uno con empate.
            $par = $this->buscarPar($netos, $bps, $desplaza, $maxDesplazamiento, $sube, evitaEmpate: true)
                ?? $this->buscarPar($netos, $bps, $desplaza, $maxDesplazamiento, $sube, evitaEmpate: false);
            $paso = 1;
            if ($par === null) {
                // Ningún par de ±1 centavo mueve el redondeo (ocurre cuando casi ninguna línea cambia su ITBMS con un centavo): se prueban
                // pares de ±2 y ±3 centavos, que sí lo mueven exactamente en 1.
                [$par, $paso] = $this->buscarParMayor($netos, $bps, $desplaza, $maxDesplazamiento, $sube);
                if ($par === null) {
                    break;
                }
            }

            [$a, $b] = $par;                 // a recibe +$paso centavos, b cede $paso centavos
            $netos[$a] += $paso;
            $netos[$b] -= $paso;
            $desplaza[$a] += $paso;
            $desplaza[$b] -= $paso;
            $delta += ($delta > 0 ? -1 : 1) * self::ESCALA;
            $pares++;
        }

        return [$netos, $pares];
    }

    /**
     * Busca (a, b) de la misma tasa tal que sumarle 1 centavo a `a` y quitarle 1 a `b` mueva el ITBMS redondeado total en −1 (baja δ)
     * o +1 (sube δ). Preferencia determinista: líneas de mayor neto (el centavo pesa menos) y, a igualdad, la de menor índice.
     *
     * @param array<int, int> $netos
     * @param array<int, int> $bps
     * @param array<int, int> $desplaza
     * @return array{0: int, 1: int}|null
     */
    private function buscarPar(array $netos, array $bps, array $desplaza, int $max, bool $sube, bool $evitaEmpate): ?array
    {
        $orden = array_keys($netos);
        usort($orden, static fn (int $x, int $y): int => [$netos[$y], $x] <=> [$netos[$x], $y]);

        // Para bajar δ: `a` no debe subir su ITBMS al recibir +1 y `b` debe bajar el suyo al ceder 1.
        // Para subir δ: `a` debe subir su ITBMS al recibir +1 y `b` no debe bajar el suyo al ceder 1.
        foreach ($orden as $a) {
            // M2: una línea sin neto (regalo a 0,00) nunca recibe un centavo. M1: se prefiere no dejar a nadie justo en un empate de medio centavo,
            // porque el PAC multiplica en coma flotante y ese redondeo podría voltearse y anular el movimiento.
            if ($bps[$a] === 0 || $desplaza[$a] >= $max || $netos[$a] < 1 || ($evitaEmpate && self::esEmpate($netos[$a] + 1, $bps[$a]))) {
                continue;
            }
            $subeA = self::itbmsDeLinea($netos[$a] + 1, $bps[$a]) > self::itbmsDeLinea($netos[$a], $bps[$a]);
            if ($subeA !== $sube) {
                continue;
            }
            foreach ($orden as $b) {
                if ($b === $a || $bps[$b] !== $bps[$a] || $netos[$b] <= 1 || $desplaza[$b] <= -$max || ($evitaEmpate && self::esEmpate($netos[$b] - 1, $bps[$b]))) {
                    continue;
                }
                $bajaB = self::itbmsDeLinea($netos[$b] - 1, $bps[$b]) < self::itbmsDeLinea($netos[$b], $bps[$b]);
                if ($bajaB === ! $sube) {
                    return [$a, $b];
                }
            }
        }

        return null;
    }

    /**
     * Como `buscarPar` pero moviendo 2 o 3 centavos de una línea a otra de la misma tasa. Se acepta el par cuyo efecto combinado sobre el
     * ITBMS redondeado es exactamente −1 (si δ > 0) o +1 (si δ < 0); el ITBMS exacto no cambia porque la suma de los netos es la misma.
     *
     * @param array<int, int> $netos
     * @param array<int, int> $bps
     * @param array<int, int> $desplaza
     * @return array{0: array{0: int, 1: int}|null, 1: int} par (a recibe, b cede) y cuántos centavos
     */
    private function buscarParMayor(array $netos, array $bps, array $desplaza, int $max, bool $sube): array
    {
        $objetivo = $sube ? 1 : -1;
        $orden    = array_keys($netos);
        usort($orden, static fn (int $x, int $y): int => [$netos[$y], $x] <=> [$netos[$x], $y]);

        for ($paso = 2; $paso <= min(3, $max); $paso++) {
            foreach ([true, false] as $evitaEmpate) {
                foreach ($orden as $a) {
                    if ($bps[$a] === 0 || $netos[$a] < 1 || $desplaza[$a] + $paso > $max
                        || ($evitaEmpate && self::esEmpate($netos[$a] + $paso, $bps[$a]))) {
                        continue;
                    }
                    $efectoA = self::itbmsDeLinea($netos[$a] + $paso, $bps[$a]) - self::itbmsDeLinea($netos[$a], $bps[$a]);
                    foreach ($orden as $b) {
                        if ($b === $a || $bps[$b] !== $bps[$a] || $netos[$b] - $paso < 1 || $desplaza[$b] - $paso < -$max
                            || ($evitaEmpate && self::esEmpate($netos[$b] - $paso, $bps[$b]))) {
                            continue;
                        }
                        $efectoB = self::itbmsDeLinea($netos[$b] - $paso, $bps[$b]) - self::itbmsDeLinea($netos[$b], $bps[$b]);
                        if ($efectoA + $efectoB === $objetivo) {
                            return [[$a, $b], $paso];
                        }
                    }
                }
            }
        }

        return [null, 0];
    }

    /** ¿El ITBMS exacto de la línea cae justo en medio centavo? Ahí el redondeo del PAC es inestable. */
    private static function esEmpate(int $netoCentavos, int $puntosBase): bool
    {
        return ($netoCentavos * $puntosBase) % self::ESCALA === self::ESCALA / 2;
    }
}
