<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Services\PipelineStages;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 7c (docs/specs/fase7c-etapas-pipeline.md) — dominio `pipeline_stage`: la siembra reproduce el flujo de siempre (equivalencia con los
 * datos por defecto), una etapa propia se intercala entre Lead y Aprobado y siempre es «abierta», y las 6 de sistema no se reordenan
 * ni cambian de categoría (las compuertas, las comisiones y «pasar a pedido» dependen de ellas).
 */
final class PipelineStageCatalogTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function entry(string $key): CatalogEntry
    {
        return CatalogEntry::query()->where('domain', PipelineStages::DOMAIN)->where('key', $key)->firstOrFail();
    }

    public function test_la_siembra_reproduce_el_flujo_y_las_categorias_de_siempre(): void
    {
        $this->assertSame(['lead', 'design', 'quote', 'proposal', 'approved'], PipelineStages::linear());
        $this->assertSame(['lead', 'design', 'quote', 'proposal'], PipelineStages::open());
        $this->assertSame(['approved'], PipelineStages::won());
        $this->assertSame(['lost'], PipelineStages::lost());
        $this->assertSame(['lead', 'design', 'quote', 'proposal', 'approved', 'lost'], PipelineStages::keys());
        $this->assertSame('Aprobado', PipelineStages::label('approved'));
        $this->assertTrue(PipelineStages::isWon('approved'));
        $this->assertTrue(PipelineStages::isLost('lost'));
        $this->assertFalse(PipelineStages::isWon('proposal'));
    }

    public function test_la_posicion_en_el_flujo_responde_las_preguntas_de_las_compuertas(): void
    {
        $this->assertTrue(PipelineStages::isAtOrAfter('proposal', PipelineStages::PROPOSAL));
        $this->assertTrue(PipelineStages::isAtOrAfter('approved', PipelineStages::PROPOSAL));
        $this->assertFalse(PipelineStages::isAtOrAfter('quote', PipelineStages::PROPOSAL));
        $this->assertTrue(PipelineStages::isBefore('design', PipelineStages::QUOTE));
        $this->assertFalse(PipelineStages::isBefore('quote', PipelineStages::QUOTE));
        $this->assertNull(PipelineStages::index('lost'), 'la perdida no está en el flujo lineal');
        $this->assertFalse(PipelineStages::isAtOrAfter('lost', PipelineStages::PROPOSAL));
    }

    public function test_una_etapa_propia_sin_orden_se_intercala_entre_propuesta_y_aprobado(): void
    {
        (new CatalogService)->create(PipelineStages::DOMAIN, ['key' => 'negociacion', 'label' => 'Negociación']);

        $this->assertSame(['lead', 'design', 'quote', 'proposal', 'negociacion', 'approved'], PipelineStages::linear());
        $this->assertContains('negociacion', PipelineStages::open(), 'una etapa propia es siempre abierta');
        $this->assertTrue(PipelineStages::isAtOrAfter('negociacion', PipelineStages::PROPOSAL));
        $this->assertTrue(PipelineStages::isBefore('negociacion', PipelineStages::APPROVED));
        $this->assertFalse(PipelineStages::isWon('negociacion'));
    }

    public function test_una_etapa_propia_puede_ir_entre_diseno_y_cotizacion(): void
    {
        (new CatalogService)->create(PipelineStages::DOMAIN, ['key' => 'visita', 'label' => 'Visita técnica', 'sort_order' => 250]);

        $this->assertSame(['lead', 'design', 'visita', 'quote', 'proposal', 'approved'], PipelineStages::linear());
        $this->assertTrue(PipelineStages::isBefore('visita', PipelineStages::QUOTE));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function etapasPropiasInvalidas(): array
    {
        return [
            'categoría ganada' => [['sort_order' => 250, 'props' => ['categoria' => 'ganada']]],
            'categoría perdida' => [['sort_order' => 250, 'props' => ['categoria' => 'perdida']]],
            'antes de Lead' => [['sort_order' => 50]],
            'justo en Lead' => [['sort_order' => 100]],
            'después de Aprobado' => [['sort_order' => 600]],
            'justo en Aprobado' => [['sort_order' => 500]],
            'orden de una etapa de sistema' => [['sort_order' => 200]],
            'color que no es hex' => [['sort_order' => 250, 'props' => ['color' => 'rojo']]],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    #[DataProvider('etapasPropiasInvalidas')]
    public function test_una_etapa_propia_invalida_se_rechaza(array $extra): void
    {
        $this->expectException(ValidationException::class);

        (new CatalogService)->create(PipelineStages::DOMAIN, ['key' => 'x', 'label' => 'X'] + $extra);
    }

    public function test_las_etapas_de_sistema_se_renombran_y_se_colorean_pero_no_se_reordenan_ni_cambian_de_categoria(): void
    {
        $svc = new CatalogService;
        $approved = $this->entry('approved');

        $svc->update($approved, ['label' => 'Cerrado ganado', 'props' => ['categoria' => 'ganada', 'color' => '#10b981']]);
        $this->assertSame('Cerrado ganado', PipelineStages::label('approved'));
        $this->assertTrue(PipelineStages::isWon('approved'), 'renombrar no cambia la categoría');

        foreach ([
            ['sort_order' => 450],
            ['props' => ['categoria' => 'abierta']],
            ['props' => ['color' => '#10b981']], // sin `categoria`: no se puede perder la categoría al guardar
        ] as $cambio) {
            try {
                $svc->update($this->entry('approved'), $cambio);
                $this->fail('Debió rechazar: '.json_encode($cambio));
            } catch (ValidationException) {
                // esperado
            }
        }
        $this->assertTrue(PipelineStages::isWon('approved'));
        $this->assertSame(['lead', 'design', 'quote', 'proposal', 'approved'], PipelineStages::linear());
    }

    public function test_una_etapa_de_sistema_no_se_desactiva_ni_se_borra(): void
    {
        $this->expectException(\DomainException::class);

        (new CatalogService)->update($this->entry('lost'), ['is_active' => false]);
    }

    public function test_la_columna_stage_ya_no_es_un_enum_cerrado(): void
    {
        $schema = Tenant::current()?->slug.'_ventas_diseno';
        $tipo = DB::scalar("select data_type from information_schema.columns where table_schema = ? and table_name = 'pipeline_cards' and column_name = 'stage'", [$schema]);
        $check = DB::scalar("select count(*) from pg_constraint where conname = 'pipeline_cards_stage_check' and connamespace = (select oid from pg_namespace where nspname = ?)", [$schema]);

        $this->assertSame('character varying', $tipo);
        $this->assertSame(0, (int) $check);
    }
}
