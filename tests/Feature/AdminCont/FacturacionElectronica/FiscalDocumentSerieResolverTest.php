<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentSerieResolver;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-941 "Abierto #2" — serie configurable por tenant, NC deriva con prefijo `NC-`. */
final class FiscalDocumentSerieResolverTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_usa_el_default_ilu_sembrado_por_la_migracion(): void
    {
        $resolver = new FiscalDocumentSerieResolver();

        $this->assertSame('ILU', $resolver->paraFactura());
        $this->assertSame('NC-ILU', $resolver->paraNotaCredito());
    }

    public function test_deriva_de_un_serie_fiscal_configurado_distinto(): void
    {
        AdminContFiscalSettings::current()->forceFill(['serie_fiscal' => 'OTRA'])->save();

        $resolver = new FiscalDocumentSerieResolver();

        $this->assertSame('OTRA', $resolver->paraFactura());
        $this->assertSame('NC-OTRA', $resolver->paraNotaCredito());
    }
}
