<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Support\InvoicePdfVariant;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1156 (T3 a T6) — las dos variantes del PDF de factura: la certificada (formato nuevo, QR, clave de acceso) y la de siempre con
 * el banner «Sin validez fiscal». Se prueba lo que ve el cliente (el HTML que va a dompdf) y que dompdf produzca un PDF de verdad.
 * Ninguna prueba consume números del sandbox: las facturas y las transmisiones se fabrican.
 */
final class InvoicePdfVariantsTest extends FeatureTestCase
{
    private const CUFE = 'FE0120000155704849-2-2021-261179609251609600073000000000000000000000';

    private const NOMBRE_REAL = 'ROGER DUCRUET REAL';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'ATLANTIC USA CORP', 'ruc' => '1838853-1-711641', 'dv' => '81',
            'direccion_fiscal' => 'PH Time Square, planta baja', 'telefono_contacto' => '269-8051',
        ]);
    }

    private static function qr(string $cufe = self::CUFE, string $host = 'dgi-fep-test.mef.gob.pa:40001'): string
    {
        return "https://{$host}/Consultas/FacturasPorCUFE?chFE={$cufe}&iAmb=2&digestValue=abc";
    }

    /** @param array<string, mixed> $attrs */
    private function makeInvoice(array $attrs = []): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente PDF '.uniqid(), 'default_price_type' => 'project']);

        $attrs = array_merge([
            'numero' => 'F-'.random_int(100000, 999999), 'order_id' => random_int(1000000, 9999999),
            'master_client_id' => $master->id, 'cliente' => self::NOMBRE_REAL, 'monto' => 107.0, 'saldo_aplicado' => 0.0,
            'serie' => 'ILU', 'fe_estado' => 'certificado', 'cufe' => self::CUFE, 'qr_content' => self::qr(),
            'fe_emision_at' => '2026-10-14 15:30:00',
            'receptor_tipo' => '01', 'receptor_ruc' => '8-238-845', 'receptor_dv' => '10', 'receptor_nombre' => 'ROGER DUCRUET SA',
        ], $attrs);

        // Los campos fiscales los escribe solo `FiscalCertificationService` (no son fillable): igual acá, con forceFill.
        $fiscales = array_intersect_key($attrs, array_flip(['serie', 'fe_estado', 'cufe', 'qr_content', 'fe_emision_at']));
        $invoice  = AdminContInvoice::create(array_diff_key($attrs, $fiscales));
        $invoice->forceFill($fiscales)->save();

        return $invoice->refresh();
    }

    /** Factura certificada en producción: transmisión `produccion` Y enlace del host de producción (ambos deben coincidir). @param array<string, mixed> $attrs */
    private function makeProdInvoice(array $attrs = []): AdminContInvoice
    {
        return $this->makeInvoice(array_merge(['qr_content' => self::qr(self::CUFE, 'dgi-fep.mef.gob.pa')], $attrs));
    }

    private function makeTransmision(AdminContInvoice $invoice, string $ambiente = 'pruebas', string $numeroFiscal = '0039'): void
    {
        AdminContFeTransmision::create([
            'documento_tipo' => 'factura', 'documento_id' => $invoice->id, 'provider' => 'msl', 'ambiente' => $ambiente,
            'operacion' => 'certificar', 'origen' => 'certificacion', 'serie' => 'ILU', 'numero_fiscal' => $numeroFiscal,
            'resultado' => 'certificado', 'request_payload' => ['serie' => 'ILU', 'numero' => $numeroFiscal],
        ]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) \Illuminate\Support\Facades\DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function detail(AdminContInvoice $invoice, array $over = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id, 'numero_factura' => $invoice->numero, 'fecha_factura' => '2026-10-14',
            'es_anulada' => false, 'cliente' => self::NOMBRE_REAL, 'cliente_ruc' => $invoice->receptor_ruc,
            'cliente_receptor_tipo' => $invoice->receptor_tipo, 'receptor_nombre' => $invoice->receptor_nombre,
            'vendedor' => 'MARIBEL', 'monto' => 107.0, 'subtotal' => 100.0, 'descuentos' => 0.0, 'itbms' => 7.0, 'total' => 107.0,
            'saldo_aplicado' => 0.0, 'total_a_pagar' => 107.0, 'cuenta_pago' => '0123456789', 'responsable' => 'Contabilidad',
            'fe_estado' => $invoice->fe_estado, 'cufe' => $invoice->cufe, 'qr_content' => $invoice->qr_content,
            'items' => [['referencia_publica' => 'ML-10221CBK', 'descripcion' => 'EMP/LED DIM 10W 3K', 'cantidad' => 2, 'precio_unitario' => 50.0, 'subtotal' => 100.0]],
        ], $over);
    }

    /** HTML que recibe dompdf, con la plantilla que elige `generate()`. @param array<string, mixed> $detail */
    private function html(array $detail): string
    {
        $service = app(InvoicePdfService::class);
        $data    = $service->viewData($detail);
        $view    = $data['variante']->isFiscal() ? 'admin-cont.invoice-pdf-fiscal' : 'admin-cont.invoice-pdf';

        return view($view, $data)->render();
    }

    private function conAnulacion(AdminContInvoice $invoice): array
    {
        $invoice->forceFill(['anulada_at' => now()])->save();

        return $this->detail($invoice, ['es_anulada' => true]);
    }

    private function makeNotaDeAnulacion(AdminContInvoice $invoice, ?string $feEstado): AdminContCreditNote
    {
        $note = AdminContCreditNote::create([
            'numero' => 'NC-'.random_int(100000, 999999), 'master_client_id' => $invoice->master_client_id, 'cliente' => 'Cliente',
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $invoice->id,
            'monto' => 107, 'subtotal' => 100, 'itbms' => 7, 'motivo' => 'Test', 'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO,
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, 'registrado_por' => $this->userIdByEmail('contabilidad@test.com'),
            'fecha' => now()->toDateString(),
        ]);
        $note->forceFill(['fe_estado' => $feEstado])->save();

        return $note;
    }

    // ── Variante fiscal (T4) ─────────────────────────────────────────────────────────────────

    public function test_factura_certificada_lleva_titulo_clave_enlace_qr_y_leyenda_sin_banner_de_sin_validez(): void
    {
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('Factura de Operación Interna', $html);
        $this->assertStringContainsString(self::CUFE, $html);
        $this->assertStringContainsString('https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE', $html);
        $this->assertStringNotContainsString('chFE=', $html, 'el enlace impreso va sin la query');
        $this->assertStringContainsString('Consulte por la clave de acceso en:', $html);
        $this->assertMatchesRegularExpression('/<img src="data:image\/png;base64,[^"]+" width="200" height="200" alt="QR">/', $html);
        $this->assertStringContainsString('Representación gráfica generada por AtlanticERP', $html);
        $this->assertStringNotContainsString('Sin validez fiscal', $html);
        $this->assertStringNotContainsString('DGI</', $html, 'sin «DGI» como encabezado de autoridad (spec §12.1)');
    }

    public function test_el_qr_dibujado_es_el_de_qr_content_del_pac(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice);

        $data = app(InvoicePdfService::class)->viewData($this->detail($invoice));

        $this->assertSame(\App\Shared\Support\QrPng::dataUri((string) $invoice->qr_content), $data['qrDataUri']);
    }

    public function test_serie_numero_y_fecha_salen_de_lo_enviado_al_pac_no_del_folio_con_letra(): void
    {
        $invoice = $this->makeInvoice(['numero' => 'F-0039']);
        $this->makeTransmision($invoice, 'pruebas', '0039');

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('<td>ILU</td>', $html);
        $this->assertStringContainsString('<td>0039</td>', $html);
        $this->assertStringContainsString('14/10/2026 15:30', $html);
        $this->assertStringContainsString('<td>MARIBEL</td>', $html);
    }

    public function test_contribuyente_imprime_razon_social_ruc_y_dv_del_receptor_congelado(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('ROGER DUCRUET SA', $html);
        $this->assertStringContainsString('RUC 8-238-845', $html);
        $this->assertStringContainsString('DV 10', $html);
        $this->assertStringContainsString('Contribuyente', $html);
        $this->assertStringNotContainsString('referencia interna', $html);
    }

    public function test_consumidor_final_imprime_lo_enviado_al_pac_y_el_nombre_real_solo_como_referencia_interna(): void
    {
        $invoice = $this->makeInvoice(['receptor_tipo' => '02', 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => null]);
        $this->makeTransmision($invoice);

        $html = $this->html($this->detail($invoice, ['cliente_ruc' => null, 'receptor_nombre' => null, 'cliente_receptor_tipo' => '02']));

        $this->assertStringContainsString('CONSUMIDOR FINAL', $html);
        $this->assertStringNotContainsString('RUC 8-', $html);
        $this->assertStringContainsString('Cliente (referencia interna, no consta en el comprobante fiscal): '.self::NOMBRE_REAL, $html);
        $this->assertStringContainsString('Emitida en Uso Posterior', $html);
        // El nombre real aparece UNA sola vez: en la línea rotulada, nunca como nombre del bloque fiscal.
        $this->assertSame(1, substr_count($html, self::NOMBRE_REAL));
    }

    public function test_el_bloque_de_pago_se_rotula_como_dato_interno(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('dato interno, no consta en el comprobante fiscal', $html);
        $this->assertStringContainsString('0123456789', $html);
    }

    public function test_el_pdf_sale_siempre_en_espanol_aunque_el_request_pida_ingles(): void
    {
        // `SetLocaleFromHeader` cambia el idioma por request según `Accept-Language`; el documento fiscal no debe mezclar idiomas.
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');
        $pendiente = $this->makeInvoice(['fe_estado' => 'pendiente']);

        // Con el idioma del request en inglés y traducciones inglesas CARGADAS, la plantilla igual debe salir en español.
        app('translator')->addLines([
            'invoice_pdf.titulo' => 'Internal Operation Invoice', 'invoice_pdf.clave_acceso' => 'Look up the access key at:',
            'invoice_pdf.sin_validez' => 'No fiscal validity', 'invoice_pdf.motivo.pendiente' => 'Pending certification',
        ], 'en');
        app()->setLocale('en');
        try {
            $fiscal = $this->html($this->detail($invoice));
            $sin    = $this->html($this->detail($pendiente));
        } finally {
            app()->setLocale('es');
        }

        $this->assertStringContainsString('Factura de Operación Interna', $fiscal);
        $this->assertStringContainsString('Consulte por la clave de acceso en:', $fiscal);
        $this->assertStringContainsString('Sin validez fiscal', $sin);
        $this->assertStringNotContainsString('Internal Operation Invoice', $fiscal);
        $this->assertStringNotContainsString('No fiscal validity', $sin);
    }

    public function test_una_factura_certificada_por_polling_toma_numero_y_ambiente_de_esa_transmision(): void
    {
        // Quedó `pendiente` y se certificó después por polling: la fila `certificado` es de `consultar_estado`, no de `certificar`.
        $invoice = $this->makeInvoice(['numero' => 'F-0044']);
        AdminContFeTransmision::create([
            'documento_tipo' => 'factura', 'documento_id' => $invoice->id, 'provider' => 'msl', 'ambiente' => 'pruebas',
            'operacion' => 'certificar', 'origen' => 'certificacion', 'serie' => 'ILU', 'numero_fiscal' => '0044', 'resultado' => 'pendiente',
        ]);
        AdminContFeTransmision::create([
            'documento_tipo' => 'factura', 'documento_id' => $invoice->id, 'provider' => 'msl', 'ambiente' => 'pruebas',
            'operacion' => 'consultar_estado', 'origen' => 'polling', 'serie' => 'ILU', 'numero_fiscal' => '0044', 'resultado' => 'certificado',
        ]);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('<td>0044</td>', $html, 'número fiscal, no el folio F-0044');
        $this->assertStringNotContainsString('<td>F-0044</td>', $html);
        $this->assertStringContainsString('AMBIENTE DE PRUEBAS', $html);
    }

    // ── Ambiente (RN9) ───────────────────────────────────────────────────────────────────────

    public function test_ambiente_de_pruebas_lleva_leyenda_y_marca_de_agua(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice, 'pruebas');

        $html = $this->html($this->detail($invoice));

        $this->assertSame(2, substr_count($html, 'AMBIENTE DE PRUEBAS - SIN VALIDEZ FISCAL'), 'aviso y marca de agua');
        $this->assertStringContainsString('class="watermark"', $html);
    }

    public function test_ambiente_de_produccion_no_lleva_marca_de_pruebas(): void
    {
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');

        $html = $this->html($this->detail($invoice));

        $this->assertStringNotContainsString('AMBIENTE DE PRUEBAS', $html);
        $this->assertStringNotContainsString('class="watermark"', $html);
        $this->assertStringContainsString('https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE', $html);
    }

    public function test_ambiente_produccion_con_enlace_del_host_de_pruebas_se_imprime_como_pruebas(): void
    {
        // Datos que se contradicen: ante la duda, nunca se pinta como producción lo que no se puede demostrar.
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice, 'produccion');

        $this->assertStringContainsString('AMBIENTE DE PRUEBAS', $this->html($this->detail($invoice)));
    }

    public function test_el_ambiente_sale_de_la_transmision_no_de_la_configuracion_actual(): void
    {
        // La configuración fiscal HOY apunta a producción, pero la factura se certificó en pruebas: manda la transmisión.
        AdminContFiscalSettings::current()->update(['pac_ambiente' => 'production']);
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice, 'pruebas');

        $this->assertStringContainsString('AMBIENTE DE PRUEBAS', $this->html($this->detail($invoice)));
    }

    // ── Variante sin validez (T3) ────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function estadosSinCertificar(): array
    {
        return [
            'pendiente'    => ['pendiente', 'Pendiente de certificación'],
            'rechazado'    => ['rechazado', 'Rechazada por el proveedor de facturación electrónica'],
            'en espera NC' => ['en_espera_nc', 'En espera de nota de crédito'],
            'no aplica'    => ['no_aplica', 'Facturación electrónica no aplicada a este documento'],
            'error config' => ['error_configuracion', 'Facturación electrónica no aplicada a este documento'],
            'legada'       => [null, 'Factura anterior a la facturación electrónica'],
        ];
    }

    #[DataProvider('estadosSinCertificar')]
    public function test_factura_sin_certificar_mantiene_el_pdf_actual_con_el_banner_y_su_motivo_y_sin_qr(?string $estado, string $motivo): void
    {
        $invoice = $this->makeInvoice(['fe_estado' => $estado, 'cufe' => $estado === null ? null : self::CUFE, 'qr_content' => $estado === null ? null : self::qr()]);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('Sin validez fiscal', $html);
        $this->assertStringContainsString($motivo, $html);
        $this->assertStringContainsString('class="sin-validez"', $html);
        $this->assertStringNotContainsString('alt="QR"', $html);
        $this->assertStringNotContainsString(self::CUFE, $html);
        $this->assertStringNotContainsString('Factura de Operación Interna', $html);
        $this->assertStringContainsString('Factura '.$invoice->numero, $html, 'es el PDF de siempre');
        $this->assertStringNotContainsString('class="watermark"', $html, 'sin anular no hay marca de agua');
    }

    public function test_factura_sin_emitir_dice_que_aun_no_esta_emitida(): void
    {
        $html = app(InvoicePdfService::class);
        $data = $html->viewData(['es_anulada' => false, 'cliente' => 'Cliente', 'monto' => 100, 'invoice_id' => null]);

        $this->assertSame(InvoicePdfVariant::MOTIVO_SIN_EMITIR, $data['variante']->motivo);
        $this->assertStringContainsString('Factura aún no emitida', view('admin-cont.invoice-pdf', $data)->render());
    }

    public function test_certificada_sin_cufe_cae_a_sin_validez_y_deja_una_advertencia(): void
    {
        Log::spy();
        $invoice = $this->makeInvoice(['cufe' => null]);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('Sin validez fiscal', $html);
        $this->assertStringContainsString('Datos fiscales incompletos', $html);
        $this->assertStringNotContainsString('alt="QR"', $html);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_certificada_con_cufe_ajeno_al_enlace_no_se_pinta_como_certificada(): void
    {
        $invoice = $this->makeInvoice(['qr_content' => self::qr('FE0000000000000000-9-9999')]);

        $html = $this->html($this->detail($invoice));

        $this->assertStringContainsString('Datos fiscales incompletos', $html);
        $this->assertStringNotContainsString('alt="QR"', $html);
    }

    // ── Anulación (T5, RN5) ──────────────────────────────────────────────────────────────────

    public function test_certificada_anulada_con_la_nota_certificada_lleva_la_marca_anulada(): void
    {
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');
        $detail = $this->conAnulacion($invoice);
        $this->makeNotaDeAnulacion($invoice, 'certificado');

        $html = $this->html($detail);

        $this->assertMatchesRegularExpression('/<div class="watermark"><span style="font-size: 90px;">ANULADA/', $html);
        $this->assertStringNotContainsString('ANULACIÓN EN TRÁMITE', $html);
        $this->assertStringContainsString('alt="QR"', $html, 'sigue siendo el formato certificado');
    }

    public function test_certificada_anulada_con_la_nota_pendiente_dice_anulacion_en_tramite_no_anulada(): void
    {
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');
        $detail = $this->conAnulacion($invoice);
        $this->makeNotaDeAnulacion($invoice, 'pendiente');

        $html = $this->html($detail);

        $this->assertStringContainsString('ANULACIÓN EN TRÁMITE', $html);
        $this->assertStringContainsString('la nota de crédito de anulación aún no está certificada', $html);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*>ANULADA/', $html);
    }

    public function test_certificada_anulada_sin_ninguna_nota_tambien_es_en_tramite(): void
    {
        $invoice = $this->makeProdInvoice();
        $this->makeTransmision($invoice, 'produccion');

        $this->assertStringContainsString('ANULACIÓN EN TRÁMITE', $this->html($this->conAnulacion($invoice)));
    }

    public function test_sin_certificar_y_anulada_lleva_las_dos_marcas_y_el_banner(): void
    {
        $invoice = $this->makeInvoice(['fe_estado' => 'pendiente']);
        $detail  = $this->conAnulacion($invoice);

        $html = $this->html($detail);

        $this->assertMatchesRegularExpression('/<span>ANULADA <br><small[^>]*>SIN VALIDEZ FISCAL<\/small>/', $html);
        $this->assertStringContainsString('class="sin-validez"', $html);
    }

    public function test_el_pdf_anulado_solo_no_certificado_conserva_la_marca_de_siempre(): void
    {
        // Contrato del PDF anterior: una anulada con FE apagada mantiene «ANULADA», ahora con el aviso de sin validez.
        $invoice = $this->makeInvoice(['fe_estado' => 'no_aplica', 'cufe' => null, 'qr_content' => null]);

        $this->assertStringContainsString('ANULADA', $this->html($this->conAnulacion($invoice)));
    }

    // ── Integridad de montos (RN7) y PDF real ────────────────────────────────────────────────

    public function test_los_montos_y_el_pago_son_identicos_en_las_dos_variantes(): void
    {
        $certificada = $this->makeInvoice();
        $this->makeTransmision($certificada);
        $pendiente = $this->makeInvoice(['fe_estado' => 'pendiente']);

        $over = ['subtotal' => 250.5, 'descuentos' => 25.05, 'itbms' => 15.78, 'total' => 241.23, 'monto' => 241.23, 'saldo_aplicado' => 100.0, 'total_a_pagar' => 141.23];

        $a = $this->html($this->detail($certificada, $over));
        $b = $this->html($this->detail($pendiente, $over));

        preg_match('/<div class="totales">.*?<\/div>\s*<div class="pago-block">/s', $a, $ta);
        preg_match('/<div class="totales">.*?<\/div>\s*<div class="pago-block">/s', $b, $tb);

        $this->assertNotEmpty($ta);
        $this->assertSame(preg_replace('/\s+/', ' ', $ta[0]), preg_replace('/\s+/', ' ', $tb[0]));
        foreach (['250.50', '25.05', '15.78', '241.23', '100.00', '141.23'] as $monto) {
            $this->assertStringContainsString($monto, $a);
        }
    }

    public function test_dompdf_produce_un_pdf_de_verdad_en_cada_variante(): void
    {
        $certificada = $this->makeInvoice();
        $this->makeTransmision($certificada);
        $pendiente = $this->makeInvoice(['fe_estado' => 'pendiente']);
        $service   = app(InvoicePdfService::class);

        foreach ([$certificada, $pendiente] as $invoice) {
            $bytes = $service->generate($this->detail($invoice))->output();
            $this->assertStringStartsWith('%PDF', $bytes);
            $this->assertGreaterThan(5000, strlen($bytes));
        }
    }

    public function test_certificada_con_cuarenta_lineas_genera_el_pdf_y_cuenta_el_tiempo(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeTransmision($invoice);
        $items = [];
        for ($i = 1; $i <= 45; $i++) {
            $items[] = ['referencia_publica' => "REF-{$i}", 'descripcion' => "Luminaria de prueba número {$i} con descripción larga para ocupar el ancho", 'cantidad' => $i, 'precio_unitario' => 10.0, 'subtotal' => 10.0 * $i];
        }

        $inicio = microtime(true);
        $bytes  = app(InvoicePdfService::class)->generate($this->detail($invoice, ['items' => $items]))->output();
        $segundos = microtime(true) - $inicio;

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertLessThan(10.0, $segundos, 'generar un PDF certificado de 45 líneas no debe tardar segundos de más');
    }
}
