<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Fiscal\TaxId\ColombiaTaxIdRules;
use App\Shared\Fiscal\TaxId\GenericTaxIdRules;
use App\Shared\Fiscal\TaxId\PanamaTaxIdRules;
use App\Shared\Fiscal\TaxId\TaxIdRulesRegistry;
use App\Shared\Fiscal\TaxId\UnitedStatesTaxIdRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 6c (docs/specs/fase6-fiscal-por-pais.md) — reglas locales de identificación fiscal por país: formato y dígito verificador.
 */
final class TaxIdCountryRulesTest extends TestCase
{
    public function test_el_registro_resuelve_las_reglas_de_cada_pais_y_cae_en_las_genericas(): void
    {
        $this->assertInstanceOf(PanamaTaxIdRules::class, TaxIdRulesRegistry::forCountry('PA'));
        $this->assertInstanceOf(ColombiaTaxIdRules::class, TaxIdRulesRegistry::forCountry('co'));
        $this->assertInstanceOf(UnitedStatesTaxIdRules::class, TaxIdRulesRegistry::forCountry('US'));
        $this->assertInstanceOf(GenericTaxIdRules::class, TaxIdRulesRegistry::forCountry('XX'));
    }

    public function test_describe_dice_al_frontend_como_armar_el_formulario(): void
    {
        $this->assertSame(['label' => 'RUC', 'uses_dv' => true, 'dv_max_length' => 2], TaxIdRulesRegistry::forCountry('PA')->describe());
        $this->assertSame(['label' => 'NIT', 'uses_dv' => true, 'dv_max_length' => 1], TaxIdRulesRegistry::forCountry('CO')->describe());
        $this->assertSame(['label' => 'EIN / Tax ID', 'uses_dv' => false, 'dv_max_length' => 0], TaxIdRulesRegistry::forCountry('US')->describe());
    }

    // ── Panamá: solo el DV (cero cambio respecto a antes de la 6c) ──

    public function test_panama_no_restringe_la_identificacion_y_exige_un_dv_de_1_o_2_digitos(): void
    {
        $r = new PanamaTaxIdRules;
        foreach (['8-123-4567', 'PE-1-234', '155704849-2-2021', 'cualquier cosa'] as $id) {
            $this->assertNull($r->validate($id, '12'), $id);
        }
        $this->assertNull($r->validate('8-123-4567', null));
        $this->assertNull($r->validate('8-123-4567', ''));
        $this->assertSame('El DV debe tener 1 o 2 dígitos.', $r->validate('8-123-4567', '123'));
        $this->assertSame('El DV debe tener 1 o 2 dígitos.', $r->validate('8-123-4567', 'AB'));
    }

    // ── Colombia: NIT con DV por módulo 11 ──

    /** @return array<string, array{string, int}> NIT reales publicados por la DIAN y su DV */
    public static function nitsReales(): array
    {
        return [
            'Universidad Nacional' => ['899999063', 3],
            'DIAN' => ['800197268', 4],
        ];
    }

    #[DataProvider('nitsReales')]
    public function test_colombia_calcula_el_dv_del_nit_por_modulo_11(string $nit, int $dv): void
    {
        $r = new ColombiaTaxIdRules;

        $this->assertSame($dv, $r->calcularDv($nit));
        $this->assertNull($r->validate($nit, (string) $dv));
        $this->assertNull($r->validate(substr($nit, 0, 3).'.'.substr($nit, 3, 3).'.'.substr($nit, 6), (string) $dv), 'con puntos');
        $this->assertNull($r->validate($nit, null), 'el DV es opcional al capturar');
    }

    public function test_colombia_rechaza_un_dv_que_no_corresponde_y_formatos_invalidos(): void
    {
        $r = new ColombiaTaxIdRules;

        $this->assertStringContainsString('debería ser 3', (string) $r->validate('899999063', '9'));
        $this->assertSame('El DV del NIT debe ser un solo dígito.', $r->validate('899999063', '33'));
        $this->assertStringContainsString('entre 6 y 10 dígitos', (string) $r->validate('12345', '1'));
        $this->assertStringContainsString('entre 6 y 10 dígitos', (string) $r->validate('ABC123456', '1'));
        $this->assertStringContainsString('entre 6 y 10 dígitos', (string) $r->validate('12345678901', '1'));
    }

    // ── Estados Unidos: EIN / SSN, sin DV ──

    public function test_estados_unidos_acepta_ein_y_ssn_y_rechaza_el_resto(): void
    {
        $r = new UnitedStatesTaxIdRules;

        foreach (['12-3456789', '123456789', '123-45-6789'] as $id) {
            $this->assertNull($r->validate($id, null), $id);
        }
        foreach (['12-345678', '1234567890', 'AB-1234567', '155704849-2-2021'] as $id) {
            $this->assertNotNull($r->validate($id, null), $id);
        }
    }

    public function test_estados_unidos_no_admite_digito_verificador(): void
    {
        $this->assertNotNull((new UnitedStatesTaxIdRules)->validate('12-3456789', '5'));
        $this->assertNull((new UnitedStatesTaxIdRules)->validate('12-3456789', ''));
    }

    public function test_pais_generico_acepta_alfanumerico_sin_dv(): void
    {
        $r = new GenericTaxIdRules;

        $this->assertNull($r->validate('CHE-123.456.789', null));
        $this->assertNotNull($r->validate('AB', null));
        $this->assertNotNull($r->validate('<script>', null));
        $this->assertNotNull($r->validate('123456', '1'));
    }
}
