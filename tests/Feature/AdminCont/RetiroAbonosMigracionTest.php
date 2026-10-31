<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 15a-2 (docs/specs/fase15a-un-solo-concepto-de-cobros.md) — la migración que retira el esquema del modelo anterior de cobros. En la base de pruebas
 * ya corrió, así que el test reconstruye el estado previo dentro de la transacción y la vuelve a ejecutar.
 */
final class RetiroAbonosMigracionTest extends FeatureTestCase
{
    private const ARCHIVO = '2026_11_09_100000_retire_abonos_schema.php';

    private string $s = 'atlantic_admin_contab';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        parent::tearDown();
    }

    private function migracion(): object
    {
        return require base_path('database/migrations/tenant/'.self::ARCHIVO);
    }

    private function reconstruirEstadoPrevio(): void
    {
        DB::statement("CREATE TABLE {$this->s}.abonos (id bigserial primary key, estado varchar(20) not null default 'confirmado')");
        DB::statement("CREATE TABLE {$this->s}.abono_folio_counters (id bigserial primary key)");
        DB::statement("ALTER TABLE {$this->s}.payment_invoices ADD COLUMN abono_id bigint NULL, ADD COLUMN monto_abono_aplicado numeric(12,2) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE {$this->s}.payment_movements ADD COLUMN abono_id bigint NULL");
        DB::statement("ALTER TABLE {$this->s}.payments ADD COLUMN cobros_v2 boolean NOT NULL DEFAULT true");
        DB::statement("INSERT INTO {$this->s}.admin_contab_settings (key, value, created_at, updated_at) VALUES ('cobros_v2_activo', '1', now(), now())");
    }

    private function enProduccion(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    public function test_retira_tablas_columnas_y_el_ajuste(): void
    {
        $this->reconstruirEstadoPrevio();
        DB::statement("INSERT INTO {$this->s}.abonos (estado) VALUES ('confirmado')");   // en testing los datos son descartables

        $this->migracion()->up();

        $this->assertFalse(Schema::hasTable("{$this->s}.abonos"));
        $this->assertFalse(Schema::hasTable("{$this->s}.abono_folio_counters"));
        $this->assertFalse(Schema::hasColumn("{$this->s}.payment_invoices", 'abono_id'));
        $this->assertFalse(Schema::hasColumn("{$this->s}.payment_invoices", 'monto_abono_aplicado'));
        $this->assertFalse(Schema::hasColumn("{$this->s}.payment_movements", 'abono_id'));
        $this->assertFalse(Schema::hasColumn("{$this->s}.payments", 'cobros_v2'));
        $this->assertSame(0, DB::table("{$this->s}.admin_contab_settings")->where('key', 'cobros_v2_activo')->count());
    }

    public function test_fuera_de_entornos_descartables_aborta_con_abonos_y_no_borra_nada(): void
    {
        $this->reconstruirEstadoPrevio();
        DB::statement("INSERT INTO {$this->s}.abonos (estado) VALUES ('confirmado')");
        $this->enProduccion();

        try {
            $this->migracion()->up();
            $this->fail('Debió abortar con un abono registrado.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('abonos', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable("{$this->s}.abonos"));
        $this->assertTrue(Schema::hasColumn("{$this->s}.payments", 'cobros_v2'));
        $this->assertSame(1, DB::table("{$this->s}.admin_contab_settings")->where('key', 'cobros_v2_activo')->count());
    }

    public function test_fuera_de_entornos_descartables_aborta_con_cobros_del_modelo_anterior(): void
    {
        $this->reconstruirEstadoPrevio();
        $this->enProduccion();
        $id = DB::table("{$this->s}.payments")->insertGetId([
            'master_client_id' => \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'Cliente Anterior '.uniqid()])->id,
            'monto_recibido' => 10, 'saldo_favor_aplicado' => 0, 'metodo_pago' => 'efectivo', 'estado' => 'confirmado', 'cobros_v2' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $id);

        try {
            $this->migracion()->up();
            $this->fail('Debió abortar con un cobro del modelo anterior.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cobros del modelo anterior', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn("{$this->s}.payments", 'cobros_v2'));
    }

    public function test_en_produccion_sin_datos_anteriores_migra(): void
    {
        $this->reconstruirEstadoPrevio();
        $this->enProduccion();

        $this->migracion()->up();

        $this->assertFalse(Schema::hasTable("{$this->s}.abonos"));
    }

    public function test_es_irreversible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->migracion()->down();
    }
}
