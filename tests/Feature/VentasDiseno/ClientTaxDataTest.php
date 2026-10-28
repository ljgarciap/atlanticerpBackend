<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Database\Seeders\SpecialPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1002 — validar un RUC/Cédula contra la DGI (vía MSL) y editar razón social/RUC/DV de un SubCliente.
 * Decisiones de Luis (2026-09-29): la validación respeta "Apagado"; editan Gerencia, Contabilidad y Ventas & Diseño;
 * 409 solo si el cliente tiene facturas/NC `certificado` o `pendiente`.
 */
final class ClientTaxDataTest extends FeatureTestCase
{
    private const URL_VALIDAR = '/api/ventas-diseno/clients/validate-tax-id';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        $this->limpiarCircuitos();
    }

    protected function tearDown(): void
    {
        $this->limpiarCircuitos();
        parent::tearDown();
    }

    /** El estado de los circuitos vive en Redis y sobrevive entre tests: sin esto un test que abre el circuito rompe al siguiente. */
    private function limpiarCircuitos(): void
    {
        foreach (['msl_lookup_pruebas', 'msl_fe_pruebas'] as $nombre) {
            Redis::del("cb:{$nombre}:failures", "cb:{$nombre}:opened_at");
        }
    }

    private function limpiarLimite(string $email): void
    {
        $id = (int) DB::table('atlantic_auth.users')->where('email', $email)->value('id');
        RateLimiter::clear(md5('tax-lookup'.'tax-lookup:u'.$id));
    }

    private function grant(string $email, string ...$permissions): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)
            ->update(['extra_permissions' => json_encode(array_merge(['ventas_diseno.read'], $permissions))]);
    }

    private function designerToken(string ...$permissions): string
    {
        $this->grant('vendedor@atlantic.test', 'ventas_diseno.write', ...$permissions);

        return $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
    }

    private function reTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function fakeMsl(array $body, int $status = 200): void
    {
        Http::fake(['sandbox.msl.test/v1/validar-ruc*' => Http::response($body, $status)]);
    }

    // ─── Validación ───────────────────────────────────────────────────────────────────────────────

    public function test_ruc_valido_devuelve_dv_nombre_tipo_y_estado_sin_exponer_la_respuesta_cruda(): void
    {
        $this->fakeMsl(['ok' => true, 'valido' => true, 'ruc' => '155704849-2-2021', 'nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', 'dv' => '32', 'tipoRuc' => '2', 'estado' => 'I', 'ambiente' => 'pruebas']);

        $response = $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])
            ->assertStatus(200);

        $response->assertExactJson([
            'disponible' => true, 'valido' => true, 'nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA',
            'dv' => '32', 'tipo_ruc' => '2', 'estado' => 'I', 'codigo' => 'ok',
        ]);
        $this->assertStringNotContainsString('sandbox-key', (string) $response->getContent());
    }

    public function test_ruc_inexistente_no_es_lo_mismo_que_no_disponible(): void
    {
        $this->fakeMsl(['ok' => true, 'valido' => false]);

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155-0000-1-2026'])
            ->assertStatus(200)
            ->assertJsonPath('disponible', true)
            ->assertJsonPath('valido', false)
            ->assertJsonPath('codigo', 'no_encontrado')
            ->assertJsonPath('nombre', null);
    }

    public function test_un_404_de_negocio_de_msl_es_ruc_inexistente(): void
    {
        $this->fakeMsl(['ok' => false, 'error' => 'RUC no encontrado'], 404);

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155-0000-1-2026'])
            ->assertStatus(200)
            ->assertJsonPath('disponible', true)
            ->assertJsonPath('codigo', 'no_encontrado');
    }

    /** @return array<string, array{0: int}> */
    public static function fallasDeDisponibilidad(): array
    {
        return ['api key rechazada' => [401], 'sin permiso en MSL' => [403], 'limite de tasa' => [429], 'error de servidor' => [503]];
    }

    /** @dataProvider fallasDeDisponibilidad */
    #[\PHPUnit\Framework\Attributes\DataProvider('fallasDeDisponibilidad')]
    public function test_una_falla_de_msl_nunca_se_presenta_como_ruc_invalido_ni_como_5xx(int $status): void
    {
        $this->fakeMsl(['ok' => false, 'error' => 'x'], $status);

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155-0000-1-2026'])
            ->assertStatus(200)
            ->assertJsonPath('disponible', false)
            ->assertJsonPath('valido', null)
            ->assertJsonPath('codigo', 'no_disponible');
    }

    public function test_con_la_fe_apagada_no_se_llama_a_msl(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        Http::fake();

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])
            ->assertStatus(200)
            ->assertJsonPath('disponible', false)
            ->assertJsonPath('codigo', 'apagado');

        Http::assertNothingSent();
    }

    public function test_sin_api_key_configurada_no_se_llama_a_msl(): void
    {
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => '']]);
        Http::fake();

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])
            ->assertStatus(200)
            ->assertJsonPath('codigo', 'sin_credenciales');

        Http::assertNothingSent();
    }

    public function test_cinco_fallas_de_validacion_no_abren_el_circuito_de_certificacion(): void
    {
        $this->fakeMsl(['ok' => false], 503);
        $token = $this->designerToken();

        for ($i = 0; $i < 6; $i++) {
            $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(200);
        }

        // El circuito de consultas quedó abierto: la 6.ª consulta ya no llegó a MSL...
        Http::assertSentCount(5);
        $this->assertNotNull(Redis::get('cb:msl_lookup_pruebas:opened_at'), 'el circuito de consultas debe abrirse');
        // ...pero el de certificación ni se tocó: una ráfaga de validaciones no frena las facturas.
        $this->assertNull(Redis::get('cb:msl_fe_pruebas:opened_at'));
        $this->assertNull(Redis::get('cb:msl_fe_pruebas:failures'));
    }

    public function test_el_log_enmascara_el_ruc(): void
    {
        Log::spy();
        $this->fakeMsl(['ok' => true, 'valido' => true, 'nombre' => 'X', 'dv' => '32']);

        $this->withHeaders($this->authHeaders($this->designerToken()))
            ->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(200);

        Log::shouldHaveReceived('info')->withArgs(fn (string $msg, array $ctx): bool => $msg === 'ruc_lookup'
            && $ctx['ruc'] === TaxIdValidationService::enmascarar('155704849-2-2021')
            && ! str_contains($ctx['ruc'], '704849'))->once();
    }

    public function test_validar_exige_permiso_de_escritura_y_formato(): void
    {
        $this->grant('nivel5@atlantic.test'); // solo lectura
        $sinPermiso = $this->loginAs('nivel5@atlantic.test', 'Password123!');
        $this->withHeaders($this->authHeaders($sinPermiso))->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(403);

        $token = $this->designerToken();
        $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, [])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, ['tax_id' => "155'; DROP TABLE x;--"])->assertStatus(422);
    }

    public function test_la_validacion_tiene_un_limite_de_20_por_minuto_por_usuario(): void
    {
        $this->fakeMsl(['ok' => true, 'valido' => true, 'nombre' => 'X', 'dv' => '32']);
        $this->limpiarLimite('vendedor@atlantic.test');
        $token = $this->designerToken();

        for ($i = 0; $i < 20; $i++) {
            $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(200);
        }

        $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(429);
        $this->limpiarLimite('vendedor@atlantic.test');
    }

    // ─── Edición de datos fiscales ────────────────────────────────────────────────────────────────

    /** @return array{sub: SubClient, card: PipelineCard, order: Order, quote: Quote, rate: AdminContItbmsRate} */
    private function subClienteConPedido(): array
    {
        $master = MasterClient::create(['name' => 'Cliente FE '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Razón Vieja S.A.', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $ownerId = (int) DB::table('atlantic_auth.users')->where('email', 'contabilidad@test.com')->value('id');
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 100, 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name, 'pipeline_card_id' => $card->id,
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ]);

        return ['sub' => $sub, 'card' => $card, 'order' => $order, 'quote' => $quote, 'rate' => AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail()];
    }

    private function facturaDe(array $ctx, ?string $feEstado): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => $ctx['order']->id, 'quote_id' => $ctx['quote']->id,
            'itbms_rate_id' => $ctx['rate']->id, 'sales_project_id' => $ctx['card']->sales_project_id, 'master_client_id' => $ctx['sub']->master_client_id,
            'cliente' => $ctx['sub']->business_name, 'monto' => 100, 'saldo_aplicado' => 0.0,
        ]);
        $invoice->forceFill(['fe_estado' => $feEstado])->save();

        return $invoice;
    }

    /** @return array{business_name: string, tax_id: string, tax_id_dv: string} */
    private function payloadNuevo(): array
    {
        return ['business_name' => 'Razón Nueva, S.A.', 'tax_id' => '155704849-2-2021', 'tax_id_dv' => '32'];
    }

    private function url(SubClient $sub): string
    {
        return "/api/ventas-diseno/clients/sub-clients/{$sub->id}/tax-data";
    }

    public function test_quien_edita_en_ventas_y_diseno_puede_editar_y_queda_en_auditoria(): void
    {
        $ctx = $this->subClienteConPedido();
        $token = $this->designerToken('ventas_diseno.edit');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), $this->payloadNuevo())
            ->assertStatus(200)->assertJsonPath('tax_id_dv', '32')->assertJsonPath('business_name', 'Razón Nueva, S.A.');
        $this->reTenant();

        $fresh = $ctx['sub']->fresh();
        $this->assertSame('155704849-2-2021', $fresh->tax_id);
        $auditoria = DB::table('atlantic_auth.audit_logs')->where('entity_id', (string) $ctx['sub']->id)->where('action', 'like', '%sub_client%')->latest('id')->first();
        $this->assertNotNull($auditoria, 'el cambio debe quedar en audit_logs');
        $this->assertStringContainsString('155-0000-1-2026', (string) $auditoria->old_values);
        $this->assertStringContainsString('155704849-2-2021', (string) $auditoria->new_values);
    }

    public function test_el_permiso_angosto_sin_ventas_diseno_edit_tambien_habilita_la_edicion(): void
    {
        $ctx = $this->subClienteConPedido();
        $token = $this->designerToken('ventas_diseno.edit.client_tax_data');
        // `ventas_diseno.edit` no está en los permisos de este usuario de prueba: solo el angosto.
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(200);
    }

    public function test_sin_ningun_permiso_de_edicion_es_403(): void
    {
        $ctx = $this->subClienteConPedido();
        $this->grant('nivel5@atlantic.test', 'ventas_diseno.write'); // escribe (alta) pero no edita
        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(403);
        $this->reTenant();
        $this->assertSame('155-0000-1-2026', $ctx['sub']->fresh()->tax_id);
    }

    /** @return array<string, array{0: string}> */
    public static function estadosQueBloquean(): array
    {
        return ['certificado' => ['certificado'], 'pendiente' => ['pendiente']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estadosQueBloquean')]
    public function test_409_si_el_cliente_tiene_una_factura_certificada_o_pendiente(string $estado): void
    {
        $ctx = $this->subClienteConPedido();
        $this->facturaDe($ctx, $estado);
        $token = $this->designerToken('ventas_diseno.edit');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(409);
        $this->reTenant();
        $this->assertSame('155-0000-1-2026', $ctx['sub']->fresh()->tax_id, 'nada se modifica si se bloquea');
    }

    public function test_409_si_solo_una_nota_de_credito_esta_certificada(): void
    {
        $ctx = $this->subClienteConPedido();
        $factura = $this->facturaDe($ctx, 'rechazado');
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $ctx['sub']->master_client_id, 'cliente' => $ctx['sub']->business_name,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $factura->id, 'monto' => 10, 'subtotal' => 9.35, 'itbms' => 0.65,
            'motivo' => 'Descuento', 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => 1, 'fecha' => now()->toDateString(),
        ]);
        $nota->forceFill(['fe_estado' => 'certificado'])->save();

        $this->withHeaders($this->authHeaders($this->designerToken('ventas_diseno.edit')))
            ->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(409);
    }

    /** @return array<string, array{0: string|null}> */
    public static function estadosQueNoBloquean(): array
    {
        return ['apagado / no_aplica' => ['no_aplica'], 'rechazado' => ['rechazado'], 'error de configuracion' => ['error_configuracion'], 'sin estado' => [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estadosQueNoBloquean')]
    public function test_se_puede_editar_si_los_documentos_no_estan_certificados_ni_pendientes(?string $estado): void
    {
        $ctx = $this->subClienteConPedido();
        $this->facturaDe($ctx, $estado);

        $this->withHeaders($this->authHeaders($this->designerToken('ventas_diseno.edit')))
            ->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(200);
    }

    public function test_los_documentos_de_otro_subcliente_no_bloquean(): void
    {
        $otro = $this->subClienteConPedido();
        $this->facturaDe($otro, 'certificado');
        $ctx = $this->subClienteConPedido();

        $this->withHeaders($this->authHeaders($this->designerToken('ventas_diseno.edit')))
            ->patchJson($this->url($ctx['sub']), $this->payloadNuevo())->assertStatus(200);
    }

    public function test_enviar_los_mismos_datos_no_se_bloquea_aunque_haya_documentos_certificados(): void
    {
        $ctx = $this->subClienteConPedido();
        $this->facturaDe($ctx, 'certificado');

        $this->withHeaders($this->authHeaders($this->designerToken('ventas_diseno.edit')))
            ->patchJson($this->url($ctx['sub']), ['business_name' => 'Razón Vieja S.A.', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81'])
            ->assertStatus(200);
    }

    public function test_validaciones_de_la_edicion_y_subcliente_inexistente(): void
    {
        $ctx = $this->subClienteConPedido();
        $token = $this->designerToken('ventas_diseno.edit');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), ['business_name' => 'X', 'tax_id' => '155', 'tax_id_dv' => 'ab'])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), [])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->patchJson('/api/ventas-diseno/clients/sub-clients/99999999/tax-data', $this->payloadNuevo())->assertStatus(404);
    }

    public function test_el_seeder_concede_el_permiso_angosto_a_aprobador_y_contabilidad_sin_pisar_lo_que_ya_tenian(): void
    {
        (new SpecialPermissionSeeder())->run();
        $this->reTenant();

        foreach (['gerencia3@test.com', 'contabilidad@test.com'] as $email) {
            $permisos = json_decode((string) DB::table('atlantic_auth.users')->where('email', $email)->value('extra_permissions'), true);
            $this->assertContains('ventas_diseno.edit.client_tax_data', $permisos, $email);
            $this->assertContains('ventas_diseno.edit.conditions', $permisos, "$email conserva lo que ya tenía");
        }
    }

    // ─── Directorio para Contabilidad/Admin&Cont y mensajes ──────────────────────────────────────────────

    private const URL_DIRECTORIO = '/api/ventas-diseno/clients/fiscal-directory';

    public function test_el_directorio_lo_ve_quien_edita_o_tiene_el_permiso_angosto_y_nadie_mas(): void
    {
        $this->subClienteConPedido();

        $this->withHeaders($this->authHeaders($this->designerToken('ventas_diseno.edit')))->getJson(self::URL_DIRECTORIO)->assertStatus(200);
        // Solo el permiso angosto, sin ventas_diseno.read de más: es el caso de Contabilidad desde Admin&Cont.
        $this->grant('vendedor@atlantic.test', 'ventas_diseno.edit.client_tax_data');
        $this->withHeaders($this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer')))->getJson(self::URL_DIRECTORIO)->assertStatus(200);

        $this->grant('nivel5@atlantic.test', 'ventas_diseno.write'); // escribe pero no edita
        $this->withHeaders($this->authHeaders($this->loginAs('nivel5@atlantic.test', 'Password123!')))->getJson(self::URL_DIRECTORIO)->assertStatus(403);
    }

    public function test_el_directorio_busca_por_razon_social_ruc_y_cliente_master_y_marca_los_bloqueados(): void
    {
        $libre = $this->subClienteConPedido();
        $libre['sub']->update(['business_name' => 'Zeta Directorio Libre S.A.', 'tax_id' => '155-ZETA-1']);
        $bloqueado = $this->subClienteConPedido();
        $bloqueado['sub']->update(['business_name' => 'Zeta Directorio Bloqueado S.A.', 'tax_id' => '155-ZETA-2']);
        $this->facturaDe($bloqueado, 'certificado');
        $nombreMaster = $libre['sub']->masterClient->name; // antes de las peticiones HTTP: luego el tenant se olvida
        $token = $this->designerToken('ventas_diseno.edit');

        $porNombre = $this->withHeaders($this->authHeaders($token))->getJson(self::URL_DIRECTORIO.'?search=zeta directorio')->assertStatus(200);
        $this->assertSame(['Zeta Directorio Bloqueado S.A.', 'Zeta Directorio Libre S.A.'], array_column($porNombre->json('data'), 'business_name'));
        $filas = collect($porNombre->json('data'))->keyBy('id');
        $this->assertTrue($filas[$bloqueado['sub']->id]['bloqueado']);
        $this->assertFalse($filas[$libre['sub']->id]['bloqueado']);
        $this->assertSame($nombreMaster, $filas[$libre['sub']->id]['master_client_name']);

        $porRuc = $this->withHeaders($this->authHeaders($token))->getJson(self::URL_DIRECTORIO.'?search=155-ZETA-2')->json('data');
        $this->assertCount(1, $porRuc);
        $porMaster = $this->withHeaders($this->authHeaders($token))->getJson(self::URL_DIRECTORIO.'?search='.urlencode($nombreMaster))->json('data');
        $this->assertContains($libre['sub']->id, array_column($porMaster, 'id'));
    }

    public function test_el_directorio_trata_los_comodines_como_texto_y_limita_el_tamano_de_pagina(): void
    {
        $this->subClienteConPedido();
        $token = $this->designerToken('ventas_diseno.edit');

        // `%` y `_` del usuario no deben actuar como comodines: una búsqueda de solo "%" no devuelve todo.
        $this->assertSame([], $this->withHeaders($this->authHeaders($token))->getJson(self::URL_DIRECTORIO.'?search=%25')->json('data'));

        $todos = $this->withHeaders($this->authHeaders($token))->getJson(self::URL_DIRECTORIO.'?per_page=all')->assertStatus(200);
        $this->assertLessThanOrEqual(100, count($todos->json('data')), 'per_page=all se recorta a 100');
        $this->assertSame(100, $todos->json('meta.per_page'));
    }

    public function test_validar_acepta_tambien_el_permiso_angosto_sin_ventas_diseno_write(): void
    {
        $this->fakeMsl(['ok' => true, 'valido' => true, 'nombre' => 'X', 'dv' => '32']);
        $this->grant('nivel5@atlantic.test', 'ventas_diseno.edit.client_tax_data'); // como Contabilidad: sin .write
        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, ['tax_id' => '155704849-2-2021'])->assertStatus(200);
    }

    public function test_los_errores_de_validacion_salen_en_espanol(): void
    {
        $ctx = $this->subClienteConPedido();
        $token = $this->designerToken('ventas_diseno.edit');

        $edicion = $this->withHeaders($this->authHeaders($token))->patchJson($this->url($ctx['sub']), ['business_name' => '', 'tax_id' => '155', 'tax_id_dv' => 'ab'])->assertStatus(422);
        $this->assertSame('La razón social es obligatoria.', $edicion->json('errors.business_name.0'));
        $this->assertSame('El DV debe tener 1 o 2 dígitos.', $edicion->json('errors.tax_id_dv.0'));

        $validacion = $this->withHeaders($this->authHeaders($token))->postJson(self::URL_VALIDAR, [])->assertStatus(422);
        $this->assertSame('Ingresa el RUC o la cédula a validar.', $validacion->json('errors.tax_id.0'));
    }
}
