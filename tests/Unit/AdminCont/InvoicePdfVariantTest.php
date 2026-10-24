<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\Support\InvoicePdfVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-1156 (T1) — el selector de variante del PDF de factura es una función pura: cada rama se prueba sola, sin BD ni
 * Laravel. Casos de la sección 6 del diseño: un caso por estado fiscal, `cufe` ausente del enlace, host no permitido, esquema
 * que no es `https`, `gd` ausente y ambiente de pruebas o desconocido.
 */
final class InvoicePdfVariantTest extends TestCase
{
    private const CUFE = 'FE0120000155704849-2-2021-261179609251609600073000000000000000000000';
    private const ALLOWED = ['dgi-fep.mef.gob.pa', 'dgi-fep-test.mef.gob.pa'];
    private const PRODUCTION = ['dgi-fep.mef.gob.pa'];

    private static function qr(string $host = 'dgi-fep-test.mef.gob.pa:40001', string $cufe = self::CUFE, string $scheme = 'https'): string
    {
        return "{$scheme}://{$host}/Consultas/FacturasPorCUFE?chFE={$cufe}&iAmb=2&digestValue=abc%2B123";
    }

    public function test_certificada_con_datos_integros_es_fiscal_con_enlace_y_clave(): void
    {
        $r = InvoicePdfVariant::resolve('certificado', self::CUFE, self::qr(), true, self::ALLOWED);

        $this->assertTrue($r->isFiscal());
        $this->assertSame(InvoicePdfVariant::FISCAL, $r->variante);
        $this->assertNull($r->motivo);
        $this->assertSame(self::CUFE, $r->clave);
        // Sin query ni fragmento; el puerto no estándar del ambiente de pruebas se conserva.
        $this->assertSame('https://dgi-fep-test.mef.gob.pa:40001/Consultas/FacturasPorCUFE', $r->consultaUrl);
    }

    public function test_el_enlace_de_produccion_no_imprime_el_puerto_443(): void
    {
        $r = InvoicePdfVariant::resolve('certificado', self::CUFE, self::qr('dgi-fep.mef.gob.pa:443'), true, self::ALLOWED);

        $this->assertTrue($r->isFiscal());
        $this->assertSame('https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE', $r->consultaUrl);
    }

    public function test_un_espacio_o_salto_de_linea_al_final_del_enlace_guardado_se_tolera(): void
    {
        $r = InvoicePdfVariant::resolve('certificado', self::CUFE, self::qr()."\n", true, self::ALLOWED);

        $this->assertTrue($r->isFiscal());
    }

