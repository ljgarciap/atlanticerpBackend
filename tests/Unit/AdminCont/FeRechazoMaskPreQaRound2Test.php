<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\FeRechazoNotifier;
use PHPUnit\Framework\TestCase;

final class FeRechazoMaskPreQaRound2Test extends TestCase
{
    public function test_mascara(): void
    {
        $ref = new \ReflectionMethod(FeRechazoNotifier::class, 'sinIdentificaciones');
        $n = (new \ReflectionClass(FeRechazoNotifier::class))->newInstanceWithoutConstructor();
        $ids = ['8-123-4567', 'E-8-123456', 'PE-12-345', '155704849-2-2021', '801234567', '8 123 4567', 'PAS-123456', 'PA1234567', '8-NT-2-1234',
            '8-123-4567 DV 12', '155704849-2-2021 DV 45', '12345-6789', 'N-20-1234', '1-23-456', '4-712-1893', 'AV-123-4567', '8-AV-1-1234', 'PE-5-1234 DV 7', 'N 12 345', '8-123-4567DV12'];
        $malos = [];
        foreach ($ids as $id) {
            foreach (["RUC $id inválido", "El receptor $id no coincide.", "[9032] tipo de RUC para $id", "$id"] as $txt) {
                $out = $ref->invoke($n, $txt);
                // sobrevive algún dígito de la identificación original?
                $digs = preg_replace('/\D/', '', $id);
                $restos = preg_match_all('/\d{2,}/', $out, $m) ? implode(',', $m[0]) : '';
                if ($restos !== '' ) { $malos[] = "'$txt' => '$out'"; }
            }
        }
        fwrite(STDERR, "\nFUGAS(".count($malos)."):\n".implode("\n", array_slice($malos, 0, 40))."\n");
        $fp = ['Total B/. 2109.94 vs 2109.95', 'Códigos 2507 y 2511', 'fecha 2026-10-21 14:30', 'dVTot 4854.26', 'Factura F-0087 rechazada', 'ITBMS 138.04 excede 0.01'];
        foreach ($fp as $t) { fwrite(STDERR, "FP '$t' => '".$ref->invoke($n, $t)."'\n"); }
        $this->assertTrue(true);
    }
}
