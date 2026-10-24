<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsLineBalancer;
use PHPUnit\Framework\TestCase;

/** Pre-QA SCRUM-1185 — fuzz adversarial: invariantes del balanceador. */
final class ItbmsLineBalancerPreQaFuzzTest extends TestCase
{
    private function delta(array $netos, array $bps): int
    {
        $d = 0;
        foreach ($netos as $i => $n) {
            $d += intdiv($n * $bps[$i] + 5000, 10000) * 10000 - $n * $bps[$i];
        }
        return $d;
    }

    public function test_invariantes_con_fuzz(): void
    {
        mt_srand(1185);
        $stats = ['casos' => 0, 'tocados' => 0, 'fuera_limite' => 0, 'cambio_sin_necesidad' => 0, 'max_desp' => 0, 'empeora' => 0];
        $malos = [];
        for ($it = 0; $it < 6000; $it++) {
            $n = [1, 1, 2, 3, 5, 8, 14, 33, 100, 300][mt_rand(0, 9)];
            $tasas = [[700], [700], [700, 0], [700, 1000, 1500, 0], [0], [1500], [1000, 700]][mt_rand(0, 6)];
            $lineas = [];
            for ($i = 0; $i < $n; $i++) {
                $modo = mt_rand(0, 9);
                $neto = match (true) {
                    $modo === 0 => 0,
                    $modo === 1 => 1,
                    $modo === 2 => mt_rand(1, 20),
                    $modo <= 6 => mt_rand(100, 60000),
                    default => mt_rand(1, 5000000),
                };
                // empates de medio centavo: neto múltiplo de 50 con 7 %
                if (mt_rand(0, 4) === 0) { $neto = mt_rand(1, 3000) * 50; }
                $lineas[] = ['neto' => $neto, 'bp' => $tasas[mt_rand(0, count($tasas) - 1)]];
            }
            $r1 = (new ItbmsLineBalancer())->balancear($lineas);
            $r2 = (new ItbmsLineBalancer())->balancear($lineas);
            $stats['casos']++;
            $netosIn = array_column($lineas, 'neto');
            $bps = array_column($lineas, 'bp');
            $ctx = json_encode($lineas);
            if ($r1 != $r2) { $malos[] = "no determinista $ctx"; }
            if (array_sum($r1->netos) !== array_sum($netosIn)) { $malos[] = "neto total cambia $ctx"; }
            if (count($r1->netos) !== $n) { $malos[] = "cuenta $ctx"; }
            $porTasaIn = $porTasaOut = [];
            foreach ($lineas as $i => $l) {
                $porTasaIn[$l['bp']] = ($porTasaIn[$l['bp']] ?? 0) + $l['neto'];
                $porTasaOut[$l['bp']] = ($porTasaOut[$l['bp']] ?? 0) + $r1->netos[$i];
                if ($r1->netos[$i] < 0) { $malos[] = "neto negativo $ctx"; }
                $d = $r1->netos[$i] - $l['neto'];
                $stats['max_desp'] = max($stats['max_desp'], abs($d));
                if (abs($d) > 3) { $malos[] = "desplazamiento>3 $ctx"; }
                if ($l['bp'] === 0 && $d !== 0) { $malos[] = "exenta tocada $ctx"; }
                if ($l['neto'] === 0 && $d !== 0) { $malos[] = "línea en 0 tocada $ctx"; }
                if ($r1->itbms[$i] !== intdiv($r1->netos[$i] * $l['bp'] + 5000, 10000)) { $malos[] = "itbms inconsistente $ctx"; }
            }
            ksort($porTasaIn); ksort($porTasaOut);
            if ($porTasaIn !== $porTasaOut) { $malos[] = "neto por tasa cambia $ctx"; }
            $ini = $this->delta($netosIn, $bps);
            $fin = $this->delta($r1->netos, $bps);
            if (abs($fin - $r1->deltaFinal * 10000) > 0.5) { $malos[] = "deltaFinal reportado distinto $ctx"; }
            if (abs($ini) <= 10000 && $r1->cambiado()) { $stats['cambio_sin_necesidad']++; $malos[] = "tocó factura correcta $ctx"; }
            if (abs($fin) > abs($ini) && $r1->cambiado()) { $stats['empeora']++; $malos[] = "empeora $ctx"; }
            if ($r1->dentroDelLimite !== (abs($fin) <= 10000)) { $malos[] = "flag dentroDelLimite $ctx"; }
            if (abs($ini) > 10000 && abs($fin) > 10000) { $stats['fuera_limite']++; if ($stats['fuera_limite'] < 6) fwrite(STDERR, "\nFUERA n=$n ini=$ini fin=$fin ".substr($ctx,0,300)."\n"); }
            if ($r1->cambiado()) { $stats['tocados']++; }
            // idempotencia: re-balancear la salida no mueve nada
            $again = (new ItbmsLineBalancer())->balancear(array_map(fn ($nn, $b) => ['neto' => $nn, 'bp' => $b], $r1->netos, $bps));
            if ($again->cambiado()) { $malos[] = "no idempotente $ctx"; }
        }
        fwrite(STDERR, "\nSTATS ".json_encode($stats)."\n");
        $this->assertSame([], array_slice($malos, 0, 5));
    }

    public function test_bordes(): void
    {
        $b = new ItbmsLineBalancer();
        $this->assertSame([], $b->balancear([])->netos);
        $r = $b->balancear([['neto' => 0, 'bp' => 700]]);
        $this->assertFalse($r->cambiado());
        $r = $b->balancear([['neto' => 1, 'bp' => 700], ['neto' => 1, 'bp' => 700]]);
        fwrite(STDERR, "\n1c+1c: ".json_encode($r)."\n");
        // negativo
        $r = $b->balancear([['neto' => -500, 'bp' => 700], ['neto' => 1000, 'bp' => 700]]);
        fwrite(STDERR, "\nneg: ".json_encode($r)."\n");
        $this->assertTrue(true);
    }
}
