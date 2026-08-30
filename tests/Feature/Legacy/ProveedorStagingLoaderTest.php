<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Services\ProveedorStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-756 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal. Sin Matcher, sin Loader-B — el pipeline de esta entidad termina en `legacy.proveedores`.
 */
final class ProveedorStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_load_stagea_proveedores(): void
    {
        $loader = new ProveedorStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(['proveedores' => 1], $counts);
        $this->assertDatabaseHas('atlantic_legacy.proveedores', [
            'icg_codproveedor' => 55,
            'nombre'           => 'Iluminación Panamá S.A.',
            'nombre_comercial' => 'Ilumipan',
        ]);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica(): void
    {
        $loader = new ProveedorStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Proveedor::query()->where('icg_codproveedor', 55)->count());
    }

    public function test_re_correr_actualiza_valores_cambiados(): void
    {
        $loader = new ProveedorStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedProveedores([
            new ProveedorRow(55, 'Iluminación Panamá S.A. (renombrada)', 'Ilumipan', '1234567-8-901234', 'Calle 50', '507-1234567', 'ventas@ilumipan.com'),
        ]);
        $loader->load($updated);

        $proveedor = Proveedor::query()->where('icg_codproveedor', 55)->firstOrFail();
        $this->assertSame('Iluminación Panamá S.A. (renombrada)', $proveedor->nombre);
    }

    /**
     * Regresión — mismo patrón que `ProductoStagingLoaderTest`/`ClienteStagingLoaderTest`: el
     * chunking (CHUNK_SIZE=500) no debe perder ni duplicar filas en el borde entre chunks, aunque
     * con 114 filas reales (§2 del diseño) este batch nunca active el chunking de verdad.
     */
    public function test_load_con_mas_filas_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $proveedores = [];
        for ($i = 1; $i <= 600; $i++) {
            $proveedores[] = new ProveedorRow($i, "Proveedor {$i}", null, null, null, null, null);
        }

        $loader = new ProveedorStagingLoader();
        $counts = $loader->load(new ExtractedProveedores($proveedores));

        $this->assertSame(['proveedores' => 600], $counts);
        $this->assertSame(600, Proveedor::query()->count());
        $this->assertDatabaseHas('atlantic_legacy.proveedores', ['icg_codproveedor' => 1, 'nombre' => 'Proveedor 1']);
        $this->assertDatabaseHas('atlantic_legacy.proveedores', ['icg_codproveedor' => 600, 'nombre' => 'Proveedor 600']);
    }

    private static function sampleExtraction(): ExtractedProveedores
    {
        return new ExtractedProveedores([
            new ProveedorRow(55, 'Iluminación Panamá S.A.', 'Ilumipan', '1234567-8-901234', 'Calle 50, Ciudad de Panamá', '507-1234567', 'ventas@ilumipan.com'),
        ]);
    }
}