    public function test_el_host_se_compara_sin_distinguir_mayusculas(): void
    {
        $r = InvoicePdfVariant::resolve('certificado', self::CUFE, self::qr('DGI-FEP.MEF.GOB.PA'), true, self::ALLOWED);

        $this->assertTrue($r->isFiscal());
        $this->assertSame('https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE', $r->consultaUrl);
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function estadosSinCertificar(): array
    {
        return [
            'pendiente'           => ['pendiente', InvoicePdfVariant::MOTIVO_PENDIENTE],
            'rechazado'           => ['rechazado', InvoicePdfVariant::MOTIVO_RECHAZADA],
            'en espera de NC'     => ['en_espera_nc', InvoicePdfVariant::MOTIVO_EN_ESPERA_NC],
            'error de config.'    => ['error_configuracion', InvoicePdfVariant::MOTIVO_NO_APLICADA],
            'no aplica'           => ['no_aplica', InvoicePdfVariant::MOTIVO_NO_APLICADA],
            'sin estado (legada)' => [null, InvoicePdfVariant::MOTIVO_LEGADA],
            'estado vacío'        => ['', InvoicePdfVariant::MOTIVO_LEGADA],
            'estado desconocido'  => ['otro', InvoicePdfVariant::MOTIVO_DATOS_INCOMPLETOS],
        ];
    }

    #[DataProvider('estadosSinCertificar')]
    public function test_un_estado_sin_certificar_es_sin_validez_con_su_motivo_aunque_traiga_cufe_y_qr(?string $estado, string $motivo): void
    {
        $r = InvoicePdfVariant::resolve($estado, self::CUFE, self::qr(), true, self::ALLOWED);

        $this->assertFalse($r->isFiscal());
        $this->assertSame(InvoicePdfVariant::SIN_VALIDEZ, $r->variante);
        $this->assertSame($motivo, $r->motivo);
        $this->assertNull($r->consultaUrl);
        $this->assertNull($r->clave);
    }

    /** @return array<string, array{0: string|null, 1: string|null, 2: bool}> */
    public static function certificadaConDatosInvalidos(): array
    {
        return [
            'cufe ausente'                    => [null, self::qr(), true],
            'cufe vacío'                      => ['  ', self::qr(), true],
            'qr ausente'                      => [self::CUFE, null, true],
            'qr vacío'                        => [self::CUFE, '  ', true],
            'cufe ausente del enlace (chFE)'  => [self::CUFE, self::qr(cufe: 'FE0000000000000-9'), true],
            'chFE es prefijo del cufe'        => [self::CUFE, self::qr(cufe: substr(self::CUFE, 0, 30)), true],
            'chFE repetido, el último es el cufe' => [self::CUFE, 'https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?chFE=FE0000-9&chFE='.self::CUFE, true],
            'chFE repetido, el primero es el cufe' => [self::CUFE, 'https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?chFE='.self::CUFE.'&chFE=FE0000-9', true],
            'chFE repetido con otra capitalización' => [self::CUFE, 'https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?chFE='.self::CUFE.'&CHFE='.self::CUFE, true],
            'enlace sin chFE'                 => [self::CUFE, 'https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?x=1', true],
            'host no permitido'               => [self::CUFE, self::qr('evil.example.com'), true],
            'subdominio engañoso'             => [self::CUFE, self::qr('dgi-fep.mef.gob.pa.evil.example.com'), true],
            'esquema http'                    => [self::CUFE, self::qr(scheme: 'http'), true],
            'credenciales en el enlace'       => [self::CUFE, self::qr('dgi-fep.mef.gob.pa@evil.example.com'), true],
            'usuario y host permitido'        => [self::CUFE, self::qr('user:pw@dgi-fep.mef.gob.pa'), true],
            'qr que no es un enlace'          => [self::CUFE, 'no-es-un-enlace', true],
            'backslash que engaña al navegador' => [self::CUFE, 'https://dgi-fep.mef.gob.pa\\@evil.example.com/Consultas/FacturasPorCUFE?chFE='.self::CUFE, true],
            'espacio dentro del enlace'       => [self::CUFE, 'https://dgi-fep.mef.gob.pa/Consul tas?chFE='.self::CUFE, true],
            'tabulación dentro del enlace'    => [self::CUFE, "https://dgi-fep.mef.gob.pa/Consultas?chFE=".self::CUFE."\t&x=1", true],
            'salto de línea dentro'           => [self::CUFE, "https://dgi-fep.mef.gob.pa/\nConsultas?chFE=".self::CUFE, true],
            'gd ausente'                      => [self::CUFE, self::qr(), false],
        ];
    }

    #[DataProvider('certificadaConDatosInvalidos')]
    public function test_certificada_con_datos_invalidos_cae_a_sin_validez_por_datos_incompletos(?string $cufe, ?string $qr, bool $gd): void
    {
        $r = InvoicePdfVariant::resolve('certificado', $cufe, $qr, $gd, self::ALLOWED);

        $this->assertFalse($r->isFiscal());
        $this->assertSame(InvoicePdfVariant::MOTIVO_DATOS_INCOMPLETOS, $r->motivo);
        $this->assertNull($r->consultaUrl);
        $this->assertNull($r->clave);
    }

    public function test_sin_hosts_permitidos_ninguna_certificada_es_fiscal(): void
    {
        $r = InvoicePdfVariant::resolve('certificado', self::CUFE, self::qr(), true, []);

        $this->assertFalse($r->isFiscal());
    }

    /** @return array<string, array{0: string|null, 1: string|null, 2: bool}> */
    public static function ambientes(): array
    {
        return [
            'produccion con host de producción'          => ['produccion', self::qr('dgi-fep.mef.gob.pa'), false],
            'production con host de producción'          => ['production', self::qr('dgi-fep.mef.gob.pa'), false],
            'produccion pero enlace del host de pruebas' => ['produccion', self::qr(), true],
            'production pero enlace del host de pruebas' => ['production', self::qr('dgi-fep-test.mef.gob.pa'), true],
            'pruebas explícito'                          => ['pruebas', self::qr('dgi-fep.mef.gob.pa'), true],
            'valor desconocido es pruebas'               => ['staging', self::qr('dgi-fep.mef.gob.pa'), true],
            'produccion con espacios'                    => [' produccion ', self::qr('dgi-fep.mef.gob.pa'), false],
            'Produccion con mayúscula es desconocido'    => ['Produccion', self::qr('dgi-fep.mef.gob.pa'), true],
            'sin ambiente, host de producción'           => [null, self::qr('dgi-fep.mef.gob.pa'), false],
            'sin ambiente, host de producción con puerto' => ['', self::qr('dgi-fep.mef.gob.pa:443'), false],
            'sin ambiente, host de pruebas'              => [null, self::qr(), true],
            'sin ambiente, host desconocido'             => [null, self::qr('evil.example.com'), true],
            'sin ambiente ni enlace'                     => [null, null, true],
            'sin ambiente, enlace roto'                  => [null, 'no-es-un-enlace', true],
        ];
    }

    #[DataProvider('ambientes')]
    public function test_ambiente_de_pruebas(?string $ambiente, ?string $qr, bool $esperado): void
    {
        $this->assertSame($esperado, InvoicePdfVariant::isTestEnvironment($ambiente, $qr, self::PRODUCTION));
    }

    public function test_la_config_trae_los_hosts_dgi_y_produccion_es_subconjunto(): void
    {
        $config = require dirname(__DIR__, 3).'/config/fiscal-pdf.php';

        $this->assertContains('dgi-fep.mef.gob.pa', $config['allowed_hosts']);
        $this->assertContains('dgi-fep-test.mef.gob.pa', $config['allowed_hosts']);
        $this->assertSame([], array_diff($config['production_hosts'], $config['allowed_hosts']));
        $this->assertNotContains('dgi-fep-test.mef.gob.pa', $config['production_hosts']);
    }
}
