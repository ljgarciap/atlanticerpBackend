<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 26b — la migración quita `ventas_diseno.pipeline.edit` solo al rol de Contabilidad y es idempotente. */
class RetirePipelineEditFromContabilidadMigrationTest extends FeatureTestCase
{
    public function test_quita_el_permiso_a_contabilidad_y_no_a_otras_personas(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $contabilidad = User::where('email', 'contabilidad@test.com')->firstOrFail();
        $otra = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $contabilidad->update(['extra_permissions' => ['ventas_diseno.pipeline.edit', 'ventas_diseno.pipeline.read_all']]);
        $otra->update(['extra_permissions' => ['ventas_diseno.pipeline.edit']]);

        $migracion = require base_path('database/migrations/tenant/2026_11_17_100000_retire_pipeline_edit_from_contabilidad.php');
        $migracion->up();
        $migracion->up();

        $this->assertSame(['ventas_diseno.pipeline.read_all'], $contabilidad->fresh()->extra_permissions);
        $this->assertSame(['ventas_diseno.pipeline.edit'], $otra->fresh()->extra_permissions);
    }
}
