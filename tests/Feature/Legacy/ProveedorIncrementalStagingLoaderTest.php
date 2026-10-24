<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Services\ProveedorIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class ProveedorIncrementalStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_primera_corrida_cuenta_todo_como_nuevo(): void
    {
        $loader = new ProveedorIncrementalStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['nuevos']);
        $this->assertDatabaseHas('atlantic_legacy.proveedores', ['icg_codproveedor' => 501, 'nombre' => 'Proveedor Uno']);
    }

    public function test_re_correr_sin_cambios_no_escribe_nada(): void
    {
        $loader = new ProveedorIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $proveedor = Proveedor::query()->where('icg_codproveedor', 501)->firstOrFail();
        $updatedAtAntes = $proveedor->updated_at;

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(0, $counts['nuevos']);
        $this->assertSame(0, $counts['cambiados']);
        $this->assertSame(1, $counts['sin_cambios']);

        $proveedor->refresh();
        $this->assertTrue($updatedAtAntes->equalTo($proveedor->updated_at));
    }

    public function test_un_cambio_real_se_detecta(): void
    {
        $loader = new ProveedorIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $cambiado = new ExtractedProveedores([
            new ProveedorRow(501, 'Proveedor Uno (renombrado)', null, null, null, null, null),
        ]);
        $counts = $loader->load($cambiado);

        $this->assertSame(1, $counts['cambiados']);
        $proveedor = Proveedor::query()->where('icg_codproveedor', 501)->firstOrFail();
        $this->assertSame('Proveedor Uno (renombrado)', $proveedor->nombre);
    }

    public function test_re_correr_nunca_resetea_provider_id_ya_seteado(): void
    {
        $loader = new ProveedorIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $proveedor = Proveedor::query()->where('icg_codproveedor', 501)->firstOrFail();
        $proveedor->match_status = Proveedor::MATCH_MATCHED;
        $proveedor->save();

        $loader->load(self::sampleExtraction());

        $proveedor->refresh();
        $this->assertSame(Proveedor::MATCH_MATCHED, $proveedor->match_status);
    }

    private static function sampleExtraction(): ExtractedProveedores
    {
        return new ExtractedProveedores([
            new ProveedorRow(501, 'Proveedor Uno', null, null, null, null, null),
        ]);
    }
}
