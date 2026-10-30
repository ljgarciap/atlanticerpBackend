<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14f-2 (docs/specs/fase14f-retiro-zona-libre.md) — la migración que retira el esquema de Zona Libre. En la base de pruebas ya corrió, así que el
 * test reconstruye el estado previo (tablas, permiso, categoría, modalidad) dentro de la transacción y la vuelve a ejecutar.
 */
final class RetiroZonaLibreMigracionTest extends FeatureTestCase
{
    private const ARCHIVO = '2026_11_06_100000_retire_zona_libre.php';

    private const PERMISO = 'compras.zona-libre.approve';

    private string $compras;

    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $this->compras = 'atlantic_compras';
        $this->auth = 'atlantic_auth';
    }

    private function migracion(): object
    {
        return require base_path('database/migrations/tenant/'.self::ARCHIVO);
    }

    private function reconstruirEstadoPrevio(): void
    {
        DB::statement("CREATE TABLE {$this->compras}.bodega_zona_libre_requests (id bigserial primary key, order_number int, status varchar(20) not null default 'pendiente')");
        DB::statement("CREATE TABLE {$this->compras}.bodega_zona_libre_request_lines (id bigserial primary key, bodega_zona_libre_request_id bigint)");
        DB::statement("CREATE TABLE {$this->compras}.bodega_zona_libre_folio_counters (id bigserial primary key)");
        DB::statement("ALTER TABLE {$this->compras}.settings ADD COLUMN IF NOT EXISTS zona_libre_provider_id bigint NULL");
        DB::table("{$this->auth}.permission_catalog")->insert([
            'key' => self::PERMISO, 'module' => 'compras', 'label' => 'x', 'sort_order' => 999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("UPDATE {$this->auth}.security_levels SET permissions = permissions || '[\"".self::PERMISO."\"]'::jsonb WHERE id = (SELECT min(id) FROM {$this->auth}.security_levels)");
        DB::table("{$this->auth}.catalog_entries")->insert([
            'domain' => 'provider_category', 'key' => 'zona_libre', 'label' => 'Zona', 'sort_order' => 99, 'is_active' => true, 'is_system' => false,
            'props' => json_encode(['origen' => 'internacional', 'valida_identificacion' => false]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table("{$this->auth}.catalog_entries")->where('domain', 'provider_category')->where('key', 'internacional')->delete();
    }

    public function test_aborta_si_queda_una_solicitud_pendiente_y_no_borra_nada(): void
    {
        $this->reconstruirEstadoPrevio();
        DB::table("{$this->compras}.bodega_zona_libre_requests")->insert(['order_number' => 1, 'status' => 'pendiente']);

        try {
            $this->migracion()->up();
            $this->fail('Debió abortar con una solicitud pendiente.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pendiente', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable("{$this->compras}.bodega_zona_libre_requests"));
        $this->assertTrue(DB::table("{$this->auth}.permission_catalog")->where('key', self::PERMISO)->exists());
    }

    public function test_retira_tablas_permiso_categoria_y_convierte_las_ordenes(): void
    {
        $this->reconstruirEstadoPrevio();
        DB::table("{$this->compras}.bodega_zona_libre_requests")->insert(['order_number' => 1, 'status' => 'aprobada']);
        $proveedor = Provider::create(['name' => 'Proveedor X', 'currency' => 'USD', 'category' => 'zona_libre', 'origin' => 'internacional']);
        $orden = PurchaseOrder::create(['provider_id' => $proveedor->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'modality' => 'zona_libre']);
        DB::statement("INSERT INTO atlantic_gerencia.reglas_aprobacion (tipo, activo, created_at, updated_at) VALUES ('zona_libre', true, now(), now())");

        $this->migracion()->up();

        foreach (['bodega_zona_libre_requests', 'bodega_zona_libre_request_lines', 'bodega_zona_libre_folio_counters'] as $tabla) {
            $this->assertFalse(Schema::hasTable("{$this->compras}.{$tabla}"), $tabla);
        }
        $this->assertFalse(Schema::hasColumn("{$this->compras}.settings", 'zona_libre_provider_id'));
        $this->assertFalse(DB::table("{$this->auth}.permission_catalog")->where('key', self::PERMISO)->exists());
        $this->assertSame(0, DB::table("{$this->auth}.security_levels")->whereRaw('jsonb_exists(permissions, ?)', [self::PERMISO])->count());
        $this->assertSame('directo', DB::table("{$this->compras}.purchase_orders")->where('id', $orden->id)->value('modality'));
        $this->assertSame('internacional', DB::table("{$this->compras}.providers")->where('id', $proveedor->id)->value('category'));
        $this->assertFalse(DB::table("{$this->auth}.catalog_entries")->where('domain', 'provider_category')->where('key', 'zona_libre')->exists());
        $this->assertTrue(DB::table("{$this->auth}.catalog_entries")->where('domain', 'provider_category')->where('key', 'internacional')->exists());
        $this->assertSame(0, DB::table('atlantic_gerencia.reglas_aprobacion')->where('tipo', 'zona_libre')->count());
    }

    public function test_es_irreversible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->migracion()->down();
    }
}
