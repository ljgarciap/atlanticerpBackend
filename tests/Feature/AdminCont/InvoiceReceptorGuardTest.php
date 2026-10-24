<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Auth\Models\AuditLog;
use App\Shared\Services\AuditService;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\FeatureTestCase;

/**
 * Guarda de RUC al facturar (docs/specs/guarda-validacion-ruc-al-facturar.md, decisiones de Luis 2026-10-07): la vista
 * previa informa si el receptor valida contra la DGI y el guardado no crea NINGUNA factura del lote mientras alguno no
 * valide. Cada test registra su propio `validar-ruc` (este archivo usa la guarda real; el resto de los tests de facturación la sustituyen con `StubsReceptorGuard`).
 */
final class InvoiceReceptorGuardTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;

    private const URL_FACTURAS = '/api/admin-contab/invoices';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->ambiente('testing');
        $this->limpiarCircuitos();
    }

    protected function tearDown(): void
    {
        $this->limpiarCircuitos();
        parent::tearDown();
    }

    /** RUC que usan estos tests: la guarda cachea 60 s por RUC y ambiente, y el estado de la caché sobrevive entre tests. */
    private const RUCS = ['1689571-1-684168', '1689671-1-68', '1689571-1-111111'];

    private function limpiarCircuitos(): void
    {
        foreach (['testing', 'production', 'apagado'] as $ambiente) {
            foreach (self::RUCS as $ruc) {
                Cache::forget(ReceptorFiscalGuard::claveCache($ambiente, $ruc));
            }
        }

        foreach (['msl_lookup_pruebas', 'msl_fe_pruebas', 'msl_lookup_produccion', 'msl_fe_produccion'] as $nombre) {
            Redis::del("cb:{$nombre}:failures", "cb:{$nombre}:opened_at");
        }
    }

    private function ambiente(string $ambiente, string $apiKey = 'sandbox-key'): void
    {
        config([
            'services.msl_fe.sandbox'    => ['base_url' => 'https://sandbox.msl.test', 'api_key' => $apiKey],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => $apiKey],
        ]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => $ambiente])->save();
    }

    /** @param array<string, mixed> $body */
    private function dgi(array $body, int $status = 200): void
    {
        Http::fake(['*/v1/validar-ruc*' => Http::response($body, $status)]);
    }

    private function dgiValida(string $nombre, string $dv): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => $nombre, 'dv' => $dv, 'tipoRuc' => '2', 'estado' => 'I']);
    }

    /** @return array{pedido: array<string, mixed>, sub: SubClient} */
    private function pedidoConReceptor(string $nombre = 'CONSTRUCTORA GRUPO V, SA', string $ruc = '1689571-1-684168', ?string $dv = '33'): array
    {
        $pedido = $this->makePedido([['desc' => 'Lámpara', 'qty' => 2, 'price' => 100.0]], [[2]]);
        $sub    = SubClient::findOrFail($pedido['card']->sub_client_id);
        $sub->forceFill(['business_name' => $nombre, 'tax_id' => $ruc, 'tax_id_dv' => $dv])->save();

        return ['pedido' => $pedido, 'sub' => $sub];
    }

    private function cuantasFacturas(): int
    {
        return AdminContInvoice::query()->count();
    }

    // ─── Vista previa ─────────────────────────────────────────────────────────────────────────

    public function test_preview_informa_receptor_ok_cuando_la_dgi_coincide_aunque_la_puntuacion_del_nombre_difiera(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V  S A  ', '33');
        ['pedido' => $p] = $this->pedidoConReceptor();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertSame('ok', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
    }

    public function test_preview_marca_bloqueo_cuando_la_dgi_no_reconoce_el_ruc(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertSame('no_encontrado', $r->json('results.0.receptor_validacion.estado'));
        $this->assertTrue($r->json('results.0.receptor_validacion.bloquea'));
        $this->assertTrue($r->json('results.0.receptor_validacion.corregible'));
        $this->assertSame($sub->id, $r->json('results.0.receptor_validacion.sub_client_id'));
    }

    public function test_dv_faltante_o_distinto_bloquea_e_informa_el_dv_de_la_dgi(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');

        foreach ([null, '99'] as $dvGuardado) {
            ['pedido' => $p] = $this->pedidoConReceptor(dv: $dvGuardado);
            $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

            $this->assertSame('dv_distinto', $r->json('results.0.receptor_validacion.estado'));
            $this->assertTrue($r->json('results.0.receptor_validacion.bloquea'));
            $this->assertSame('33', $r->json('results.0.receptor_validacion.dv_dgi'));
        }
    }

    public function test_razon_social_distinta_no_bloquea_e_informa_el_nombre_de_la_dgi(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        // SCRUM-1140: informativo — viaja la razón social de la DGI y "Actualizar cliente" es opcional.
        $this->assertSame('nombre_distinto', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
        $this->assertFalse($r->json('results.0.receptor_validacion.corregible'));
        $this->assertTrue($r->json('results.0.receptor_validacion.actualizable'));
        $this->assertSame('CONSTRUCTORA GRUPO V S A', $r->json('results.0.receptor_validacion.nombre_dgi'));
    }

    public function test_razon_social_distinta_permite_guardar_la_factura(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);
    }

    public function test_cedula_sin_dv_en_la_dgi_no_bloquea_aunque_el_cliente_no_tenga_dv(): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => 'JUAN PEREZ', 'tipoRuc' => '1', 'estado' => 'I']);
        ['pedido' => $p] = $this->pedidoConReceptor('JUAN PEREZ', '1689571-1-684168', null);

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertSame('ok', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
    }

    // ─── Receptor resuelto (SCRUM-1140) ───────────────────────────────────────────────────────

    public function test_receptor_resuelto_usa_la_razon_social_y_el_dv_de_la_dgi(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');
        $guard = app(ReceptorFiscalGuard::class);

        $snapshot = $guard->receptorResuelto($guard->evaluar($sub));

        $this->assertNotNull($snapshot);
        $this->assertSame('01', $snapshot->tipo);
        $this->assertSame('1689571-1-684168', $snapshot->ruc);
        $this->assertSame('33', $snapshot->dv);
        $this->assertSame('CONSTRUCTORA GRUPO V S A', $snapshot->nombre, 'Viaja la razón social de la DGI, no la de AtlanticERP.');
        $this->assertNotNull($snapshot->validadoAt);
    }

    public function test_receptor_resuelto_de_una_cedula_sin_dv_en_la_dgi_no_lleva_dv(): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => 'JUAN PEREZ', 'tipoRuc' => '1', 'estado' => 'I']);
        ['sub' => $sub] = $this->pedidoConReceptor('JUAN PEREZ', '1689571-1-684168', '55');
        $guard = app(ReceptorFiscalGuard::class);

        $snapshot = $guard->receptorResuelto($guard->evaluar($sub));

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->dv, 'El DV solo viaja si la DGI lo trajo.');
    }

    public function test_receptor_resuelto_es_nulo_cuando_la_evaluacion_bloquea(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);
        $guard = app(ReceptorFiscalGuard::class);

        $this->assertNull($guard->receptorResuelto($guard->evaluar($sub)));
    }

    public function test_receptor_resuelto_de_consumidor_final_y_de_pac_apagado(): void
    {
        $guard = app(ReceptorFiscalGuard::class);

        ['sub' => $cf] = $this->pedidoConReceptor();
        $cf->forceFill(['receptor_tipo' => ReceptorTipo::CONSUMIDOR_FINAL, 'tax_id' => '', 'tax_id_dv' => null])->save();
        $this->assertSame('02', $guard->receptorResuelto($guard->evaluar($cf->fresh()))?->tipo);
        $this->assertSame('02', $guard->receptorResuelto($guard->evaluar(null))?->tipo, 'Pedido sin cliente fiscal => consumidor final, como el builder.');

        $this->ambiente('apagado');
        ['sub' => $apagado] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689571-1-684168', '33');
        $snapshot = $guard->receptorResuelto($guard->evaluar($apagado));
        $this->assertSame('01', $snapshot?->tipo);
        $this->assertSame('CONSTRUCTORA GRUPO V, SA', $snapshot?->nombre, 'Sin validación viaja la razón social de AtlanticERP, como hoy.');
        $this->assertNull($snapshot?->validadoAt);
        Http::assertNothingSent();
    }

    public function test_la_respuesta_definitiva_de_la_dgi_se_recuerda_y_una_falla_no(): void
    {
        $valida = ['ok' => true, 'valido' => true, 'nombre' => 'CONSTRUCTORA GRUPO V S A', 'dv' => '33', 'tipoRuc' => '2', 'estado' => 'I'];
        Http::fake(['*/v1/validar-ruc*' => Http::sequence()->push($valida)->push([], 503)->push($valida)]);
        ['pedido' => $p] = $this->pedidoConReceptor();
        $token = $this->token();
        $estado = fn () => $this->api($token, 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])
            ->assertStatus(200)->json('results.0.receptor_validacion.estado');

        $this->assertSame('ok', $estado());
        $this->assertSame('ok', $estado());
        $this->assertSame('ok', $estado());
        Http::assertSentCount(1); // tres vistas previas, una sola consulta a la DGI

        // Una falla de la DGI nunca se cachea: en cuanto responde otra vez, la siguiente consulta ya lo ve.
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', '1689571-1-684168'));
        $this->assertSame('no_disponible', $estado());
        $this->assertSame('ok', $estado());
        Http::assertSentCount(3);
    }

    public function test_estado_i_de_la_dgi_no_bloquea(): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => 'CONSTRUCTORA GRUPO V SA', 'dv' => '33', 'tipoRuc' => '2', 'estado' => 'I']);
        ['pedido' => $p] = $this->pedidoConReceptor();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
    }

    // ─── Sin validación disponible ────────────────────────────────────────────────────────────

    public function test_sin_validacion_disponible_bloquea_en_testing_y_en_production(): void
    {
        foreach (['testing', 'production'] as $ambiente) {
            $this->ambiente($ambiente);
            $this->dgi([], 503);
            $this->limpiarCircuitos();
            ['pedido' => $p] = $this->pedidoConReceptor();

            $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

            $this->assertSame('no_disponible', $r->json('results.0.receptor_validacion.estado'), $ambiente);
            $this->assertTrue($r->json('results.0.receptor_validacion.bloquea'), $ambiente);
            $this->assertFalse($r->json('results.0.receptor_validacion.corregible'), 'Una falla de la DGI no se corrige editando el RUC.');
        }
    }

    public function test_sin_credenciales_bloquea_en_testing(): void
    {
        $this->ambiente('testing', apiKey: '');
        Http::fake();
        ['pedido' => $p] = $this->pedidoConReceptor();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertTrue($r->json('results.0.receptor_validacion.bloquea'));
        Http::assertNothingSent();
    }

    public function test_con_el_pac_apagado_no_consulta_a_msl_y_deja_pasar_con_aviso(): void
    {
        $this->ambiente('apagado');
        Http::fake();
        ['pedido' => $p] = $this->pedidoConReceptor();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertSame('no_validado', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
        Http::assertNothingSent();
    }

    public function test_cliente_sin_ruc_bloquea_con_el_pac_encendido_y_pasa_con_aviso_con_el_pac_apagado(): void
    {
        Http::fake();
        ['pedido' => $p] = $this->pedidoConReceptor('CLIENTE SIN RUC SA', '', null);

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);
        $this->assertSame('sin_ruc', $r->json('results.0.receptor_validacion.estado'));
        $this->assertTrue($r->json('results.0.receptor_validacion.bloquea'));

        $this->ambiente('apagado');
        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);
        $this->assertSame('no_validado', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
        Http::assertNothingSent();
    }

    public function test_consumidor_final_no_se_valida(): void
    {
        Http::fake();
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor();
        $sub->forceFill(['receptor_tipo' => ReceptorTipo::CONSUMIDOR_FINAL, 'tax_id' => '', 'tax_id_dv' => null])->save();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS.'/preview', ['order_ids' => [$p['root']->id]])->assertStatus(200);

        $this->assertSame('no_aplica', $r->json('results.0.receptor_validacion.estado'));
        $this->assertFalse($r->json('results.0.receptor_validacion.bloquea'));
        Http::assertNothingSent();
    }

    // ─── Guardado ─────────────────────────────────────────────────────────────────────────────

    public function test_guardar_con_ruc_invalido_no_crea_la_factura_ni_certifica(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $p] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);
        $antes = $this->cuantasFacturas();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(422);

        $this->assertSame($antes, $this->cuantasFacturas());
        $this->assertSame('no_encontrado', $r->json('errors.0.receptor.estado'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/documentos') || $req->method() === 'POST');
    }

    public function test_guardar_con_receptor_valido_crea_la_factura(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p] = $this->pedidoConReceptor();

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);

        $this->assertSame(1, AdminContInvoice::where('order_id', $p['root']->id)->count());
    }

    public function test_si_un_receptor_del_lote_es_invalido_no_se_crea_ninguna_factura(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $bueno]  = $this->pedidoConReceptor();
        ['pedido' => $malo]   = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689571-1-684168', '99'); // DV que no coincide
        $antes = $this->cuantasFacturas();

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$bueno['root']->id, $malo['root']->id]])->assertStatus(422);

        $this->assertSame($antes, $this->cuantasFacturas(), 'Decisión 6: se bloquean todas.');
        $this->assertCount(2, $r->json('errors'));
        $porPedido = collect($r->json('errors'))->keyBy('order_id');
        $this->assertArrayNotHasKey('receptor', $porPedido[$bueno['root']->id]);
        $this->assertSame('dv_distinto', $porPedido[$malo['root']->id]['receptor']['estado']);
    }

    // ─── Corrección del receptor ──────────────────────────────────────────────────────────────

    private function corregir(string $token, int $subClientId, array $data)
    {
        return $this->api($token, 'PATCH', self::URL_FACTURAS."/receptors/{$subClientId}", $data);
    }

    /** SCRUM-1140 — Felix ya tiene `receptor_consumidor_final` por el seeder; para probar el 403 se le quita. */
    private function sinPermisoConsumidorFinal(): string
    {
        $slug   = (string) \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->value('slug');
        $actual = json_decode((string) DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)->value('extra_permissions'), true) ?: [];
        DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)
            ->update(['extra_permissions' => json_encode(array_values(array_diff($actual, [self::PERMISO_CF])))]);

        return $this->token();
    }

    private function sinPermiso(): string
    {
        $slug = (string) \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->value('slug');
        $actual = json_decode((string) DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)->value('extra_permissions'), true) ?: [];
        DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)
            ->update(['extra_permissions' => json_encode(array_values(array_diff($actual, ['admin_contab.edit.receptor_fiscal'])))]);

        return $this->token();
    }

    public function test_corregir_sin_el_permiso_nuevo_da_403_y_no_guarda(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $this->corregir($this->sinPermiso(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(403);

        $this->assertSame('1689671-1-68', $sub->fresh()->tax_id);
    }

    public function test_corregir_con_datos_que_la_dgi_no_reconoce_da_422_y_no_guarda(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $r = $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-111111', 'tax_id_dv' => '33'])
            ->assertStatus(422);

        $this->assertSame('no_encontrado', $r->json('receptor.estado'));
        $this->assertSame('1689671-1-68', $sub->fresh()->tax_id, 'Un RUC que no valida nunca llega a la base.');
    }

    public function test_corregir_con_datos_que_la_dgi_confirma_guarda_y_la_factura_pasa(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V  S A  ', '33');
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);
        $token = $this->token();

        $this->corregir($token, $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(200)->assertJsonPath('receptor.estado', 'ok');

        $fresh = $sub->fresh();
        $this->assertSame('1689571-1-684168', $fresh->tax_id);
        $this->assertSame('33', $fresh->tax_id_dv);
        $this->api($token, 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);
    }

    public function test_corregir_rechaza_caracteres_raros_en_la_razon_social_aunque_normalizada_coincida(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $this->corregir($this->token(), $sub->id, ['business_name' => '<"CONSTRUCTORA & GRUPO V; SA>\'', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(422)->assertJsonValidationErrors(['business_name']);

        $this->assertSame('1689671-1-68', $sub->fresh()->tax_id);
    }

    public function test_corregir_no_acepta_cambiar_a_consumidor_final(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['sub' => $sub] = $this->pedidoConReceptor();

        $this->corregir($this->token(), $sub->id, ['business_name' => 'X', 'tax_id' => '', 'tax_id_dv' => '', 'receptor_tipo' => '02'])
            ->assertStatus(422);

        $this->assertNull($sub->fresh()->receptor_tipo);
    }

    public function test_corregir_un_cliente_con_documentos_certificados_da_409(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor();
        $token = $this->token();
        $this->api($token, 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);
        AdminContInvoice::query()->update(['fe_estado' => 'certificado']);

        $this->corregir($token, $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(409);
    }

    public function test_corregir_no_guarda_una_razon_social_distinta_de_la_de_la_dgi(): void
    {
        // SCRUM-1140: la diferencia ya no bloquea la factura, pero este endpoint guarda en el cliente y exige coincidencia.
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $r = $this->corregir($this->token(), $sub->id, ['business_name' => 'OTRA EMPRESA, S.A.', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(422);

        $this->assertSame('nombre_distinto', $r->json('receptor.estado'));
        $this->assertSame('CONSTRUCTORA GRUPO V, SA', $sub->fresh()->business_name);
    }

    public function test_corregir_actualiza_la_razon_social_con_la_de_la_dgi(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V S A', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(200)->assertJsonPath('receptor.estado', 'ok');

        $this->assertSame('CONSTRUCTORA GRUPO V S A', $sub->fresh()->business_name);
    }

    public function test_corregir_audita_la_actualizacion_de_la_razon_social(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V S A', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])->assertStatus(200);

        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_SUB_CLIENT_RAZON_SOCIAL_ACTUALIZADA)->firstOrFail();
        $this->assertSame((string) $sub->id, $auditoria->entity_id);
        $this->assertSame('OTRA EMPRESA, S.A.', $auditoria->old_values['business_name']);
        $this->assertSame('CONSTRUCTORA GRUPO V S A', $auditoria->new_values['business_name']);
        $this->assertSame('vista_previa_factura', $auditoria->metadata['origen']);
    }

    public function test_corregir_sin_cambiar_la_razon_social_no_genera_el_evento_de_razon_social(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V S A', '1689671-1-68', null);

        $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V S A', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])->assertStatus(200);

        $this->assertNull(AuditLog::query()->where('action', AuditService::ADMIN_CONT_SUB_CLIENT_RAZON_SOCIAL_ACTUALIZADA)->first());
    }

    public function test_corregir_acepta_una_cedula_sin_dv_cuando_la_dgi_no_trae_dv(): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => 'JUAN PEREZ', 'tipoRuc' => '1', 'estado' => 'I']);
        ['sub' => $sub] = $this->pedidoConReceptor('JUAN PEREZ', '1689671-1-68', null);

        $this->corregir($this->token(), $sub->id, ['business_name' => 'JUAN PEREZ', 'tax_id' => '1689571-1-684168'])
            ->assertStatus(200)->assertJsonPath('receptor.estado', 'ok');

        $this->assertNull($sub->fresh()->tax_id_dv);
    }

    public function test_corregir_sin_dv_cuando_la_dgi_lo_trae_da_422(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V S A', '1689671-1-68', null);

        $r = $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V S A', 'tax_id' => '1689571-1-684168'])
            ->assertStatus(422);

        $this->assertSame('dv_distinto', $r->json('receptor.estado'));
    }

    // ─── Receptor congelado y consumidor final (SCRUM-1140) ───────────────────────────────────

    private const PERMISO_CF = 'admin_contab.edit.receptor_consumidor_final';

    /** Felix con el permiso de facturar a Consumidor Final (el seeder lo concede en T5; acá se asigna a mano para probar la regla). */
    private function conPermisoConsumidorFinal(): string
    {
        $slug   = (string) \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->value('slug');
        $actual = json_decode((string) DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)->value('extra_permissions'), true) ?: [];
        DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)
            ->update(['extra_permissions' => json_encode(array_values(array_unique([...$actual, self::PERMISO_CF])))]);

        return $this->token();
    }

    public function test_guardar_congela_la_razon_social_de_la_dgi_y_el_cliente_no_cambia(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);

        $factura = AdminContInvoice::findOrFail($r->json('invoices.0.id'));
        $this->assertSame('01', $factura->receptor_tipo);
        $this->assertSame('1689571-1-684168', $factura->receptor_ruc);
        $this->assertSame('33', $factura->receptor_dv);
        $this->assertSame('CONSTRUCTORA GRUPO V S A', $factura->receptor_nombre, 'Viaja la razón social de la DGI.');
        $this->assertNotNull($factura->receptor_validado_at);
        $this->assertSame('OTRA EMPRESA, S.A.', $sub->fresh()->business_name, 'El cliente no se modifica.');
    }

    public function test_cedula_sin_dv_en_la_dgi_congela_dv_nulo(): void
    {
        $this->dgi(['ok' => true, 'valido' => true, 'nombre' => 'JUAN PEREZ', 'tipoRuc' => '1', 'estado' => 'I']);
        ['pedido' => $p] = $this->pedidoConReceptor('JUAN PEREZ', '1689571-1-684168', '55');

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);

        $this->assertNull(AdminContInvoice::findOrFail($r->json('invoices.0.id'))->receptor_dv);
    }

    public function test_con_el_pac_apagado_congela_el_receptor_del_cliente_sin_validar(): void
    {
        $this->ambiente('apagado');
        ['pedido' => $p] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689571-1-684168', '33');

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);

        $factura = AdminContInvoice::findOrFail($r->json('invoices.0.id'));
        $this->assertSame('01', $factura->receptor_tipo);
        $this->assertSame('CONSTRUCTORA GRUPO V, SA', $factura->receptor_nombre);
        $this->assertNull($factura->receptor_validado_at);
        Http::assertNothingSent();
    }

    public function test_consumidor_final_sin_el_permiso_da_403_y_no_crea_nada(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $p] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);
        $antes = $this->cuantasFacturas();

        $this->api($this->sinPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id], 'consumidor_final_order_ids' => [$p['root']->id]])
            ->assertStatus(403);
        $this->assertNotNull(AuditLog::query()->where('action', AuditService::SECURITY_ACCESS_DENIED)->where('entity_id', 'api/admin-contab/invoices')->first(), 'El 403 deja rastro.');

        $this->assertSame($antes, $this->cuantasFacturas());
    }

    public function test_consumidor_final_como_alternativa_a_un_documento_que_no_valida(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $p, 'sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id], 'consumidor_final_order_ids' => [$p['root']->id]])
            ->assertStatus(201);

        $factura = AdminContInvoice::findOrFail($r->json('invoices.0.id'));
        $this->assertSame('02', $factura->receptor_tipo);
        $this->assertNull($factura->receptor_ruc);
        $this->assertNull($factura->receptor_dv);
        $this->assertSame('CONSUMIDOR FINAL', $factura->receptor_nombre);
        $this->assertNull($factura->receptor_validado_at);
        $this->assertSame('1689671-1-68', $sub->fresh()->tax_id, 'El cliente conserva su documento.');

        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_INVOICE_RECEPTOR_CONSUMIDOR_FINAL)->firstOrFail();
        $this->assertSame((string) $factura->id, $auditoria->entity_id);
        $this->assertSame('no_encontrado', $auditoria->metadata['causa']);
        $this->assertSame('16********68', $auditoria->metadata['documento'], 'El documento va enmascarado.');
    }

    public function test_consumidor_final_como_decision_consciente_con_un_documento_valido(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $p] = $this->pedidoConReceptor();

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id], 'consumidor_final_order_ids' => [$p['root']->id]])
            ->assertStatus(201);

        $this->assertSame('02', AdminContInvoice::findOrFail($r->json('invoices.0.id'))->receptor_tipo);
        $this->assertSame('decision_consciente', AuditLog::query()->where('action', AuditService::ADMIN_CONT_INVOICE_RECEPTOR_CONSUMIDOR_FINAL)->firstOrFail()->metadata['causa']);
    }

    public function test_lote_mixto_consumidor_final_y_contribuyente_valido_crea_las_dos(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['pedido' => $cf]   = $this->pedidoConReceptor('CLIENTE UNO, S.A.', '1689671-1-68', null); // la DGI responde válido para cualquier RUC en este fake
        ['pedido' => $bueno] = $this->pedidoConReceptor();

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, [
            'order_ids' => [$cf['root']->id, $bueno['root']->id], 'consumidor_final_order_ids' => [$cf['root']->id],
        ])->assertStatus(201);

        $tipos = collect($r->json('invoices'))->mapWithKeys(fn (array $i): array => [$i['order_id'] => AdminContInvoice::findOrFail($i['id'])->receptor_tipo]);
        $this->assertSame('02', $tipos[$cf['root']->id]);
        $this->assertSame('01', $tipos[$bueno['root']->id]);
    }

    public function test_un_receptor_que_bloquea_detiene_el_lote_aunque_otro_sea_consumidor_final(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $cf]   = $this->pedidoConReceptor('CLIENTE UNO, S.A.', '1689671-1-68', null);
        ['pedido' => $malo] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689571-1-111111', null);
        $antes = $this->cuantasFacturas();

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, [
            'order_ids' => [$cf['root']->id, $malo['root']->id], 'consumidor_final_order_ids' => [$cf['root']->id],
        ])->assertStatus(422);

        $this->assertSame($antes, $this->cuantasFacturas(), 'Decisión 6: se bloquean todas.');
        $porPedido = collect($r->json('errors'))->keyBy('order_id');
        $this->assertArrayHasKey('receptor', $porPedido[$malo['root']->id]);
    }

    public function test_consumidor_final_exige_que_el_pedido_este_en_el_lote(): void
    {
        ['pedido' => $p]    = $this->pedidoConReceptor();
        ['pedido' => $otro] = $this->pedidoConReceptor();

        $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id], 'consumidor_final_order_ids' => [$otro['root']->id]])
            ->assertStatus(422);
    }

    // ─── Senior Review / Cybersecurity de SCRUM-1140 ──────────────────────────────────────────

    public function test_actualizar_cliente_acepta_la_razon_social_de_la_dgi_aunque_tenga_caracteres_fuera_de_la_regex_del_formulario(): void
    {
        $nombreDgi = 'ACME: "EL GRANDE" @ SERVICIOS; S.A.';
        $this->dgiValida($nombreDgi, '33');
        ['sub' => $sub] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $this->corregir($this->token(), $sub->id, ['business_name' => $nombreDgi, 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(200)->assertJsonPath('receptor.estado', 'ok');

        $this->assertSame($nombreDgi, $sub->fresh()->business_name);
    }

    public function test_corregir_a_mano_sigue_rechazando_caracteres_raros_que_no_son_la_razon_social_de_la_dgi(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        ['sub' => $sub] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA');

        $this->corregir($this->token(), $sub->id, ['business_name' => 'CONSTRUCTORA GRUPO V; S A', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(422);
        $this->corregir($this->token(), $sub->id, ['business_name' => '<b>CONSTRUCTORA GRUPO V S A</b>', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])
            ->assertStatus(422);

        $this->assertSame('CONSTRUCTORA GRUPO V, SA', $sub->fresh()->business_name);
    }

    public function test_el_nombre_de_la_dgi_se_sanea_antes_de_congelarse(): void
    {
        $largo = str_repeat('RAZON SOCIAL LARGA ', 20); // ~380 caracteres
        $this->dgiValida("CONSTRUCTORA\x07 GRUPO V\n{$largo}", '33');
        ['pedido' => $p] = $this->pedidoConReceptor('OTRA EMPRESA, S.A.');

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id]])->assertStatus(201);

        $nombre = (string) AdminContInvoice::findOrFail($r->json('invoices.0.id'))->receptor_nombre;
        $this->assertLessThanOrEqual(255, mb_strlen($nombre));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $nombre, 'Sin caracteres de control.');
        $this->assertStringStartsWith('CONSTRUCTORA GRUPO V', $nombre);
    }

    public function test_el_evento_de_consumidor_final_lleva_numero_y_monto_de_la_factura(): void
    {
        $this->dgi(['ok' => true, 'valido' => false]);
        ['pedido' => $p] = $this->pedidoConReceptor('CONSTRUCTORA GRUPO V, SA', '1689671-1-68', null);

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, ['order_ids' => [$p['root']->id], 'consumidor_final_order_ids' => [$p['root']->id]])
            ->assertStatus(201);

        $factura   = AdminContInvoice::findOrFail($r->json('invoices.0.id'));
        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_INVOICE_RECEPTOR_CONSUMIDOR_FINAL)->firstOrFail();
        $this->assertSame($factura->numero, $auditoria->metadata['numero']);
        $this->assertEquals((float) $factura->monto, (float) $auditoria->metadata['monto']);
    }

    public function test_consumidor_final_por_entrega_en_modo_entrega(): void
    {
        $this->dgiValida('CONSTRUCTORA GRUPO V S A', '33');
        $p = $this->makePedido([['desc' => 'Lámpara', 'qty' => 4, 'price' => 100.0]], [[2], [2]]);
        $sub = SubClient::findOrFail($p['card']->sub_client_id);
        $sub->forceFill(['business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33'])->save();
        $entrega = $p['entregas'][0];

        $r = $this->api($this->conPermisoConsumidorFinal(), 'POST', self::URL_FACTURAS, [
            'order_ids' => [$entrega->id], 'modo' => 'entrega', 'consumidor_final_order_ids' => [$entrega->id],
        ])->assertStatus(201);

        $factura = AdminContInvoice::findOrFail($r->json('invoices.0.id'));
        $this->assertSame('02', $factura->receptor_tipo);
        $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $factura->tipo);
    }
}
