<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Services\PipelineStages;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 7c-1 — una etapa propia intercalada en el flujo lineal hereda las compuertas de las etapas de sistema que la rodean (las
 * compuertas cuelgan de la etapa de DESTINO) y las 6 de sistema siguen exactamente como antes.
 */
final class PipelineCustomStageFlowTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function etapa(string $key, int $orden): void
    {
        (new CatalogService)->create(PipelineStages::DOMAIN, ['key' => $key, 'label' => ucfirst($key), 'sort_order' => $orden]);
    }

    private function gate(string $desde, string $hacia): ?string
    {
        return (new PipelineCard(['stage' => $desde]))->validationErrorForTransitionTo($hacia);
    }

    public function test_el_flujo_de_sistema_conserva_sus_compuertas(): void
    {
        $this->assertStringContainsString('saltear', (string) $this->gate('lead', 'quote'));
        $this->assertStringContainsString('contacto', (string) $this->gate('lead', 'design'));
        $this->assertStringContainsString('cotización real', (string) $this->gate('design', 'quote'));
        $this->assertStringContainsString('superficie', (string) $this->gate('quote', 'proposal'));
        $this->assertStringContainsString('fecha de entrega', (string) $this->gate('proposal', 'approved'));
        $this->assertNull($this->gate('design', 'lost'));
        $this->assertNull($this->gate('lost', 'lead'));
        $this->assertStringContainsString('Perdido', (string) $this->gate('lost', 'design'));
        $this->assertStringContainsString('final', (string) $this->gate('approved', 'lost'));
    }

    public function test_una_etapa_entre_propuesta_y_aprobado_hereda_la_compuerta_de_aprobar(): void
    {
        $this->etapa('negociacion', 450);

        $this->assertNull($this->gate('proposal', 'negociacion'), 'entrar a una etapa propia no tiene compuerta propia');
        $this->assertStringContainsString('saltear', (string) $this->gate('proposal', 'approved'), 'ya no se puede ir de Propuesta directo a Aprobado');
        $this->assertStringContainsString('fecha de entrega', (string) $this->gate('negociacion', 'approved'), 'aprobar exige lo mismo que antes');
    }

    public function test_una_etapa_entre_diseno_y_cotizacion_no_permite_llegar_a_cotizacion_sin_cotizar(): void
    {
        $this->etapa('visita', 250);

        $this->assertNull($this->gate('design', 'visita'));
        $this->assertStringContainsString('cotización real', (string) $this->gate('visita', 'quote'), 'a Cotización solo se llega generando una cotización');
        $this->assertStringContainsString('saltear', (string) $this->gate('design', 'quote'));
    }

    public function test_salir_de_lead_hacia_una_etapa_propia_sigue_pidiendo_contacto_y_cliente(): void
    {
        $this->etapa('precalificacion', 150);

        $this->assertStringContainsString('contacto', (string) $this->gate('lead', 'precalificacion'));
    }

    public function test_una_etapa_propia_puede_marcarse_perdida_como_cualquier_etapa_abierta(): void
    {
        $this->etapa('negociacion', 450);

        $this->assertNull($this->gate('negociacion', 'lost'));
    }

    public function test_las_propuestas_pendientes_incluyen_las_etapas_propias_posteriores_a_propuesta(): void
    {
        $this->etapa('negociacion', 450);
        $this->etapa('visita', 250);

        $this->assertSame(['proposal', 'negociacion'], PipelineStages::openAtOrAfter(PipelineStages::PROPOSAL));
        $this->assertSame(['lead', 'design', 'visita', 'quote', 'proposal', 'negociacion'], PipelineStages::open());
    }

    public function test_una_etapa_propia_hereda_la_probabilidad_de_la_ultima_etapa_con_probabilidad_que_la_precede(): void
    {
        $this->etapa('negociacion', 450);
        $this->etapa('visita', 250);
        $anclas = [PipelineStages::QUOTE, PipelineStages::PROPOSAL];

        $this->assertNull(PipelineStages::anchorOf('design', $anclas));
        $this->assertNull(PipelineStages::anchorOf('visita', $anclas), 'antes de Cotización no hay probabilidad (igual que Diseño)');
        $this->assertSame('quote', PipelineStages::anchorOf('quote', $anclas));
        $this->assertSame('proposal', PipelineStages::anchorOf('negociacion', $anclas));
        $this->assertNull(PipelineStages::anchorOf('lost', $anclas));
    }

    public function test_cotizar_desde_una_etapa_propia_anterior_a_cotizacion_avanza_la_tarjeta(): void
    {
        $this->etapa('visita', 250);

        $this->assertTrue(PipelineStages::isBefore('visita', PipelineStages::QUOTE));
        $this->assertFalse(PipelineStages::isBefore('negociacion', PipelineStages::QUOTE));
    }
}
