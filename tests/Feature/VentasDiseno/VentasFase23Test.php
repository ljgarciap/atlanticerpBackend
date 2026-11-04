<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;
use Tests\Support\UniqueTaxId;

/**
 * Fase 23 (docs/specs/fase23-ajustes-ventas.md) — #9 Gerencia ve y edita ajustes, #10 comisión de vendedores,
 * #8 `scope=team` sin permiso, #7 ficha técnica, #2 último contacto.
 */
final class VentasFase23Test extends FeatureTestCase
{
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function vendedor(): array
    {
        return $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
    }

    /** @return array<string, string> */
    private function gerencia(): array
    {
        return $this->authHeaders($this->loginAs());
    }

    private function producto(): CatalogProduct
    {
        $this->tenant();

        return CatalogProduct::create([
            'reference' => 'F23-1', 'name' => 'Producto F23', 'description' => 'Producto F23', 'price_full' => 10, 'cost' => 5,
            'stock_quantity' => 1, 'category' => 'general', 'is_active' => true,
        ]);
    }

    // ── #9 / #10 ──────────────────────────────────────────────────────────────

    public function test_el_nivel_de_gerencia_trae_los_permisos_de_ajustes_y_ningun_otro_nivel(): void
    {
        $this->tenant();
        $claves = ['ventas_diseno.pricing.configure', 'ventas_diseno.reports.configure', 'ventas_diseno.commissions.mark_paid'];
        $niveles = DB::table('atlantic_auth.security_levels')->get(['id', 'level', 'permissions']);

        foreach ($claves as $clave) {
            $con = $niveles->filter(fn ($n) => in_array($clave, (array) json_decode((string) $n->permissions, true), true))->pluck('level')->all();
            $this->assertSame([9], $con, "{$clave} solo debe vivir en el nivel de Gerencia");
        }

        $nivelDeGerencia = DB::table('atlantic_auth.security_levels')->where('id', DB::table('atlantic_auth.roles')->where('key', 'management')->value('default_security_level_id'))->value('level');
        $this->assertSame(9, (int) $nivelDeGerencia);
    }

    public function test_los_ajustes_de_ventas_siguen_cerrados_al_vendedor_y_abiertos_a_quien_los_tiene(): void
    {
        $v = $this->vendedor();
        foreach (['pricing-settings', 'orders-settings', 'sales-commission-tiers', 'reports/goals'] as $ruta) {
            $this->withHeaders($v)->getJson("/api/ventas-diseno/{$ruta}")->assertStatus(403);
        }
        foreach (['pricing-settings', 'orders-settings', 'sales-commission-tiers', 'reports/goals'] as $ruta) {
            $this->withHeaders($this->gerencia())->getJson("/api/ventas-diseno/{$ruta}")->assertOk();
        }
    }

    // ── #8 ────────────────────────────────────────────────────────────────────

    public function test_pedir_scope_team_sin_ver_al_equipo_responde_403_y_con_permiso_sigue_igual(): void
    {
        $v = $this->vendedor();
        foreach (['home/summary', 'quotes', 'projects', 'reports/summary', 'reports/commissions', 'calendar-events'] as $ruta) {
            $this->withHeaders($v)->getJson("/api/ventas-diseno/{$ruta}?scope=team")->assertStatus(403)->assertJsonPath('code', 'team_scope_forbidden');
            $this->withHeaders($v)->getJson("/api/ventas-diseno/{$ruta}")->assertOk();
        }

        $this->withHeaders($this->gerencia())->getJson('/api/ventas-diseno/quotes?scope=team')->assertOk();
    }

    // ── #7 ────────────────────────────────────────────────────────────────────

    public function test_la_ficha_tecnica_se_sube_y_se_borra_solo_con_permiso_de_edicion(): void
    {
        Storage::fake('s3-private');
        $producto = $this->producto();
        $url = "/api/ventas-diseno/catalog/{$producto->id}/technical-sheet";
        $pdf = fn () => UploadedFile::fake()->create('ficha.pdf', 50, 'application/pdf');

        // Un rol de solo lectura (Operaciones) ya no sube ni borra.
        $lectura = $this->authHeaders($this->loginAs('nivel1@atlantic.test', 'Password123!', 'designer'));
        $this->withHeaders($lectura)->postJson($url, ['file' => $pdf()])->assertStatus(403);
        $this->withHeaders($lectura)->deleteJson($url)->assertStatus(403);

        $v = $this->vendedor();
        $this->withHeaders($v)->deleteJson($url)->assertStatus(404); // sin ficha todavía
        $this->withHeaders($v)->postJson($url, ['file' => $pdf()])->assertOk();

        $this->tenant();
        $key = $producto->refresh()->technical_sheet_key;
        Storage::disk('s3-private')->assertExists($key);

        $this->withHeaders($v)->deleteJson($url)->assertNoContent();
        $this->tenant();
        $producto->refresh();
        $this->assertNull($producto->technical_sheet_key);
        $this->assertNull($producto->technical_sheet_filename);
        Storage::disk('s3-private')->assertMissing($key);
        $this->withHeaders($v)->getJson($url)->assertStatus(404);
    }

    // ── #2 ────────────────────────────────────────────────────────────────────

    public function test_no_se_borra_el_ultimo_contacto_de_un_cliente(): void
    {
        $h = $this->gerencia();
        $cliente = $this->withHeaders($h)->postJson('/api/ventas-diseno/clients', [
            'name' => 'Cliente F23 '.uniqid(), 'business_name' => 'Cliente F23 SA', 'tax_id' => UniqueTaxId::next(), 'tax_id_dv' => '81',
            'contacts' => [['name' => 'Ana Prueba', 'role' => 'client', 'phone' => '6111-1111']],
        ])->assertCreated();
        $sub = (int) $cliente->json('sub_clients.0.id');
        $unico = (int) $cliente->json('sub_clients.0.contacts.0.id');

        $this->withHeaders($h)->deleteJson("/api/ventas-diseno/sub-clients/{$sub}/contacts/{$unico}")
            ->assertStatus(422)->assertJsonPath('code', 'last_contact');

        $otro = $this->withHeaders($h)->postJson("/api/ventas-diseno/sub-clients/{$sub}/contacts", ['name' => 'Beto Prueba', 'role' => 'client', 'phone' => '6222-2222'])->assertCreated();
        $this->withHeaders($h)->deleteJson("/api/ventas-diseno/sub-clients/{$sub}/contacts/{$unico}")->assertNoContent();
        $this->withHeaders($h)->deleteJson('/api/ventas-diseno/sub-clients/'.$sub.'/contacts/'.$otro->json('id'))->assertStatus(422);
    }
}
