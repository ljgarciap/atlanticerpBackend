<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsLineBalancer;
use PHPUnit\Framework\TestCase;

final class ItbmsLineBalancerPreQaRound2Test extends TestCase
{
    private function delta(array $netos, array $bps): int
    {
        $d = 0;
        foreach ($netos as $i => $n) {
            $d += intdiv($n * $bps[$i] + 5000, 10000) * 10000 - $n * $bps[$i];
        }
        return $d;
    }

    public function test_medicion_fuera_de_tolerancia_mismo_metodo_ronda1(): void
    {
        mt_srand(20261021);
        $out = [];
        foreach ([[2, 4], [5, 10], [11, 40], [41, 120]] as [$lo, $hi]) {
            $exceden = 0; $fuera = 0; $ejemplos = [];
            for ($k = 0; $k < 8000; $k++) {
                $n = mt_rand($lo, $hi);
                $netos = []; $bps = [];
                for ($i = 0; $i < $n; $i++) { $netos[] = mt_rand(100, 300000); $bps[] = 700; }
                $ini = $this->delta($netos, $bps);
                if (abs($ini) <= 10000) { continue; }
                $exceden++;
                $lineas = array_map(fn ($a, $b) => ['neto' => $a, 'bp' => $b], $netos, $bps);
                $r = (new ItbmsLineBalancer())->balancear($lineas);
                $fin = $this->delta($r->netos, $bps);
                $this->assertSame(array_sum($netos), array_sum($r->netos));
                if (abs($fin) > 10000) { $fuera++; if (count($ejemplos) < 2) { $ejemplos[] = json_encode($netos); } }
            }
            $out[] = "$lo-$hi lineas: exceden=$exceden fuera=$fuera";
            if ($ejemplos) { fwrite(STDERR, "\nEJEMPLO ".implode(' | ', $ejemplos)."\n"); }
        }
        fwrite(STDERR, "\nMEDICION ".implode(' ;; ', $out)."\n");
        $this->assertTrue(true);
    }

    public function test_mixtas_medicion(): void
    {
        mt_srand(77);
        $exceden = 0; $fuera = 0;
        for ($k = 0; $k < 8000; $k++) {
            $n = mt_rand(3, 30);
            $netos = []; $bps = [];
            for ($i = 0; $i < $n; $i++) { $netos[] = mt_rand(100, 300000); $bps[] = [700, 700, 700, 1000, 1500, 0][mt_rand(0, 5)]; }
            $ini = $this->delta($netos, $bps);
            if (abs($ini) <= 10000) continue;
            $exceden++;
            $r = (new ItbmsLineBalancer())->balancear(array_map(fn ($a, $b) => ['neto' => $a, 'bp' => $b], $netos, $bps));
            if (abs($this->delta($r->netos, $bps)) > 10000) $fuera++;
        }
        fwrite(STDERR, "\nMIXTAS exceden=$exceden fuera=$fuera\n");
        $this->assertTrue(true);
    }

    public function test_rendimiento_1000_y_3000_lineas_y_peor_caso(): void
    {
        mt_srand(5);
        foreach ([300, 1000, 3000] as $n) {
            $peor = 0; $mem = 0;
            for ($k = 0; $k < ($n >= 1000 ? 5 : 20); $k++) {
                $l = [];
                for ($i = 0; $i < $n; $i++) { $l[] = ['neto' => mt_rand(1, 500000), 'bp' => 700]; }
                $t = microtime(true);
                (new ItbmsLineBalancer())->balancear($l);
                $peor = max($peor, microtime(true) - $t);
            }
            fwrite(STDERR, "\nPERF n=$n peor=".round($peor, 2)."s peakMB=".round(memory_get_peak_usage(true) / 1048576, 1)."\n");
        }
        // peor caso: netos pequeños (casi ninguna línea mueve su ITBMS con ±1) donde buscarParMayor se recorre completo
        foreach ([100, 300, 500] as $n) {
            $l = [];
            for ($i = 0; $i < $n; $i++) { $l[] = ['neto' => mt_rand(1, 20), 'bp' => 700]; }
            $t = microtime(true);
            $r = (new ItbmsLineBalancer())->balancear($l);
            fwrite(STDERR, "\nPERF-PEOR n=$n ".round(microtime(true) - $t, 2)."s cambiado=".json_encode($r->cambiado())." dentro=".json_encode($r->dentroDelLimite)."\n");
        }
        // todas iguales (empates masivos) y todas en la tasa 15 con netos de 50
        foreach ([[1000, 50, 700], [1000, 1000, 1500], [1000, 7, 700], [1000, 143, 700]] as [$n, $neto, $bp]) {
            $l = array_fill(0, $n, ['neto' => $neto, 'bp' => $bp]);
            $t = microtime(true);
            $r = (new ItbmsLineBalancer())->balancear($l);
            fwrite(STDERR, "\nPERF-IGUALES n=$n neto=$neto bp=$bp ".round(microtime(true) - $t, 2)."s cambiado=".json_encode($r->cambiado())." dentro=".json_encode($r->dentroDelLimite)." dIni={$r->deltaInicial} dFin={$r->deltaFinal}\n");
        }
        $this->assertTrue(true);
    }

