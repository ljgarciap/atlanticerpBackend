<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-816 — dashboard de migración ICG. Gate `role:superadmin` exclusivo (ajuste 2026-09-04 a
 * pedido de Luis — antes incluía `management`/Gerencia, spec cerrada 2026-09-01/02; se restringe
 * a solo equipo técnico, mismo criterio que Pagos ICG/Vista ICG).
 */
final class LegacyMigrationDashboardControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_designer_no_puede_ver_el_dashboard(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/counts')
            ->assertStatus(403);
    }

    // Ajuste 2026-09-04, sin ticket Jira (pedido directo de Luis) — management ya no debe poder
    // ver el dashboard, mismo criterio que Pagos ICG/Vista ICG (antes sí podía, ver docblock).
    public function test_management_ya_no_puede_ver_el_dashboard(): void
    {
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/counts')
            ->assertStatus(403);
    }

    public function test_superadmin_ve_el_resumen_historico_paginado_y_filtrable(): void
    {
        SyncRun::query()->create([
            'entidad' => 'productos', 'extraidos' => 10, 'staged' => 10, 'mapeados' => 9,
            'cargados' => 9, 'excluidos' => 1, 'errores' => 0, 'duracion_ms' => 1200,
            'status' => SyncRun::STATUS_SUCCESS, 'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(),
        ]);
        SyncRun::query()->create([
            'entidad' => 'clientes', 'extraidos' => 5, 'staged' => 5, 'mapeados' => 4,
            'cargados' => 4, 'excluidos' => 1, 'errores' => 0, 'duracion_ms' => 800,
            'status' => SyncRun::STATUS_SUCCESS, 'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(),
        ]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $all = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/runs')
            ->assertStatus(200)
            ->json();
        $this->assertSame(2, $all['total']);

        $filtered = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/runs?entidad=productos')
            ->assertStatus(200)
            ->json();
        $this->assertSame(1, $filtered['total']);
        $this->assertSame('productos', $filtered['data'][0]['entidad']);
    }

    public function test_comparacion_de_conteos_staging_vs_destino(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1', 'name' => 'A', 'price_full' => 10.0, 'is_active' => true,
        ]);
        Producto::query()->create(['icg_codarticulo' => 1, 'descripcion' => 'A', 'descatalogado' => false, 'catalog_product_id' => $catalogProduct->id]);
        Producto::query()->create(['icg_codarticulo' => 2, 'descripcion' => 'B', 'descatalogado' => false, 'catalog_product_id' => null]);
        Cliente::query()->create(['icg_codcliente' => 1, 'razon_social' => 'X', 'match_status' => Cliente::MATCH_MATCHED]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/counts')
            ->assertStatus(200)
            ->json('data');

        $productos = collect($response)->firstWhere('entidad', 'productos');
        $this->assertSame(2, $productos['staging']);
        $this->assertSame(1, $productos['resuelto']);

        $pagos = collect($response)->firstWhere('entidad', 'pagos');
        $this->assertSame(0, $pagos['staging']);
        $this->assertNull($pagos['resuelto']);
    }

    public function test_muestra_de_excluidos_filtrable_por_entidad(): void
    {
        $run = SyncRun::query()->create([
            'entidad' => 'facturas', 'extraidos' => 1, 'staged' => 1, 'mapeados' => 0,
            'cargados' => 0, 'excluidos' => 1, 'errores' => 0, 'duracion_ms' => 100,
            'status' => SyncRun::STATUS_SUCCESS, 'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(),
        ]);
        SyncRunDetail::query()->create([
            'sync_run_id' => $run->id, 'referencia' => 'A-9001', 'status' => 'orphan',
            'motivo' => 'icg_codcliente 555 sin match en legacy.clientes', 'created_at' => now(),
        ]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/excluded?entidad=facturas')
            ->assertStatus(200)
            ->json();

        $this->assertSame(1, $response['total']);
        $this->assertSame('A-9001', $response['data'][0]['referencia']);
        $this->assertSame('facturas', $response['data'][0]['entidad']);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/dashboard/excluded?entidad=pedidos')
            ->assertStatus(200)
            ->assertJsonPath('total', 0);
    }
}
