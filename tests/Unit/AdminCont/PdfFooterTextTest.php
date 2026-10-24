<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Support\PdfFooterText;
use PHPUnit\Framework\TestCase;

/**
 * Pre-QA (SCRUM-857/858, 2026-09-09) — cobertura pura de PdfFooterText, sin base de datos ni
 * render real de PDF (mismo criterio que tests/Unit/VentasDiseno/QuotePdfLayoutEstimatorTest.php).
 *
 * Nace de un rebote encontrado en vivo contra dev.atlanticerp.ai, en 2 capas del mismo problema, al
 * poblar AdminContFiscalSettings con datos de prueba que incluían una comilla (para probar el
 * escaping, tal como pide el protocolo de Pre-QA):
 *
 * 1. El PDF descargado mostraba literalmente "Corp\&#039;s" en vez de "Corp's" — forFiscal() ya
 *    escapaba el valor para el contexto de string PHP dentro del bloque <script type="text/php">
 *    de dompdf, pero ambas vistas (payment-receipt-pdf.blade.php, account-statement-pdf.blade.php)
 *    interpolaban el resultado con {{ }}, que vuelve a aplicar htmlspecialchars() sobre un valor
 *    ya escapado — doble escape. Corregido cambiando esas vistas a {!! !!}.
 * 2. Con ese primer fix, el PDF pasó a mostrar "Corp\'s" (con un backslash VISIBLE) en vez de
 *    "Corp's" — el string destino usa comillas DOBLES (`$companyText = "..."`), pero el escaping
 *    original (`addslashes()`) escapaba también comillas simples, y `\'` no es una secuencia de
 *    escape reconocida dentro de un string PHP doble-comillado (a diferencia de uno simple-
 *    comillado) — el backslash quedaba como carácter literal. Corregido escapando solo backslash
 *    y comilla doble, lo único que ese contexto realmente necesita.
 *
 * También cubre el fallback agregado el mismo día: con AdminContFiscalSettings totalmente vacío
 * (nombre_comercial Y direccion_fiscal — el estado real hoy en dev/test/prod, nadie llenó esa
 * pantalla todavía, exclusiva de Mark), el footer debe mostrar el mismo texto estándar ya
 * hardcodeado en quote-pdf.blade.php/service-quote-pdf.blade.php, no solo "Atlantic" a secas
 * (eso incumplía el criterio literal del ticket y la consistencia visual pedida contra
 * cotizaciones).
 */
class PdfFooterTextTest extends TestCase
{
    private function fiscal(string $nombreComercial, string $direccionFiscal, string $ruc = '', string $dv = ''): AdminContFiscalSettings
    {
        $fiscal = new AdminContFiscalSettings();
        $fiscal->nombre_comercial = $nombreComercial;
        $fiscal->direccion_fiscal = $direccionFiscal;
        $fiscal->ruc = $ruc;
        $fiscal->dv = $dv;

        return $fiscal;
    }

    public function test_companyName_cae_a_atlantic_si_nombre_comercial_esta_vacio(): void
    {
        $this->assertSame('Atlantic', PdfFooterText::companyName($this->fiscal('', '')));
    }

    public function test_companyName_usa_el_dato_real_si_esta_cargado(): void
    {
        $this->assertSame('Atlantic USA Corp', PdfFooterText::companyName($this->fiscal('Atlantic USA Corp', '')));
    }

    public function test_forFiscal_usa_el_pie_de_pagina_estandar_completo_si_todo_esta_vacio(): void
    {
        // Mismo texto exacto que quote-pdf.blade.php/service-quote-pdf.blade.php — es el estado
        // real hoy en dev/test/prod, no un fixture artificial.
        $this->assertSame(
            'ATLANTIC USA CORP - Calle 57 Este, Times Square Plaza - Obarrio, Panama - www.atlantic.com.pa',
            PdfFooterText::forFiscal($this->fiscal('', ''))
        );
    }

    public function test_forFiscal_usa_el_dato_real_una_vez_cargado(): void
    {
        $this->assertSame(
            'Atlantic USA Corp - Calle 57 Este, Panama - www.atlantic.com.pa',
            PdfFooterText::forFiscal($this->fiscal('Atlantic USA Corp', 'Calle 57 Este, Panama'))
        );
    }

    public function test_forFiscal_omite_el_guion_colgante_si_solo_hay_nombre(): void
    {
        $this->assertSame(
            'Atlantic USA Corp - www.atlantic.com.pa',
            PdfFooterText::forFiscal($this->fiscal('Atlantic USA Corp', ''))
        );
    }

    public function test_forFiscal_no_escapa_comilla_simple_el_string_destino_es_doble_comillado(): void
    {
        // Un apóstrofe no necesita (ni debe) escaparse para interpolarse dentro de un string PHP
        // "..." — un backslash ahí quedaría como carácter literal VISIBLE en el PDF ("Corp\'s").
        $result = PdfFooterText::forFiscal($this->fiscal("Atlantic USA Corp's", ''));

        $this->assertSame("Atlantic USA Corp's - www.atlantic.com.pa", $result);
    }

    public function test_forFiscal_escapa_comilla_doble_y_backslash_para_el_contexto_de_script_php_de_dompdf(): void
    {
        $result = PdfFooterText::forFiscal($this->fiscal('Atlantic\\Corp', 'Calle "5", Panama'));

        $this->assertSame('Atlantic\\\\Corp - Calle \\"5\\", Panama - www.atlantic.com.pa', $result);
        $this->assertStringNotContainsString('&#039;', $result);
        $this->assertStringNotContainsString('&quot;', $result);
    }
}
