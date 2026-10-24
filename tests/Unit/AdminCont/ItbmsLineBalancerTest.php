<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsLineBalancer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-1185 (hotfix) — `ItbmsLineBalancer`. Los casos con nombre reproducen envíos reales al sandbox de MSL del 2026-10-21 (rondas 2 a 6):
 * lo que ahí certificó o rechazó está fijado acá. Los netos están en centavos.
 */
final class ItbmsLineBalancerTest extends TestCase
{
    private const SIETE = 700;

    /** Líneas reales de ILU-0116 (producción, rechazada 9026): [cantidad, neto en centavos]. */
    private const F0116 = [25872, 25872, 30800, 6684, 5880, 14627, 17500, 7560, 7997, 11900, 21700, 3998, 5950, 10850];

    /** Líneas reales de ILU-0087 (33 líneas). */
    private const F0087 = [1230, 18522, 13230, 3444, 984, 44760, 10584, 15400, 59500, 12750, 1722, 540, 924, 2120, 13760, 26148, 7604, 21250, 17000, 51200,
        12800, 3300, 1100, 4400, 7700, 5500, 37044, 19200, 12800, 19422, 3237, 1470, 3024];

    /** Líneas reales de ILU-0139 (12 líneas). */
    private const F0139 = [13536, 3245, 646, 49595, 96520, 13412, 2786, 2494, 2667, 723, 7436, 400];

    /** @param list<int> $netos @return list<array{neto: int, bp: int}> */
    private static function lineas(array $netos, int $bp = self::SIETE): array
    {
        return array_map(static fn (int $n): array => ['neto' => $n, 'bp' => $bp], $netos);
    }

    /** δ en centavos calculado de forma independiente (no usa la clase): suma del ITBMS por línea (empate hacia arriba) − ITBMS exacto. */
    private static function delta(array $netos, array $bps): float
    {
        $redondeado = 0; $exacto = 0.0;
        foreach ($netos as $i => $n) {
            $redondeado += (int) floor($n * $bps[$i] / 10000 + 0.5 + 1e-9);
            $exacto     += $n * $bps[$i] / 10000;
        }

        return round($redondeado - $exacto, 6);
    }

    public function test_itbms_de_linea_redondea_el_empate_de_medio_centavo_hacia_arriba(): void
    {
        $this->assertSame(417, ItbmsLineBalancer::itbmsDeLinea(5950, self::SIETE), '59,50 × 7 % = 4,165 → 4,17');
        $this->assertSame(760, ItbmsLineBalancer::itbmsDeLinea(10850, self::SIETE), '108,50 × 7 % = 7,595 → 7,60 (el flotante daba 7,59)');
        $this->assertSame(11, ItbmsLineBalancer::itbmsDeLinea(150, self::SIETE), '1,50 × 7 % = 0,105 → 0,11');
        $this->assertSame(416, ItbmsLineBalancer::itbmsDeLinea(5949, self::SIETE));
        $this->assertSame(1811, ItbmsLineBalancer::itbmsDeLinea(25872, self::SIETE));
        $this->assertSame(0, ItbmsLineBalancer::itbmsDeLinea(100, 0), 'exento');
        $this->assertSame(1500, ItbmsLineBalancer::itbmsDeLinea(10000, 1500));
    }

    /** @return array<string, array{0: list<int>, 1: float, 2: float, 3: int, 4: int, 5: int}> nombre => [netos, δ antes, δ después, líneas movidas, pares, total fiscal esperado] */
    public static function facturasReales(): array
    {
        return [
            // Verificado en el sandbox: control rechazado, balanceada CERTIFICADA (rondas 5 y 6).
            'ILU-0116 (14 líneas)' => [self::F0116, 1.70, 0.70, 2, 1, 210994],
            'ILU-0087 (33 líneas)' => [self::F0087, 2.17, 0.17, 3, 2, 485426],
            'ILU-0139 (12 líneas)' => [self::F0139, 1.80, 0.80, 2, 1, 207003],
        ];
    }

    /** @param list<int> $netos */
    #[DataProvider('facturasReales')]
    public function test_balancea_las_facturas_reales_rechazadas_en_produccion(array $netos, float $antes, float $despues, int $movidas, int $pares, int $total): void
    {
        $r = (new ItbmsLineBalancer())->balancear(self::lineas($netos));

        $this->assertEqualsWithDelta($antes, $r->deltaInicial, 1e-9);
        $this->assertEqualsWithDelta($despues, $r->deltaFinal, 1e-9);
        $this->assertTrue($r->dentroDelLimite);
        $this->assertGreaterThanOrEqual(1, count($r->modificadas));
        $this->assertLessThanOrEqual(2 * $r->pares, count($r->modificadas), 'cada par toca a lo sumo 2 líneas');
        $this->assertSame($pares, $r->pares);
        $desplazamiento = array_sum(array_map(static fn (int $a, int $b): int => abs($a - $b), $r->netos, $netos));
        $this->assertSame(2 * $r->pares, $desplazamiento, 'cada par mueve exactamente un centavo de una línea a otra');
        $this->assertSame(array_sum($netos), array_sum($r->netos), 'el neto total no cambia');
        $this->assertSame($total, $r->totalCentavos(), 'total fiscal = neto + ITBMS por línea (el dVTot que firma MSL)');
        $this->assertEqualsWithDelta($despues, self::delta($r->netos, array_fill(0, count($netos), self::SIETE)), 1e-9, 'δ recalculado de forma independiente');
    }

