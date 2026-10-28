<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AI\Support\BusinessProfile;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4c — los prompts de IA no fijan el sector: la descripción sale de «Datos de la Empresa» del tenant.
 */
class BusinessProfileTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_sin_descripcion_los_prompts_dicen_una_empresa(): void
    {
        AdminContCompanyProfile::current()->update(['descripcion_corta' => '']);

        $this->assertSame('una empresa', BusinessProfile::description());
        $this->assertSame('Asistente de una empresa.', BusinessProfile::fill('Asistente de {negocio}.'));
    }

    public function test_con_descripcion_el_prompt_la_incluye_acotada(): void
    {
        AdminContCompanyProfile::current()->update(['descripcion_corta' => 'distribución de repuestos']);

        $this->assertSame('Asistente de distribución de repuestos.', BusinessProfile::fill('Asistente de {negocio}.'));
        $this->assertSame('x', BusinessProfile::fill('x'));
    }

    public function test_ningun_prompt_de_ia_fija_un_sector(): void
    {
        $archivos = [
            'app/Modules/AI/Jobs/SummarizeProjectJob.php', 'app/Modules/AI/Jobs/DraftMessageJob.php', 'app/Modules/AI/Jobs/MonthlyAnalysisJob.php',
            'app/Modules/AI/Jobs/SuggestActionsJob.php', 'app/Modules/Auth/Jobs/SecurityAnalysisJob.php',
        ];
        foreach ($archivos as $rel) {
            $this->assertStringContainsString(BusinessProfile::PLACEHOLDER, (string) file_get_contents(base_path($rel)), $rel);
            $this->assertDoesNotMatchRegularExpression('/ilumin/i', (string) preg_replace('/^\s*(\/\*\*|\*|\/\/).*$/m', '', (string) file_get_contents(base_path($rel))), $rel);
        }
    }
}
