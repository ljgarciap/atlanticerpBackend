<?php

declare(strict_types=1);

namespace Tests\Unit\VentasDiseno;

use App\Modules\VentasDiseno\Services\QuotePdfLayoutEstimator;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-801 (3er rebote, 2026-09-01) — cobertura pura de QuotePdfLayoutEstimator, sin base de
 * datos ni render real de PDF (mismo criterio que el resto de tests/Unit). Ver docblock de la
 * clase para el diseño completo; acá solo se verifica el comportamiento observable: la vista
 * externa nunca ancla nada, una cotización corta entra en la página actual con espaciador > 0, y
 * una cotización larga fuerza página nueva — sin afirmar valores exactos en puntos (la precisión
 * pixel-perfect no es el contrato, page-break-inside:avoid en la vista es la garantía dura, no
 * esta clase).
 */
class QuotePdfLayoutEstimatorTest extends TestCase
{
    private QuotePdfLayoutEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new QuotePdfLayoutEstimator();
    }

    private function shortQuote(): array
    {
        return [
            'contacts' => [['name' => 'Alicia', 'phone' => '123', 'email' => 'a@test.com']],
            'observations_preview' => 'Obs corta',
            'conditions_text' => "1. Condicion corta.\n2. Otra.",
            'discount_totals_amount' => 0,
            'document_status' => 'draft',
            'parts' => [
                [
                    'name' => 'L1',
                    'items' => [
                        ['description' => 'Item 1', 'photo_url' => null],
                        ['description' => 'Item 2', 'photo_url' => null],
                    ],
                    'subtotal' => 100,
                ],
            ],
        ];
    }

    /**
     * Un solo ítem con una descripción enorme (se envuelve a decenas de líneas) — más confiable
     * que sumar muchos ítems "normales" para forzar el caso "no entra en la página actual": el
     * espacio remanente en la última página de la tabla de items depende del RESIDUO del total
     * acumulado contra el alto de página, no solo del volumen total, así que apilar más filas de
     * altura similar no garantiza determinísticamente un residuo chico. Una sola fila que por sí
     * sola consume la mayor parte del presupuesto de la página sí lo garantiza.
     */
    private function longQuote(int $parts = 1, int $itemsPerPart = 1): array
    {
        // 330 repeticiones fue determinado empíricamente (ver debug en la sesión de
        // implementación): el residuo del cursor tras el wrap de esta fila cae dentro de un
        // margen amplio (325-350 repeticiones siguen forzando página nueva) por debajo del alto
        // del bloque final — no es un valor mágico frágil, hay ~25 repeticiones de aire alrededor.
        $hugeDescription = trim(str_repeat('palabra larga de relleno ', 330));

        $partsData = [];
        for ($p = 1; $p <= $parts; $p++) {
            $items = [];
            for ($i = 1; $i <= $itemsPerPart; $i++) {
                $items[] = ['description' => $hugeDescription, 'photo_url' => null];
            }
            $partsData[] = ['name' => "Partida {$p}", 'items' => $items, 'subtotal' => 999];
        }

        return [
            'contacts' => [['name' => 'Alicia', 'phone' => '123', 'email' => 'a@test.com']],
            'observations_preview' => 'Observación con más texto para ocupar más de una línea completa dentro del bloque final.',
            'conditions_text' => "1. Condición larga que se envuelve.\n2. Otra condición larga que también se envuelve en varias líneas.\n3. Tercera condición.",
            'discount_totals_amount' => 50,
            'document_status' => 'approved',
            'parts' => $partsData,
        ];
    }

    public function test_vista_externa_nunca_ancla_el_bloque(): void
    {
        $result = $this->estimator->estimate($this->shortQuote(), external: true);

        $this->assertSame(0.0, $result['spacerPt']);
        $this->assertFalse($result['forceNewPage']);
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
        // Aun forzando página nueva, el espaciador nunca es negativo (piso en 0 dentro de la clase).
        $this->assertGreaterThanOrEqual(0.0, $result['spacerPt']);
    }

    public function test_espaciador_nunca_es_negativo_ni_en_el_borde_exacto(): void
    {
        // Partidas suficientes para acercarse al límite de una página sin pasarlo por mucho —
        // el caso más sensible a un error de redondeo en la estimación.
        $result = $this->estimator->estimate($this->longQuote(parts: 1, itemsPerPart: 3), external: false);

        $this->assertGreaterThanOrEqual(0.0, $result['spacerPt']);
    }

    /** @return array<string, mixed> */
    private function quoteWithItems(int $items, array $overrides = []): array
    {
        $rows = [];
        for ($i = 1; $i <= $items; $i++) {
            $rows[] = ['description' => "Item {$i}", 'photo_url' => null];
        }

        return array_merge($this->shortQuote(), [
            'parts' => [['name' => 'L1', 'items' => $rows, 'subtotal' => 100]],
        ], $overrides);
    }

    private function callProtected(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    /** SCRUM-986 — el bloque final ya NO incluye Totales/Firma: su alto no depende de Descuento ni de $signed. */
    public function test_bloque_final_no_depende_de_totales_ni_firma(): void
    {
        $base = $this->callProtected($this->estimator, 'finalBlockHeight', $this->shortQuote());
        $conDescuentoFirmado = $this->callProtected(
            $this->estimator,
            'finalBlockHeight',
            array_merge($this->shortQuote(), ['discount_totals_amount' => 50, 'document_status' => 'approved']),
        );

        $this->assertSame($base, $conDescuentoFirmado);
    }

    /** SCRUM-986 — el bloque Firma|Totales crece con la fila Descuento y con el nombre del firmante. */
    public function test_bloque_firma_totales_crece_con_descuento_y_firmante(): void
    {
        $base = $this->callProtected($this->estimator, 'leadingBlockHeight', $this->shortQuote());
        $conDescuento = $this->callProtected($this->estimator, 'leadingBlockHeight', array_merge($this->shortQuote(), ['discount_totals_amount' => 50]));
        $firmado = $this->callProtected($this->estimator, 'leadingBlockHeight', array_merge($this->shortQuote(), ['document_status' => 'approved']));

        $this->assertGreaterThan(0.0, $base);
        $this->assertGreaterThan($base, $conDescuento);
        $this->assertGreaterThan($base, $firmado);
    }

    /**
     * SCRUM-986 — ancla el bloque inferior DESPUÉS de Firma|Totales: con más ítems el espaciador
     * nunca crece, y cuando el bloque Firma|Totales ya no cabe en la página el cálculo sigue sin
     * dar negativos y el bloque final usa la página completa como referencia.
     */
    public function test_espaciador_decrece_monotonicamente_con_mas_items_y_nunca_es_negativo(): void
    {
        $prev = INF;
        $forced = false;
        for ($n = 1; $n <= 60; $n++) {
            $r = $this->estimator->estimate($this->quoteWithItems($n), external: false);
            $this->assertGreaterThanOrEqual(0.0, $r['spacerPt']);
            if (! $r['forceNewPage']) {
                $this->assertLessThanOrEqual($prev, $r['spacerPt'], "n={$n}");
                $prev = $r['spacerPt'];
            } else {
                $forced = true;
                $prev = INF; // nuevo tramo: Firma|Totales cambió de página, el espaciador reinicia
            }
        }
        $this->assertTrue($forced, 'con 60 ítems el bloque final debe forzar página nueva en algún punto');
    }

    /** SCRUM-986 — Firma|Totales que no cabe pasa íntegro a la página siguiente: el bloque final cabe ahí sin forzar otra página. */
    public function test_firma_totales_que_no_cabe_se_mueve_y_el_bloque_final_sigue_en_esa_pagina(): void
    {
        $leadingMoved = null;
        $prevForce = null;
        for ($n = 1; $n <= 60; $n++) {
            $r = $this->estimator->estimate($this->quoteWithItems($n), external: false);
            // Secuencia esperada al crecer n: sin forzar -> forzar (final no cabe tras Firma|Totales)
            // -> sin forzar de nuevo (Firma|Totales pasó a la página siguiente y el final cabe tras él).
            if ($prevForce === true && $r['forceNewPage'] === false) {
                $leadingMoved = $n;
                break;
            }
            $prevForce = $r['forceNewPage'];
        }

        $this->assertNotNull($leadingMoved, 'debe existir el punto donde Firma|Totales salta de página');
    }

    /** SCRUM-986 — la vista externa sigue sin bloque inferior (política SCRUM-140). */
    public function test_vista_externa_no_cambia_con_el_bloque_firma_totales(): void
    {
        $r = $this->estimator->estimate($this->quoteWithItems(30), external: true);

        $this->assertSame(0.0, $r['spacerPt']);
        $this->assertFalse($r['forceNewPage']);
    }
}