    /** @return array<string, array{0: list<int>, 1: bool}> nombre => [netos, ¿debe cambiar?] — casos del sandbox de MSL */
    public static function casosDelSandbox(): array
    {
        return [
            'C control: 3 líneas sin empates (certificó)'               => [[25872, 25872, 30800], false],
            'E: 2 líneas con empate, δ +0,0100 (certificó)'            => [[5950, 10850], false],
            'M1: 2 líneas iguales de 59,50, δ +0,0100 (certificó)'     => [[5950, 5950], false],
            'G: 3 × 59,50, δ +0,0150 (rechazó)'                        => [[5950, 5950, 5950], true],
            'F: 4 × 59,50, δ +0,0200 (rechazó)'                        => [[5950, 5950, 5950, 5950], true],
            'H: 59,49 + 3 × 59,50, δ +0,0107 (rechazó)'                => [[5949, 5950, 5950, 5950], true],
            'M2: 4 × 59,49, δ −0,0172 (rechazó: el error hacia abajo también cuenta)' => [[5949, 5949, 5949, 5949], true],
            'M3: 3 × 59,50 + 2 × 59,49, δ +0,0064 (certificó)'         => [[5950, 5950, 5950, 5949, 5949], false],
            'K: ILU-0116 con 108,49 en la línea 14 (certificó)'        => [[...array_slice(self::F0116, 0, 13), 10849], false],
        ];
    }

    /** @param list<int> $netos */
    #[DataProvider('casosDelSandbox')]
    public function test_los_casos_que_el_sandbox_certifico_no_se_tocan_y_los_que_rechazo_se_corrigen(array $netos, bool $debeCambiar): void
    {
        $r = (new ItbmsLineBalancer())->balancear(self::lineas($netos));

        $this->assertSame($debeCambiar, $r->cambiado());
        $this->assertTrue($r->dentroDelLimite);
        $this->assertLessThanOrEqual(1.0, abs($r->deltaFinal));
        $this->assertSame(array_sum($netos), array_sum($r->netos));
        if (! $debeCambiar) {
            $this->assertSame($netos, $r->netos, 'una factura que ya cumple viaja idéntica');
        }
    }

    public function test_solo_se_mueven_centavos_entre_lineas_de_la_misma_tasa_y_las_exentas_no_se_tocan(): void
    {
        $lineas = [
            ['neto' => 5950, 'bp' => 700], ['neto' => 5950, 'bp' => 700], ['neto' => 5950, 'bp' => 700], ['neto' => 5950, 'bp' => 700],
            ['neto' => 5950, 'bp' => 1000], ['neto' => 5950, 'bp' => 1000],
            ['neto' => 12345, 'bp' => 0], ['neto' => 99, 'bp' => 0],
        ];
        $r = (new ItbmsLineBalancer())->balancear($lineas);

        foreach ([700, 1000, 0] as $bp) {
            $antes   = array_sum(array_map(fn (array $l): int => $l['bp'] === $bp ? $l['neto'] : 0, $lineas));
            $despues = 0;
            foreach ($lineas as $i => $l) { $despues += $l['bp'] === $bp ? $r->netos[$i] : 0; }
            $this->assertSame($antes, $despues, "el neto de la tasa $bp no cambia");
        }
        $this->assertSame(12345, $r->netos[6]);
        $this->assertSame(99, $r->netos[7]);
        $this->assertTrue($r->dentroDelLimite);
    }

    public function test_si_no_hay_forma_de_mejorar_devuelve_la_entrada_intacta_y_lo_dice(): void
    {
        // 30 líneas de 1 centavo al 7 %: cada ITBMS redondea a 0 (exacto 0,07 ¢) → δ = −2,1 ¢; no hay centavos que mover (ninguna línea puede ceder).
        $r = (new ItbmsLineBalancer())->balancear(self::lineas(array_fill(0, 30, 1)));

        $this->assertFalse($r->cambiado());
        $this->assertFalse($r->dentroDelLimite);
        $this->assertSame(array_fill(0, 30, 1), $r->netos);
        $this->assertEqualsWithDelta(-2.1, $r->deltaFinal, 1e-9);
    }

