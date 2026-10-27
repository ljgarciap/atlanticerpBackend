<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Servicios\Services\TicketTypes;
use App\Modules\VentasDiseno\Services\PipelineStages;
use App\Shared\Catalogs\CatalogCache;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 7c — preguntar por el comportamiento de una entrada dentro de un bucle (una fila de la lista de cotizaciones, una tarjeta del
 * forecast) no debe costar una consulta por pregunta, y una escritura del catálogo debe verse de inmediato.
 */
final class CatalogCacheTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_preguntar_muchas_veces_por_la_etapa_o_el_tipo_no_consulta_la_base_de_datos(): void
    {
        PipelineStages::linear(); // calienta la memo
        TicketTypes::slaDias('claim');

        DB::enableQueryLog();
        for ($i = 0; $i < 50; $i++) {
            PipelineStages::isAtOrAfter('approved', PipelineStages::PROPOSAL);
            PipelineStages::isWon('approved');
            PipelineStages::label('proposal');
            TicketTypes::slaDias('claim');
            TicketTypes::label('claim');
        }
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $consultas);
    }

    public function test_una_escritura_del_catalogo_invalida_la_memo(): void
    {
        $this->assertNotContains('negociacion', PipelineStages::linear());

        (new CatalogService)->create(PipelineStages::DOMAIN, ['key' => 'negociacion', 'label' => 'Negociación', 'sort_order' => 450]);
        $this->assertContains('negociacion', PipelineStages::linear());
        $this->assertSame('Negociación', PipelineStages::label('negociacion'));

        $entrada = CatalogService::entries(PipelineStages::DOMAIN)->firstWhere('key', 'negociacion');
        (new CatalogService)->update($entrada, ['label' => 'En negociación']);
        $this->assertSame('En negociación', PipelineStages::label('negociacion'));

        (new CatalogService)->delete(CatalogService::entries(PipelineStages::DOMAIN)->firstWhere('key', 'negociacion'));
        $this->assertNotContains('negociacion', PipelineStages::linear());
    }

    public function test_la_memo_es_por_peticion_y_se_puede_descartar(): void
    {
        $cache = app(CatalogCache::class);
        $primera = $cache->entries(PipelineStages::DOMAIN);
        $this->assertSame($primera, $cache->entries(PipelineStages::DOMAIN), 'misma colección: no vuelve a consultar');

        $cache->forget();
        $this->assertNotSame($primera, $cache->entries(PipelineStages::DOMAIN));
    }
}