    public function test_limites_de_desplazamiento_empates_y_sumas_fuzz_denso(): void
    {
        mt_srand(99);
        $malos = [];
        for ($it = 0; $it < 15000; $it++) {
            $n = mt_rand(2, 60);
            $tasas = [[700], [700, 1500], [700, 1000, 1500, 0], [1500]][mt_rand(0, 3)];
            $l = [];
            for ($i = 0; $i < $n; $i++) {
                $neto = match (mt_rand(0, 4)) { 0 => mt_rand(1, 40), 1 => mt_rand(1, 3000) * 50, 2 => mt_rand(1, 600), default => mt_rand(1, 400000) };
                $l[] = ['neto' => $neto, 'bp' => $tasas[mt_rand(0, count($tasas) - 1)]];
            }
            $r = (new ItbmsLineBalancer())->balancear($l);
            $bps = array_column($l, 'bp');
            $in = array_column($l, 'neto');
            $porIn = []; $porOut = [];
            foreach ($l as $i => $x) {
                $porIn[$x['bp']] = ($porIn[$x['bp']] ?? 0) + $x['neto'];
                $porOut[$x['bp']] = ($porOut[$x['bp']] ?? 0) + $r->netos[$i];
                $d = $r->netos[$i] - $x['neto'];
                if (abs($d) > 3) $malos[] = "desp $d ".json_encode($l);
                if ($r->netos[$i] < 0 || ($x['neto'] === 0 && $d !== 0) || ($x['bp'] === 0 && $d !== 0)) $malos[] = 'neg/exenta '.json_encode($l);
                if ($r->netos[$i] < 1 && $x['neto'] >= 1) $malos[] = 'línea llevada a 0 '.json_encode($l);
            }
            ksort($porIn); ksort($porOut);
            if ($porIn !== $porOut) $malos[] = 'suma por tasa';
            $ini = $this->delta($in, $bps); $fin = $this->delta($r->netos, $bps);
            if (abs($fin) > abs($ini)) $malos[] = 'empeora';
            if (abs($ini) <= 10000 && $r->cambiado()) $malos[] = 'tocó correcta';
            if ($r->cambiado() && abs($fin) > 10000) $malos[] = 'cambiado pero fuera de límite (debería devolverse intacta?) '.json_encode($l);
            if ($r->dentroDelLimite !== (abs($fin) <= 10000)) $malos[] = 'flag';
            $again = (new ItbmsLineBalancer())->balancear(array_map(fn ($a, $b) => ['neto' => $a, 'bp' => $b], $r->netos, $bps));
            if ($again->cambiado()) $malos[] = 'no idempotente '.json_encode($l);
            if ($r != (new ItbmsLineBalancer())->balancear($l)) $malos[] = 'no determinista';
        }
        $this->assertSame([], array_slice($malos, 0, 5));
    }

    public function test_margen_del_alineador_por_numero_de_lineas(): void
    {
        mt_srand(4242);
        foreach ([1, 2, 10, 60, 120, 200, 300, 600] as $n) {
            $margen = min(50, 5 + intdiv($n, 10));
            $max = 0; $fuera = 0; $total = 0; $reps = $n >= 300 ? 1500 : 4000;
            for ($k = 0; $k < $reps; $k++) {
                $l = []; $naive = 0; $netoSum = 0;
                for ($i = 0; $i < $n; $i++) { $neto = mt_rand(100, 400000); $l[] = ['neto' => $neto, 'bp' => 700]; $naive += $neto + intdiv($neto * 700 + 5000, 10000); $netoSum += $neto; }
                $exacto = $netoSum + intdiv($netoSum * 700 + 5000, 10000);   // monto "ITBMS sobre el total"
                $r = (new ItbmsLineBalancer())->balancear($l);
                if (! $r->cambiado()) { continue; }
                $total++;
                $d = abs($r->totalCentavos() - $naive);
                $d2 = abs($r->totalCentavos() - $exacto);
                $max = max($max, $d);
                if ($d > $margen) { $fuera++; }
                $max2 = max($max2 ?? 0, $d2);
            }
            fwrite(STDERR, "\nMARGEN n=$n margen=$margen balanceadas=$total maxDiffVsNaive=$max fueraDeMargenVsNaive=$fuera maxDiffVsExacto=".($max2 ?? 0)."\n");
            unset($max2);
        }
        $this->assertTrue(true);
    }
}
