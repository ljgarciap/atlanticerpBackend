<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Models\User;
use App\Modules\AdminCont\FacturacionElectronica\Services\FeRechazoNotifier;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Notifications\Jobs\SendNotificationEmailJob;
use App\Modules\Notifications\Models\NotificationSend;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1186 (hotfix) — correo a los usuarios configurados cuando la DGI rechaza una factura o nota de crédito. Solo producción, solo `rechazado`,
 * un correo por transmisión, y NUNCA rompe la certificación.
 */
final class FeRechazoNotifierTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    /** @var list<int> */
    private array $destinatarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config([
            'services.msl_fe.sandbox'    => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'prod-secret-key-123'],
        ]);
        Queue::fake();
        // PacProviderFactory solo deja certificar contra producción real si el entorno de AtlanticERP también es producción.
        $this->app['env'] = 'production';

        $this->destinatarios = [
            (int) User::where('email', 'vendedor@atlantic.test')->value('id'),
            (int) User::where('email', 'nivel8@atlantic.test')->value('id'),
        ];
        $this->configurar('production', $this->destinatarios);
    }

    /** @param list<int> $usuarios */
    private function configurar(string $ambiente, array $usuarios): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => $ambiente, 'fe_rechazo_notificar_user_ids' => $usuarios])->save();
    }

    private function service(): FiscalCertificationService
    {
        return app(FiscalCertificationService::class);
    }

    private function facturaSimple(): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
    }

    private function fakeRechazo(string $host = 'prod.msl.test'): void
    {
        Http::fake(["{$host}/*" => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO',
            'error' => "****** Mensaje ******\nEl Tipo de RUC consignado no es el correcto (2) no es igual al correcto (1)\n[9032] RUC inválido",
            'detalles' => ['9032 - El identificador tributario es incorrecto'],
        ], 422)]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, NotificationSend> */
    private function avisos()
    {
        return NotificationSend::query()->where('source', NotificationSend::SOURCE_FE_DOCUMENTO_RECHAZADO)->get();
    }

    public function test_un_rechazo_en_produccion_envia_un_correo_a_cada_destinatario_con_los_datos_del_documento(): void
    {
        $this->fakeRechazo();
        $factura = $this->facturaSimple();   // facturar() ya certifica automáticamente y la DGI (fake) la rechaza

        $this->assertSame('rechazado', $factura->fresh()->fe_estado);
        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $aviso = $avisos->first();
        $this->assertSame([NotificationSend::CHANNEL_EMAIL], $aviso->channels);
        $this->assertEqualsCanonicalizing($this->destinatarios, $aviso->audience);

        $this->assertStringContainsString($factura->numero, $aviso->content['subject']);
        $this->assertStringContainsString('ILU-', $aviso->content['subject'], 'serie y número fiscal que se enviaron al PAC');
        $body = $aviso->content['body'];
        $this->assertStringContainsString('PRODUCCIÓN', $body);
        $this->assertStringContainsString('Tipo de RUC consignado', $body, 'el motivo del rechazo');
        $this->assertStringContainsString('Monto: B/. 963.00', $body);
        $this->assertStringContainsString('Intento número: 1', $body);
        $this->assertStringContainsString('Ventana de reintento hasta:', $body);
        $this->assertSame('/admin-contab/facturacion', $aviso->content['cta_url']);

        Queue::assertPushed(SendNotificationEmailJob::class, 2);
    }

    public function test_el_correo_no_incluye_la_api_key_ni_el_ruc_completo_del_cliente(): void
    {
        Http::fake(['prod.msl.test/*' => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO',
            'error' => 'Falló la validación. x-api-key: prod-secret-key-123 en la solicitud',
            'detalles' => ['9026 - Falló la validación de algunos escenarios.'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $aviso = $this->avisos()->first();
        $this->assertNotNull($aviso);
        $texto = $aviso->content['subject']."\n".$aviso->content['body'];
        $this->assertStringNotContainsString('prod-secret-key-123', $texto);
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,2}-\d{3,4}-\d{1,6}\b/', $texto, 'sin cédula/RUC en el correo');
    }

    public function test_el_motivo_del_rechazo_se_enmascara_si_cita_un_ruc_o_una_cedula(): void
    {
        Http::fake(['prod.msl.test/*' => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO',
            'error' => 'El Tipo de RUC consignado (2) para 8-123-4567 DV 21 y E-8-123456 y 155704849-2-2021 y 801234567 y 8 123 4567 y PAS-123456 y 8-NT-2-1234 no es correcto',
            'detalles' => ['9032 - El identificador tributario es incorrecto'],
        ], 422)]);
        $this->facturaSimple();

        $texto = $this->avisos()->first()->content['body'];
        $this->assertStringContainsString('[identificación omitida]', $texto);
        $this->assertStringContainsString('9032', $texto, 'el código del rechazo se conserva');
        foreach (['8-123-4567', 'E-8-123456', '155704849', '801234567', '8 123 4567', 'PAS-123456', '8-NT', '2-1234'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
        }
    }

    public function test_fuera_de_produccion_no_se_envia_nada(): void
    {
        foreach (['testing' => 'sandbox.msl.test', 'apagado' => null] as $ambiente => $host) {
            $this->configurar($ambiente, $this->destinatarios);
            if ($host !== null) {
                $this->fakeRechazo($host);
                $this->service()->certificarFactura($this->facturaSimple());
            } else {
                // Con el PAC apagado ni siquiera hay envío; el notificador igual debe ignorar el ambiente.
                $factura = $this->facturaSimple();
                $resultado = new \App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult(
                    estado: 'rechazado', pacProvider: 'msl', errores: ['9032'], httpStatus: 422, ambiente: 'apagado',
                );
                app(FeRechazoNotifier::class)->avisar($factura, 'factura', $resultado);
            }
            $this->assertCount(0, $this->avisos(), "ambiente {$ambiente}");
        }
        Queue::assertNothingPushed();
    }

    public function test_error_de_configuracion_y_pendiente_no_avisan(): void
    {
        Http::fake(['prod.msl.test/*' => Http::response(['error' => 'API key inválida o ausente'], 401)]);
        $factura = $this->facturaSimple();
        $this->service()->certificarFactura($factura);
        $this->assertSame('error_configuracion', $factura->fresh()->fe_estado);

        Http::fake(['prod.msl.test/*' => Http::response(['ok' => false, 'estado' => 'PENDIENTE', 'mensaje' => 'encolado'], 202)]);
        $otra = $this->facturaSimple();
        $this->service()->certificarFactura($otra);

        $this->assertCount(0, $this->avisos());
    }

    public function test_una_factura_certificada_no_avisa(): void
    {
        Http::fake(['prod.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01-x', 'qr' => 'https://qr', 'xmlFirmado' => base64_encode('<x/>')], 200)]);
        \Illuminate\Support\Facades\Storage::fake('s3-private');

        $this->service()->certificarFactura($this->facturaSimple());

        $this->assertCount(0, $this->avisos());
    }

    public function test_cada_transmision_rechazada_avisa_una_sola_vez_y_un_reintento_rechazado_avisa_de_nuevo(): void
    {
        $this->fakeRechazo();
        $factura = $this->facturaSimple();
        $this->assertCount(1, $this->avisos());

        // Misma transmisión notificada otra vez (reproceso del mismo resultado): sin duplicado.
        $resultado = new \App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult(
                    estado: 'rechazado', pacProvider: 'msl', errores: ['9032'], httpStatus: 422, ambiente: 'production',
                );
        app(FeRechazoNotifier::class)->avisar($factura->fresh(), 'factura', $resultado);
        $this->assertCount(1, $this->avisos(), 'misma transmisión: no se duplica');

        // Reintento manual que vuelve a ser rechazado: otra transmisión, otro aviso, con «Intento número: 2».
        $this->service()->recertificar($factura->fresh(), 'factura');
        $avisos = $this->avisos();
        $this->assertCount(2, $avisos);
        $this->assertStringContainsString('Intento número: 2', $avisos->sortByDesc('id')->first()->content['body']);
    }

    public function test_si_el_envio_del_correo_falla_la_factura_queda_rechazada_igual_y_se_registra_en_el_log(): void
    {
        NotificationSend::creating(static function (): void {
            throw new \RuntimeException('smtp caído');
        });
        Log::spy();
        $this->fakeRechazo();
        $factura = $this->facturaSimple();

        $this->assertSame('rechazado', $factura->fresh()->fe_estado, 'el estado fiscal no depende del correo');
        $this->assertNotNull($factura->fresh()->fe_detalle);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'no se pudo enviar el aviso'))->atLeast()->once();
    }

    public function test_sin_destinatarios_o_con_destinatarios_inactivos_no_se_envia_ni_se_registra_error(): void
    {
        Log::spy();
        $this->fakeRechazo();

        $this->configurar('production', []);
        $this->service()->certificarFactura($this->facturaSimple());

        User::whereIn('id', $this->destinatarios)->update(['is_active' => false]);
        $this->configurar('production', $this->destinatarios);
        $this->service()->certificarFactura($this->facturaSimple());
        User::whereIn('id', $this->destinatarios)->update(['is_active' => true]);

        $this->assertCount(0, $this->avisos());
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_contains((string) $m, 'no se pudo enviar el aviso')), \Mockery::any()]);
    }

    public function test_la_lista_de_destinatarios_se_cambia_por_la_api_de_configuracion_fiscal(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $id = $this->destinatarios[0];

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => [$id]])
            ->assertStatus(200)->assertJsonPath('fe_rechazo_notificar_user_ids', [$id]);
        $this->reTenant();
        $this->assertSame([$id], AdminContFiscalSettings::current()->fe_rechazo_notificar_user_ids);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['fe_rechazo_notificar_user_ids.0']);
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => [$id, $id]])
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => [true]])
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => ['a' => $id]])
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => []])
            ->assertStatus(200);
    }
}
