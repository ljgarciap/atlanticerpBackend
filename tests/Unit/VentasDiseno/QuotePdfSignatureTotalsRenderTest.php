<?php

declare(strict_types=1);

namespace Tests\Unit\VentasDiseno;

use App\Modules\VentasDiseno\Services\QuotePdfService;
use Tests\TestCase;

/**
 * SCRUM-986 — estructura del HTML de la vista previa de Cotización: Firma | Totales como dos
 * bloques independientes inmediatamente bajo la última partida, y Observaciones -> Instrucciones
 * de pago -> Condiciones debajo, en ese orden. No requiere BD: renderiza la plantilla real con un
 * detalle armado a mano (misma forma que QuoteController::formatDetail()).
 */
class QuotePdfSignatureTotalsRenderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function detail(float $discount = 0.0, bool $signed = false, int $parts = 2): array
    {
        $p = [];
        for ($a = 1; $a <= $parts; $a++) {
            $p[] = ['name' => "Partida {$a}", 'subtotal' => 21, 'items' => [[
                'reference' => "REF-{$a}", 'description' => "Lampara {$a}", 'photo_url' => null,
                'quantity' => 2, 'effective_unit_price' => 10.5, 'discount_percent' => 0, 'line_total' => 21,
            ]]];
        }

        return [
            'description' => 'Cotizacion', 'master_client' => ['name' => 'Cliente'], 'sub_client' => ['business_name' => 'Sub', 'receptor_tipo' => '01'],
            'ruc' => '1-1', 'contacts' => [['name' => 'Maria Perez', 'phone' => null, 'email' => null]],
            'sales_project' => ['name' => 'P'], 'folio' => 'T-1', 'generated_at' => '2026-10-01',
            'owner' => ['name' => 'Dis', 'phone' => null], 'parts' => $p,
            'subtotal' => 42.0, 'discount_totals_amount' => $discount, 'itbms' => 2.94, 'grand_total' => 44.94,
            'observations_preview' => 'Fecha de entrega: Por definir', 'conditions_text' => '1. Condicion.',
            'document_status' => $signed ? 'approved' : 'draft',
        ];
    }

    /**
     * @param list<string>|null $paymentLines Fase 4a: las instrucciones de pago salen de los datos de la empresa (sin tenant no hay), así que el test de ORDEN
     *                                        de bloques las inyecta; `[]` verifica que sin datos no se imprime el bloque.
     */
    private function html(array $detail, bool $external = false, ?array $paymentLines = null): string
    {
        $data = app(QuotePdfService::class)->viewData($detail, $external);
        $data['paymentLines'] = $paymentLines ?? ['Cheque a nombre de: EMPRESA DEMO S.A.', 'Pagos ACH: Cuenta 00-00-00-000000-1, cuenta corriente, Banco Demo 1'];

        return view('ventas-diseno.quote-pdf', $data)->render();
    }

    public function test_sin_datos_de_pago_no_se_imprime_el_bloque_de_instrucciones(): void
    {
        $body = $this->body($this->html($this->detail(signed: true), paymentLines: []));

        $this->assertStringNotContainsString('Instrucciones de pago', $body);
        $this->assertStringNotContainsString('Cheque a nombre de', $body);
    }

    /** Parte del HTML correspondiente al cuerpo (sin el <style>, que menciona las mismas clases). */
    private function body(string $html): string
    {
        return substr($html, (int) strpos($html, '<body>'));
    }

    public function test_firma_y_totales_son_dos_bloques_independientes_firma_antes_que_totales(): void
    {
        $body = $this->body($this->html($this->detail()));

        $left = strpos($body, 'sig-totals-col left');
        $right = strpos($body, 'sig-totals-col right');
        $this->assertNotFalse($left);
        $this->assertNotFalse($right);
        $this->assertLessThan($right, $left);

        $leftCell = substr($body, $left, $right - $left);
        $rightCell = substr($body, $right, (int) strpos($body, 'bottom-block-spacer') - $right);

        // Firma solo a la izquierda, totales solo a la derecha — nunca en el mismo contenedor.
        $this->assertStringContainsString('Firma del cliente', $leftCell);
        $this->assertStringNotContainsString('Subtotal', $leftCell);
        $this->assertStringNotContainsString('class="totals"', $leftCell);
        $this->assertStringContainsString('Subtotal', $rightCell);
        $this->assertStringContainsString('ITBMS', $rightCell);
        $this->assertStringNotContainsString('Firma del cliente', $rightCell);
        $this->assertStringNotContainsString('class="signature"', $rightCell);
        $this->assertStringNotContainsString('totals-signature', $body);
    }

    public function test_bloque_firma_totales_va_tras_la_ultima_partida_y_antes_de_observaciones_y_el_resto_en_orden(): void
    {
        $body = $this->body($this->html($this->detail(parts: 3)));

        $lastTotalPartida = (int) strrpos($body, 'Total partida:');
        $sig = (int) strpos($body, 'sig-totals-block');
        $obs = (int) strpos($body, 'Observaciones');
        $pago = (int) strpos($body, 'Instrucciones de pago');
        $cond = (int) strpos($body, 'Condiciones');

        $this->assertGreaterThan($lastTotalPartida, $sig);
        $this->assertLessThan($obs, $sig);
        $this->assertLessThan($pago, $obs);
        $this->assertLessThan($cond, $pago);
        // El bloque inferior ya no contiene Totales/Firma.
        $final = substr($body, (int) strpos($body, 'class="final-block"'));
        $this->assertStringNotContainsString('Subtotal', $final);
        $this->assertStringNotContainsString('Firma del cliente', $final);
    }

    public function test_descuento_solo_cuando_es_mayor_a_cero_dentro_del_bloque_de_totales(): void
    {
        $sin = $this->body($this->html($this->detail(discount: 0.0)));
        $con = $this->body($this->html($this->detail(discount: 5.5)));

        $this->assertStringNotContainsString('Descuento', $sin);
        $this->assertStringContainsString('-$5.50', $con);
        $this->assertGreaterThan((int) strpos($con, 'sig-totals-col right'), (int) strpos($con, 'Descuento'));
    }

    public function test_nombre_del_firmante_solo_cuando_esta_aprobada(): void
    {
        $firmada = $this->body($this->html($this->detail(signed: true)));
        $borrador = $this->body($this->html($this->detail(signed: false)));

        $this->assertStringContainsString('class="signed-name">Maria Perez<', $firmada);
        $this->assertStringNotContainsString('signed-name">', $borrador);
    }

    public function test_nombre_del_firmante_puede_hacer_wrap_dentro_de_la_columna(): void
    {
        $html = $this->html($this->detail(signed: true));

        $this->assertMatchesRegularExpression('/\.signature \.signed-name\s*\{[^}]*white-space:\s*normal/', $html);
        $this->assertMatchesRegularExpression('/\.signature \.signed-name\s*\{[^}]*word-wrap:\s*break-word/', $html);
    }

    public function test_vista_externa_sigue_sin_firma_ni_totales_ni_bloque_inferior(): void
    {
        $body = $this->body($this->html($this->detail(), external: true));

        $this->assertStringNotContainsString('sig-totals-block', $body);
        $this->assertStringNotContainsString('Firma del cliente', $body);
        $this->assertStringNotContainsString('Subtotal', $body);
        $this->assertStringNotContainsString('Observaciones', $body);
    }

    public function test_bloque_firma_totales_es_indivisible(): void
    {
        $html = $this->html($this->detail());

        $this->assertMatchesRegularExpression('/\.sig-totals-block\s*\{[^}]*page-break-inside:\s*avoid/', $html);
        $this->assertStringNotContainsString('display: flex', $html);
    }
}
