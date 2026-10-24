<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

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
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1003 — "Tipo de cliente ante la DGI" por SubCliente: Contribuyente (01, default) o Consumidor Final (02).
 * Decisiones de Luis (2026-09-29): selector explícito, sin inferir de la categoría; solo 01/02; sin reclasificar los
 * existentes (NULL = 01); con 02 no hay RUC ni DV.
 */
final class ReceptorTipoTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(string ...$permissions): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(array_merge(['ventas_diseno.read', 'ventas_diseno.write'], $permissions))]);

        return $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
    }

    private function reTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, mixed> */
    private function altaCliente(string $sufijo, array $fiscal): array
    {
        return array_merge([
            'name' => "Cliente RT {$sufijo}", 'business_name' => "Subcliente RT {$sufijo}",
            'contacts' => [['name' => 'Ana Pérez', 'role' => 'client', 'phone' => '6000-0000']],
        ], $fiscal);
    }

    // ─── Alta ─────────────────────────────────────────────────────────────────────────────────────

    public function test_alta_de_consumidor_final_no_exige_ruc_ni_dv_y_los_guarda_vacios(): void
    {
        $respuesta = $this->withHeaders($this->authHeaders($this->token('ventas_diseno.edit.client_tax_data')))
            ->postJson('/api/ventas-diseno/clients', $this->altaCliente('CF', ['receptor_tipo' => '02']))
            ->assertStatus(201);
        $this->reTenant();

        $sub = SubClient::findOrFail($respuesta->json('sub_clients.0.id'));
        $this->assertSame('02', $sub->receptor_tipo);
        $this->assertSame('', $sub->tax_id);
        $this->assertNull($sub->tax_id_dv);
        $respuesta->assertJsonPath('sub_clients.0.receptor_tipo', '02');
    }

    public function test_con_consumidor_final_el_ruc_y_dv_enviados_se_ignoran(): void
    {
        $respuesta = $this->withHeaders($this->authHeaders($this->token('ventas_diseno.edit.client_tax_data')))
            ->postJson('/api/ventas-diseno/clients', $this->altaCliente('CF2', ['receptor_tipo' => '02', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']))
            ->assertStatus(201);
        $this->reTenant();

        $sub = SubClient::findOrFail($respuesta->json('sub_clients.0.id'));
        $this->assertSame('', $sub->tax_id);
        $this->assertNull($sub->tax_id_dv);
    }

    public function test_sin_tipo_el_alta_sigue_siendo_contribuyente_y_exige_ruc(): void
    {
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('Sin', []))
            ->assertStatus(422)->assertJsonValidationErrors(['tax_id'])->assertJsonMissingValidationErrors(['tax_id_dv']); // SCRUM-1140: el DV es opcional
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('Sin2', ['receptor_tipo' => '01']))
            ->assertStatus(422)->assertJsonValidationErrors(['tax_id'])->assertJsonMissingValidationErrors(['tax_id_dv']);

        $ok = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->altaCliente('Ok', ['tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']))
            ->assertStatus(201);
        $this->reTenant();
        $this->assertSame('01', SubClient::findOrFail($ok->json('sub_clients.0.id'))->receptor_tipo, 'el tipo queda explícito');
    }

    public function test_solo_se_admiten_contribuyente_y_consumidor_final(): void
    {
        foreach (['03', '04', 'xx'] as $tipo) {
            $this->withHeaders($this->authHeaders($this->token()))
                ->postJson('/api/ventas-diseno/clients', $this->altaCliente("T{$tipo}", ['receptor_tipo' => $tipo, 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']))
                ->assertStatus(422)->assertJsonValidationErrors(['receptor_tipo']);
        }
    }

    public function test_los_errores_de_validacion_del_alta_salen_en_espanol(): void
    {
        $token = $this->token();

        $sinRuc = $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('Msg', []))->assertStatus(422);
        $this->assertSame('El RUC o cédula es obligatorio.', $sinRuc->json('errors.tax_id.0'));
        $this->assertNull($sinRuc->json('errors.tax_id_dv'), 'SCRUM-1140: el DV ya no es obligatorio.');

        $tipo = $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('Msg2', ['receptor_tipo' => '03', 'tax_id' => '155-1', 'tax_id_dv' => '81']))->assertStatus(422);
        $this->assertSame('El tipo de cliente ante la DGI debe ser Contribuyente o Consumidor Final.', $tipo->json('errors.receptor_tipo.0'));
    }

    public function test_agregar_un_subcliente_consumidor_final_a_un_cliente_existente(): void
    {
        $token = $this->token();
        $cliente = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->altaCliente('Base', ['tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']))->assertStatus(201);

        $this->withHeaders($this->authHeaders($this->token('ventas_diseno.edit.client_tax_data')))->postJson("/api/ventas-diseno/clients/{$cliente->json('id')}/sub-clients", [
            'business_name' => 'Mostrador', 'receptor_tipo' => '02',
            'contacts' => [['name' => 'Ana', 'role' => 'client', 'phone' => '6000-0000']],
        ])->assertStatus(201)->assertJsonPath('receptor_tipo', '02')->assertJsonPath('tax_id', '');
    }

    public function test_el_flujo_de_vinculacion_desde_pipeline_tambien_acepta_consumidor_final(): void
    {
        $master = MasterClient::create(['name' => 'Master Pipeline '.uniqid()]);

        $this->withHeaders($this->authHeaders($this->token('ventas_diseno.edit.client_tax_data')))->postJson('/api/ventas-diseno/sub-clients', [
            'master_client_id' => $master->id, 'business_name' => 'Mostrador Pipeline', 'receptor_tipo' => '02',
        ])->assertStatus(201)->assertJsonPath('receptor_tipo', '02');
    }

    // ─── Edición (SCRUM-1002 PATCH tax-data) ──────────────────────────────────────────────────────

    private function subContribuyente(): SubClient
    {
        $master = MasterClient::create(['name' => 'Cliente Edit '.uniqid()]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Razón Vieja', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']);
    }

    private function url(SubClient $sub): string
    {
        return "/api/ventas-diseno/clients/sub-clients/{$sub->id}/tax-data";
    }

    public function test_se_puede_pasar_un_contribuyente_existente_a_consumidor_final_y_de_vuelta(): void
    {
        $sub = $this->subContribuyente();
        $token = $this->token('ventas_diseno.edit', 'ventas_diseno.edit.client_tax_data');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Juan Pérez', 'receptor_tipo' => '02'])
            ->assertStatus(200)->assertJsonPath('receptor_tipo', '02')->assertJsonPath('tax_id', '')->assertJsonPath('tax_id_dv', null);
        $this->reTenant();
        $this->assertSame('', $sub->fresh()->tax_id);

        // De vuelta a contribuyente: ahora el RUC vuelve a ser obligatorio (el DV es opcional desde SCRUM-1140).
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Juan Pérez', 'receptor_tipo' => '01'])
            ->assertStatus(422)->assertJsonValidationErrors(['tax_id']);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson($this->url($sub), ['business_name' => 'Juan Pérez S.A.', 'receptor_tipo' => '01', 'tax_id' => '155704849-2-2021', 'tax_id_dv' => '32'])
            ->assertStatus(200)->assertJsonPath('receptor_tipo', '01');
    }

    public function test_cambiar_el_tipo_se_audita_y_un_cliente_con_documentos_certificados_no_puede_cambiarlo(): void
    {
        $sub = $this->subContribuyente();
        $token = $this->token('ventas_diseno.edit', 'ventas_diseno.edit.client_tax_data');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Razón Vieja', 'receptor_tipo' => '02'])->assertStatus(200);
        $this->reTenant();
        $auditoria = DB::table('atlantic_auth.audit_logs')->where('entity_id', (string) $sub->id)->where('action', 'like', '%sub_client%')->latest('id')->first();
        $this->assertNotNull($auditoria);
        $this->assertStringContainsString('receptor_tipo', (string) $auditoria->new_values);

        // Un cliente con una factura certificada no puede cambiar de tipo (mismo bloqueo 409 de SCRUM-1002).
        $bloqueado = $this->subContribuyente();
        $this->facturaCertificada($bloqueado);
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($bloqueado), ['business_name' => 'Razón Vieja', 'receptor_tipo' => '02'])->assertStatus(409);
        $this->reTenant();
        $this->assertSame('01', $bloqueado->fresh()->receptor_tipo ?? '01');
    }

    public function test_el_directorio_y_las_respuestas_devuelven_el_tipo_y_tratan_null_como_contribuyente(): void
    {
        $sub = $this->subContribuyente(); // receptor_tipo NULL, como las filas anteriores a este ticket
        $this->assertNull($sub->receptor_tipo);
        $token = $this->token('ventas_diseno.edit');

        $fila = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/ventas-diseno/clients/fiscal-directory?search=Razón Vieja')->json('data'))
            ->firstWhere('id', $sub->id);
        $this->assertSame('01', $fila['receptor_tipo']);
    }

    // ─── Permiso para marcar un Consumidor Final (decisión de Luis, 2026-09-30) ───────────────────

    public function test_sin_el_permiso_angosto_nadie_puede_crear_un_cliente_como_consumidor_final(): void
    {
        $token = $this->token('ventas_diseno.edit'); // escribe y edita, pero no es Mark/Felix
        $this->reTenant(); // el login olvida el tenant
        $master = MasterClient::create(['name' => 'Master Permiso '.uniqid()]);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('SinPermiso', ['receptor_tipo' => '02']))
            ->assertStatus(403)->assertJsonPath('message', 'Solo Mark y Felix pueden marcar un cliente como Consumidor final.');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/ventas-diseno/clients/{$master->id}/sub-clients", [
            'business_name' => 'Mostrador', 'receptor_tipo' => '02', 'contacts' => [['name' => 'Ana', 'role' => 'client', 'phone' => '6000-0000']],
        ])->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/sub-clients', [
            'master_client_id' => $master->id, 'business_name' => 'Mostrador Pipeline', 'receptor_tipo' => '02',
        ])->assertStatus(403);

        // Nada se creó, y el mismo usuario sí puede crear Contribuyentes.
        $this->reTenant();
        $this->assertSame(0, SubClient::where('master_client_id', $master->id)->count());
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->altaCliente('Contrib', ['tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81']))->assertStatus(201);
    }

    public function test_sin_el_permiso_angosto_no_se_puede_pasar_un_cliente_a_consumidor_final_pero_si_editar_uno_que_ya_lo_es(): void
    {
        $sub = $this->subContribuyente();
        $token = $this->token('ventas_diseno.edit');

        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Juan Pérez', 'receptor_tipo' => '02'])->assertStatus(403);
        $this->reTenant();
        $this->assertSame('155-0000-1-2026', $sub->fresh()->tax_id, 'nada cambió');

        // Alguien con el permiso angosto lo pasa a 02; después, el vendedor puede corregirle la razón social sin cambiar el tipo.
        $this->withHeaders($this->authHeaders($this->token('ventas_diseno.edit', 'ventas_diseno.edit.client_tax_data')))
            ->patchJson($this->url($sub), ['business_name' => 'Juan Pérez', 'receptor_tipo' => '02'])->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Juan A. Pérez', 'receptor_tipo' => '02'])
            ->assertStatus(200)->assertJsonPath('business_name', 'Juan A. Pérez');
        // Pasarlo de vuelta a Contribuyente exige RUC y DV (validación, no permiso): sin ellos es 422.
        $this->withHeaders($this->authHeaders($token))->patchJson($this->url($sub), ['business_name' => 'Juan A. Pérez', 'receptor_tipo' => '01'])->assertStatus(422);
    }

    private function facturaCertificada(SubClient $sub): void
    {
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $ownerId = (int) DB::table('atlantic_auth.users')->where('email', 'contabilidad@test.com')->value('id');
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 100, 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name, 'pipeline_card_id' => $card->id,
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ]);
        $factura = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => $order->id, 'quote_id' => $quote->id,
            'itbms_rate_id' => AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail()->id, 'sales_project_id' => $project->id,
            'master_client_id' => $sub->master_client_id, 'cliente' => $sub->business_name, 'monto' => 100, 'saldo_aplicado' => 0.0,
        ]);
        $factura->forceFill(['fe_estado' => 'certificado'])->save();
    }
}
