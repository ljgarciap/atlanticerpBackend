<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\CatalogPdfService;
use App\Shared\Catalogs\CatalogCache;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 9c — los campos de la ficha técnica estructurada son un catálogo por empresa (`technical_spec_field`), no una lista fija.
 */
class FichaTecnicaCatalogoFase9cTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, string>|null $spec @return list<array{label: string, value: string}> */
    private function formatSpecs(?array $spec): array
    {
        $method = new \ReflectionMethod(CatalogPdfService::class, 'formatSpecs');

        return $method->invoke(app(CatalogPdfService::class), $spec);
    }

    public function test_un_tenant_nuevo_trae_solo_campos_genericos_de_sistema(): void
    {
        $keys = CatalogEntry::query()->where('domain', 'technical_spec_field')->orderBy('sort_order')->pluck('key')->all();

        $this->assertSame(['dimensions', 'weight', 'material_finish', 'warranty', 'certifications'], $keys);
        $this->assertSame(0, CatalogEntry::query()->where('domain', 'technical_spec_field')->where('is_system', false)->count());
    }

    public function test_el_pdf_usa_etiquetas_y_orden_del_catalogo_y_conserva_claves_desconocidas(): void
    {
        app(CatalogService::class)->create('technical_spec_field', ['key' => 'capacidad', 'label' => 'Capacidad útil']);
        app(CatalogCache::class)->forget();

        $specs = $this->formatSpecs(['weight' => '2 kg', 'capacidad' => '10 L', 'clave_sin_entrada' => 'x', 'dimensions' => '1x1']);

        $this->assertSame([
            ['label' => 'Dimensiones', 'value' => '1x1'],
            ['label' => 'Peso', 'value' => '2 kg'],
            ['label' => 'Capacidad útil', 'value' => '10 L'],
            ['label' => 'Clave sin entrada', 'value' => 'x'],
        ], $specs);
    }

    public function test_un_campo_desactivado_no_se_imprime(): void
    {
        CatalogEntry::query()->where('domain', 'technical_spec_field')->where('key', 'weight')->update(['is_active' => false]);
        app(CatalogCache::class)->forget();

        $this->assertSame([['label' => 'Dimensiones', 'value' => '1x1']], $this->formatSpecs(['weight' => '2 kg', 'dimensions' => '1x1']));
    }

    public function test_un_campo_con_datos_no_se_puede_borrar(): void
    {
        $entry = app(CatalogService::class)->create('technical_spec_field', ['key' => 'capacidad', 'label' => 'Capacidad']);
        CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'P', 'description' => 'P', 'price_full' => 1, 'cost' => 1, 'stock_quantity' => 0, 'is_active' => true,
            'technical_spec' => ['capacidad' => '10 L'],
        ]);

        $this->expectException(\DomainException::class);
        app(CatalogService::class)->delete($entry);
    }
}