    public function test_una_factura_vacia_o_de_una_linea_no_cambia(): void
    {
        $b = new ItbmsLineBalancer();
        $this->assertFalse($b->balancear([])->cambiado());
        $this->assertFalse($b->balancear(self::lineas([5950]))->cambiado(), 'una línea nunca pasa de 0,5 ¢');
    }

    public function test_es_determinista(): void
    {
        $b = new ItbmsLineBalancer();
        $this->assertEquals($b->balancear(self::lineas(self::F0087)), $b->balancear(self::lineas(self::F0087)));
    }

    public function test_propiedades_con_facturas_aleatorias_de_tasas_mixtas(): void
    {
        mt_srand(1185);
        $b = new ItbmsLineBalancer();
        $tasas = [700, 700, 700, 1000, 1500, 0];
        $enLimite = 0; $total = 0;

        for ($caso = 0; $caso < 300; $caso++) {
            $n = mt_rand(1, 60);
            $lineas = [];
            for ($i = 0; $i < $n; $i++) {
                // mezcla de importes comunes y de empates de medio centavo (múltiplos de 50 centavos)
                $neto = mt_rand(0, 3) === 0 ? mt_rand(1, 400) * 50 : mt_rand(5, 500000);
                $lineas[] = ['neto' => $neto, 'bp' => $tasas[mt_rand(0, count($tasas) - 1)]];
            }
            $r = $b->balancear($lineas);

            $this->assertCount($n, $r->netos);
            foreach (array_unique(array_column($lineas, 'bp')) as $bp) {
                $antes = 0; $despues = 0;
                foreach ($lineas as $i => $l) { if ($l['bp'] === $bp) { $antes += $l['neto']; $despues += $r->netos[$i]; } }
                $this->assertSame($antes, $despues, "caso $caso: el neto de la tasa $bp cambió");
            }
            foreach ($r->netos as $i => $neto) {
                $this->assertGreaterThanOrEqual(0, $neto);
                if ($lineas[$i]['bp'] === 0) { $this->assertSame($lineas[$i]['neto'], $neto, 'una línea exenta no se toca'); }
                $this->assertLessThanOrEqual(3, abs($neto - $lineas[$i]['neto']), 'ninguna línea se aleja más de 3 centavos');
            }
            $this->assertEqualsWithDelta(self::delta($r->netos, array_column($lineas, 'bp')), $r->deltaFinal, 1e-6, "caso $caso: δ reportado ≠ δ recalculado");
            $this->assertLessThanOrEqual(abs($r->deltaInicial) + 1e-9, abs($r->deltaFinal), 'balancear nunca empeora');
            $total++;
            if ($r->dentroDelLimite) { $enLimite++; }
        }

        $this->assertGreaterThanOrEqual((int) ($total * 0.97), $enLimite, 'casi todas las facturas aleatorias deben quedar dentro del límite');
    }

    public function test_rinde_con_300_lineas(): void
    {
        mt_srand(7);
        $lineas = [];
        for ($i = 0; $i < 300; $i++) { $lineas[] = ['neto' => 5950, 'bp' => self::SIETE]; }   // 300 empates: δ = +1,5 ¢ × 100
        $t0 = microtime(true);
        $r = (new ItbmsLineBalancer())->balancear($lineas);

        $this->assertLessThan(1.0, microtime(true) - $t0);
        $this->assertTrue($r->dentroDelLimite);
        $this->assertSame(300 * 5950, array_sum($r->netos));
    }

    public function test_una_linea_sin_neto_no_recibe_centavos_ni_se_deja_a_nadie_en_un_empate_de_medio_centavo(): void
    {
        $b = new ItbmsLineBalancer();
        foreach ([self::F0116, self::F0087, self::F0139, [0, ...self::F0116]] as $netos) {
            $entrada = self::lineas($netos);
            $r = $b->balancear($entrada);
            foreach ($r->netos as $i => $neto) {
                if ($netos[$i] === 0) {
                    $this->assertSame(0, $neto, 'la línea a 0,00 queda en 0,00');
                }
                if ($neto !== $netos[$i]) {
                    $this->assertNotSame(5000, ($neto * 700) % 10000, 'ninguna línea movida queda en empate (estos casos tienen alternativa)');
                }
            }
        }
    }

    public function test_resuelve_facturas_donde_ningun_par_de_un_centavo_mueve_el_redondeo(): void
    {
        // Hallazgo ALTO-1 del Pre-QA: δ = −1,23 ¢ con 0 líneas movidas antes de permitir pares de ±2 y ±3 centavos.
        $netos = [106415, 116286, 100276, 131006, 154033, 222173];
        $r = (new ItbmsLineBalancer())->balancear(self::lineas($netos));

        $this->assertTrue($r->dentroDelLimite);
        $this->assertSame(array_sum($netos), array_sum($r->netos), 'el neto total no cambia');
        $this->assertNotSame([], $r->modificadas);
        foreach ($r->netos as $i => $neto) {
            $this->assertLessThanOrEqual(3, abs($neto - $netos[$i]));
        }
    }
}
