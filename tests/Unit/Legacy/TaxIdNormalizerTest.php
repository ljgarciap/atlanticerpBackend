<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Support\TaxIdNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-1137 (Fase 2) — tests puros (sin DB) de `TaxIdNormalizer`. Los casos "reales" son valores
 * medidos en ICG real (2026-10-13).
 */
final class TaxIdNormalizerTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: string, 5: string}> */
    public static function casosProvider(): array
    {
        // nif20, cif, ruc esperado, dv esperado, origen, estado
        return [
            // ── Valores reales de NIF20 ──
            'real: RUC sin DV'                    => ['155716554-2-2021', null, '155716554-2-2021', null, 'nif20', 'ok'],
            'real: DV de 2 dígitos pegado'        => ['155783782-2-2026DV35', null, '155783782-2-2026', '35', 'nif20', 'ok'],
            'real: DV con espacio y cero inicial' => ['987695-1-533551 DV09', null, '987695-1-533551', '09', 'nif20', 'ok'],
            'real: DV pegado 2155801'             => ['2155801-1-766140DV26', null, '2155801-1-766140', '26', 'nif20', 'ok'],
            'real: DV de 1 dígito'                => ['1160410-1-573597DV2', null, '1160410-1-573597', '2', 'nif20', 'ok'],
            'real: RUC sin DV 1671600'            => ['1671600-1-679822', null, '1671600-1-679822', null, 'nif20', 'ok'],
            'real: cédula natural'                => ['6-712-881', null, '6-712-881', null, 'nif20', 'ok'],

            // ── Valores reales de CIF solo ──
            'real: CIF cortado en 12'             => [null, '155783782-2-', '155783782-2-', null, 'cif', 'truncado_posible'],
            // Mide exactamente 12 caracteres: por la regla (f) es truncado_posible aunque quizá esté completo.
            'real: CIF de 12 completo'            => [null, '25057888-3-2', '25057888-3-2', null, 'cif', 'truncado_posible'],

            // ── Casos raros: nunca se corrigen solos ──
            'raro: RUC 1671600- (cliente 7340)'   => [null, 'RUC 1671600-', 'RUC 1671600-', null, 'cif', 'revisar'],
            'raro: 155662320 2 (cliente 7759)'    => [null, '155662320 2 ', '155662320 2', null, 'cif', 'revisar'],

            // ── Bordes ──
            'ambos null'                          => [null, null, null, null, 'ninguno', 'ok'],
            'ambos vacíos'                        => ['', '', null, null, 'ninguno', 'ok'],
            'solo espacios'                       => ['   ', "  \t ", null, null, 'ninguno', 'ok'],
            'nif20 vacío cae a cif'               => ['  ', '8-123-456', '8-123-456', null, 'cif', 'ok'],
            'DV de 4 dígitos'                     => ['155-2-1DV1234', null, '155-2-1', '1234', 'nif20', 'ok'],
            'DV de 5 dígitos no es DV'            => ['155-2-1DV12345', null, '155-2-1DV12345', null, 'nif20', 'ok'],
            'dv en minúscula'                     => ['155783782-2-2026dv35', null, '155783782-2-2026', '35', 'nif20', 'ok'],
            'dv mixto con espacios'               => ['155783782-2-2026 Dv 35', null, '155783782-2-2026', '35', 'nif20', 'ok'],
            'nif20 y cif iguales'                 => ['155716554-2-2021', '155716554-2-2021', '155716554-2-2021', null, 'nif20', 'ok'],
            'nif20 extiende al cif'               => ['155783782-2-2026DV35', '155783782-2-', '155783782-2-2026', '35', 'nif20', 'ok'],
            'nif20 manda aunque cif sea raro'     => ['6-712-881', 'RUC 1671600-', '6-712-881', null, 'nif20', 'ok'],
            'espacios alrededor de nif20'         => ['  6-712-881  ', null, '6-712-881', null, 'nif20', 'ok'],
            'marcador DV sin dígitos'             => ['155-2-1DV', null, '155-2-1DV', null, 'nif20', 'ok'],
            'letras antes de DV no se parte'      => ['ABCDV12', null, 'ABCDV12', null, 'nif20', 'ok'],
            'DV sin resto no se separa'           => ['DV35', null, 'DV35', null, 'nif20', 'ok'],
            'RUC en nif20 es revisar'             => ['RUC 155-2-1', null, 'RUC 155-2-1', null, 'nif20', 'revisar'],
            'RUC pegado es revisar'               => ['RUC155-2-1', null, 'RUC155-2-1', null, 'nif20', 'revisar'],
            'guion final en nif20 es revisar'     => ['155-2-', null, '155-2-', null, 'nif20', 'revisar'],
            'espacio interno en nif20 es revisar' => ['155 2 1', null, '155 2 1', null, 'nif20', 'revisar'],
            'guion doble es revisar'              => ['155--2', null, '155--2', null, 'nif20', 'revisar'],
            'revisar con DV conserva el original' => ['RUC 1671600-1 DV12', null, 'RUC 1671600-1 DV12', null, 'nif20', 'revisar'],
            'cif 12 caracteres válido'            => [null, '123456789012', '123456789012', null, 'cif', 'truncado_posible'],
            'cif 11 caracteres no es truncado'    => [null, '12345678901', '12345678901', null, 'cif', 'ok'],
            'cif 12 con nif20 no es truncado'     => ['155783782-2-2026', '155783782-2-', '155783782-2-2026', null, 'nif20', 'ok'],
            'letras en cédula especial'           => ['PE-1-234', null, 'PE-1-234', null, 'nif20', 'ok'],
        ];
    }

    #[DataProvider('casosProvider')]
    public function test_normaliza(?string $nif20, ?string $cif, ?string $ruc, ?string $dv, string $origen, string $estado): void
    {
        $r = TaxIdNormalizer::normalize($nif20, $cif);

        $this->assertSame($ruc, $r->ruc, 'ruc');
        $this->assertSame($dv, $r->dv, 'dv');
        $this->assertSame($origen, $r->origen, 'origen');
        $this->assertSame($estado, $r->estado, 'estado');
    }

    public function test_nunca_inventa_un_dv_para_cedulas_ni_rucs_sin_marcador(): void
    {
        foreach (['6-712-881', '155716554-2-2021', '1671600-1-679822'] as $valor) {
            $this->assertNull(TaxIdNormalizer::normalize($valor, null)->dv, $valor);
        }
    }

    public function test_dv_siempre_es_solo_digitos_de_1_a_4(): void
    {
        foreach (['1DV1', '1DV12', '1 DV123', '1dv1234'] as $valor) {
            $dv = TaxIdNormalizer::normalize($valor, null)->dv;
            $this->assertNotNull($dv, $valor);
            $this->assertMatchesRegularExpression('/^\d{1,4}$/', $dv, $valor);
        }
    }
}
