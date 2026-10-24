<?php

declare(strict_types=1);

namespace Tests\Unit\Servicios;

use App\Modules\Servicios\Services\ServiceQuotePdfLayoutEstimator;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-824 — cobertura pura de ServiceQuotePdfLayoutEstimator, sin base de datos ni render real
 * de PDF (mismo criterio que QuotePdfLayoutEstimatorTest, SCRUM-801). Ver docblock de la clase
 * para el diseño; acá solo se verifica el comportamiento observable.
 */
class ServiceQuotePdfLayoutEstimatorTest extends TestCase
{
    private ServiceQuotePdfLayoutEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new ServiceQuotePdfLayoutEstimator();
    }

    private function shortQuote(): array
    {
        return [
            'items' => [
                ['description' => 'Instalación de spot', 'unit_price' => 25],
            ],
            'observations' => 'Cliente pidió reprogramar visita.',
            'conditions_text' => "1. Condición corta.\n2. Otra.",
            'firmada' => false,
        ];
    }

    // 380 repeticiones determinado empíricamente (mismo criterio que QuotePdfLayoutEstimatorTest,
    // SCRUM-801) — el residuo del cursor tras el wrap de esta fila cae dentro de un margen amplio
    // (360-400 repeticiones siguen forzando página nueva) por debajo del alto del bloque final.
    private function longQuote(int $items = 1, int $repeats = 380): array
    {
        $hugeDescription = trim(str_repeat('palabra larga de relleno ', $repeats));
        $itemsList = [];
        for ($i = 1; $i <= $items; $i++) {
            $itemsList[] = ['description' => $hugeDescription, 'unit_price' => 25];
        }

        return [
            'items' => $itemsList,
            'observations' => 'Observación con más texto para ocupar más de una línea completa dentro del bloque final.',
            'conditions_text' => "1. Condición larga que se envuelve.\n2. Otra condición larga que también se envuelve en varias líneas.\n3. Tercera condición.",
            'firmada' => true,
        ];
    }

    public function test_cotizacion_corta_entra_en_la_pagina_actual_con_espaciador_positivo(): void
    {
        $result = $this->estimator->estimate($this->shortQuote(), external: false);

        $this->assertFalse($result['forceNewPage']);
        $this->assertGreaterThan(0.0, $result['spacerPt']);
    }

    public function test_cotizacion_larga_fuerza_pagina_nueva_para_el_bloque_final(): void
    {
        $result = $this->estimator->estimate($this->longQuote(), external: false);

        $this->assertTrue($result['forceNewPage']);
        $this->assertGreaterThanOrEqual(0.0, $result['spacerPt']);
    }

    public function test_espaciador_nunca_es_negativo(): void
    {
        $result = $this->estimator->estimate($this->longQuote(items: 1, repeats: 5), external: false);

        $this->assertGreaterThanOrEqual(0.0, $result['spacerPt']);
    }

    public function test_external_no_afecta_el_calculo_servicios_no_tiene_vista_externa(): void
    {
        // A diferencia de VentasDiseno (SCRUM-140, bloque final oculto en la vista externa),
        // Servicios no tiene vista externa — external=true no debe anclar spacer en 0 a secas.
        $short = $this->estimator->estimate($this->shortQuote(), external: false);
        $externalIgnored = $this->estimator->estimate($this->shortQuote(), external: true);

        $this->assertSame($short, $externalIgnored);
    }
}
