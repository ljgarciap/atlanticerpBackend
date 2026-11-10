<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Fase 31a (docs/specs/fase31-ventas-administracion.md) — baja de clientes y asociados, detalle del asociado, reemplazo de contacto, historial de la ficha. */
final class Fase31aVentasTest extends FeatureTestCase
{
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function gerencia(): array
    {
        $h = $this->authHeaders($this->loginAs());
        $this->tenant();

        return $h;
    }

    /** @return array<string, string> */
    private function vendedor(): array
    {
        $h = $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
        $this->tenant();

        return $h;
    }

    public function test_un_cliente_dado_de_baja_se_oculta_y_se_puede_reactivar(): void
    {
        $this->tenant();
        $c = MasterClient::create(['name' => 'Cliente Baja F31']);
        $h = $this->gerencia();

        $this->withHeaders($h)->patchJson("/api/ventas-diseno/clients/{$c->id}/active", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $ids = fn (string $q) => collect($this->withHeaders($h)->getJson("/api/ventas-diseno/clients{$q}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($c->id, $ids('?per_page=100'));
        $this->assertContains($c->id, $ids('?per_page=100&incluir_inactivos=1'));

        $sel = collect($this->withHeaders($h)->getJson('/api/ventas-diseno/master-clients?search=Baja F31')->json('data'))->pluck('id')->all();
        $this->assertNotContains($c->id, $sel);

        $this->withHeaders($h)->patchJson("/api/ventas-diseno/clients/{$c->id}/active", ['is_active' => true])->assertOk();
        $this->assertContains($c->id, $ids('?per_page=100'));
        $this->tenant();
        $this->assertTrue(MasterClient::find($c->id)->is_active);
    }

    public function test_dar_de_baja_exige_permiso_de_edicion_y_valida_el_cuerpo(): void
    {
        $this->tenant();
        $c = MasterClient::create(['name' => 'Cliente Permisos F31']);
        $lectura = $this->authHeaders($this->loginAs('nivel1@atlantic.test', 'Password123!', 'designer'));
        $this->withHeaders($lectura)->patchJson("/api/ventas-diseno/clients/{$c->id}/active", ['is_active' => false])->assertStatus(403);
        $this->withHeaders($this->gerencia())->patchJson("/api/ventas-diseno/clients/{$c->id}/active", [])->assertStatus(422);
        $this->withHeaders($this->gerencia())->patchJson('/api/ventas-diseno/clients/abc/active', ['is_active' => false])->assertStatus(404);
    }

    public function test_un_asociado_dado_de_baja_se_oculta_de_la_lista_y_conserva_su_detalle(): void
    {
        $this->tenant();
        $a = Architect::create(['type' => 'architect', 'name' => 'Asociado Baja F31']);
        $h = $this->gerencia();

        $this->withHeaders($h)->patchJson("/api/ventas-diseno/architects/{$a->id}/active", ['is_active' => false])->assertOk();

        $ids = fn (string $q) => collect($this->withHeaders($h)->getJson("/api/ventas-diseno/architects{$q}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($a->id, $ids(''));
        $this->assertContains($a->id, $ids('?incluir_inactivos=1'));
        $this->withHeaders($h)->getJson("/api/ventas-diseno/architects/{$a->id}")->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_el_detalle_del_asociado_cuenta_los_proyectos_donde_figura_como_asociado(): void
    {
        $this->tenant();
        $m  = MasterClient::create(['name' => 'Cliente Det F31']);
        $sc = SubClient::create(['master_client_id' => $m->id, 'business_name' => 'Sub Det F31', 'tax_id' => '77-7-7001', 'receptor_tipo' => ReceptorTipo::CONTRIBUYENTE]);
        $p  = SalesProject::create(['sub_client_id' => $sc->id, 'name' => 'Proyecto Det F31']);
        $a  = Architect::create(['type' => 'architect', 'name' => 'Asociado Det F31']);
        $p->associates()->attach($a->id);
        DB::table('atlantic_ventas_diseno.pipeline_cards')->insert([
            'master_client_id' => $m->id, 'sub_client_id' => $sc->id, 'sales_project_id' => $p->id,
            'owner_id' => \App\Models\User::query()->value('id'), 'stage' => 'lead', 'stage_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withHeaders($this->gerencia())->getJson("/api/ventas-diseno/architects/{$a->id}")->assertOk();
        $this->assertCount(1, $res->json('projects'));
        $res->assertJsonPath('projects.0.role', 'asociado');
    }

    public function test_reemplazar_el_unico_contacto_de_un_subcliente_en_una_operacion(): void
    {
        $this->tenant();
        $m  = MasterClient::create(['name' => 'Cliente Contacto F31']);
        $sc = SubClient::create(['master_client_id' => $m->id, 'business_name' => 'Sub Contacto F31', 'tax_id' => '77-7-7002', 'receptor_tipo' => ReceptorTipo::CONTRIBUYENTE]);
        $old = $sc->contacts()->create(['name' => 'Contacto Viejo', 'role' => 'client', 'email' => 'viejo@test.com']);
        $h = $this->gerencia();

        // Borrar el último sigue prohibido…
        $this->withHeaders($h)->deleteJson("/api/ventas-diseno/sub-clients/{$sc->id}/contacts/{$old->id}")->assertStatus(422);

        // …pero reemplazarlo funciona y deja exactamente uno.
        $this->withHeaders($h)->postJson("/api/ventas-diseno/sub-clients/{$sc->id}/contacts/{$old->id}/replace", ['name' => 'Contacto Nuevo', 'role' => 'client', 'email' => 'nuevo@test.com'])
            ->assertCreated()->assertJsonPath('name', 'Contacto Nuevo');

        $this->tenant();
        $this->assertSame(['Contacto Nuevo'], $sc->contacts()->pluck('name')->all());
        $this->withHeaders($h)->postJson("/api/ventas-diseno/sub-clients/{$sc->id}/contacts/{$old->id}/replace", ['name' => 'X', 'role' => 'client', 'email' => 'x@test.com'])->assertStatus(404);
    }

    public function test_reemplazar_o_quitar_la_ficha_conserva_el_historial_y_su_archivo(): void
    {
        Storage::fake('s3-private');
        $this->tenant();
        $p = CatalogProduct::create([
            'reference' => 'F31-1', 'name' => 'Producto F31', 'description' => 'Producto F31', 'price_full' => 10, 'cost' => 5,
            'stock_quantity' => 1, 'category' => 'general', 'is_active' => true,
        ]);
        $h   = $this->gerencia();
        $url = "/api/ventas-diseno/catalog/{$p->id}/technical-sheet";
        $pdf = fn (string $n) => UploadedFile::fake()->create($n, 20, 'application/pdf');

        $this->withHeaders($h)->postJson($url, ['file' => $pdf('uno.pdf')])->assertOk();
        $this->tenant();
        $primera = $p->refresh()->technical_sheet_key;

        $this->withHeaders($h)->postJson($url, ['file' => $pdf('dos.pdf')])->assertOk();
        $this->tenant();
        $segunda = $p->refresh()->technical_sheet_key;
        $this->withHeaders($h)->deleteJson($url)->assertNoContent();

        Storage::disk('s3-private')->assertExists($primera);
        Storage::disk('s3-private')->assertExists($segunda);

        $hist = $this->withHeaders($h)->getJson("{$url}/history")->assertOk()->json('data');
        $this->assertCount(2, $hist);
        $this->assertSame(['removed', 'replaced'], array_column($hist, 'retired_reason'));
        $this->assertSame(['dos.pdf', 'uno.pdf'], array_column($hist, 'filename'));
    }

    public function test_el_historial_de_la_ficha_exige_permiso_de_edicion(): void
    {
        $this->tenant();
        $p = CatalogProduct::create([
            'reference' => 'F31-2', 'name' => 'Producto F31b', 'description' => 'Producto F31b', 'price_full' => 10, 'cost' => 5,
            'stock_quantity' => 1, 'category' => 'general', 'is_active' => true,
        ]);
        $lectura = $this->authHeaders($this->loginAs('nivel1@atlantic.test', 'Password123!', 'designer'));
        $this->withHeaders($lectura)->getJson("/api/ventas-diseno/catalog/{$p->id}/technical-sheet/history")->assertStatus(403);
    }

    public function test_aprobaciones_pendientes_del_inicio_de_ventas_lista_las_solicitudes_de_pase_a_pedido(): void
    {
        $this->tenant();
        $m  = MasterClient::create(['name' => 'Cliente Apr F31']);
        $sc = SubClient::create(['master_client_id' => $m->id, 'business_name' => 'Sub Apr F31', 'tax_id' => '77-7-7003', 'receptor_tipo' => ReceptorTipo::CONTRIBUYENTE]);
        $p  = SalesProject::create(['sub_client_id' => $sc->id, 'name' => 'Proyecto Apr F31']);
        DB::table('atlantic_ventas_diseno.pipeline_cards')->insert([
            'master_client_id' => $m->id, 'sub_client_id' => $sc->id, 'sales_project_id' => $p->id,
            'owner_id' => \App\Models\User::query()->value('id'), 'stage' => 'approved', 'stage_changed_at' => now(),
            'order_requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tipos = fn (array $h) => array_column($this->withHeaders($h)->getJson('/api/ventas-diseno/home/approvals-pending')->assertOk()->json('data'), 'tipo');
        $this->assertContains('pass_to_order_request', $tipos($this->gerencia()));
        $this->assertNotContains('pass_to_order_request', $tipos($this->vendedor()));
    }
}
