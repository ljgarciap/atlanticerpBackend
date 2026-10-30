<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4a (docs/specs/fase4-dataset-demo-generico.md, §3.1) — `DatabaseSeeder` en tres niveles: núcleo neutro (todos los entornos), demo (solo
 * local/dev/testing) y datos del cliente anterior (NUNCA por defecto: un tenant nuevo nace sin identidad fiscal, cuentas bancarias ni proveedores ajenos).
 */
class DatabaseSeederTiersTest extends FeatureTestCase
{
    /** Seeders con datos del cliente anterior: sin llamador en `DatabaseSeeder` (Fase 4a); se borran con Legacy/Lafayette en la Fase 5. */
    private const CLIENT_DATA = [
        'AdminContFiscalIdentitySeeder', 'AdminContBankAccountSeeder', 'LafayetteLightingProviderSeeder',
        'SharedFamiliesSeeder', 'CatalogUatTestDataSeeder', 'AlmacenEquivalenciaSeeder',
    ];

    /** Seeders de demo: solo dentro del bloque `if (app()->environment([...]))`. */
    private const DEMO = [
        'AuthUserSeeder', 'CrmDemoSeeder', 'VentasDisenoDemoSeeder', 'ServiciosDemoSeeder', 'CatalogProductSeeder', 'CatalogProductFamilySeeder',
        'DemoProviderAssignmentSeeder', 'DemoReplacementRequestSeeder', 'ProductWarehouseStockBackfillSeeder',
        // Fase 4d: identidades `*@test.com` (contraseña = correo) y todo lo que las busca por correo.
        'CoreUserSeeder', 'BusinessRoleUserSeeder', 'PrimaryApproverBackfillSeeder', 'AdminContPrimaryApproverBackfillSeeder', 'RoleBackfillSeeder',
        'VentasDisenoSalesGoalsSeeder', 'UserModuleVisibilityGrantSeeder', 'InternalTechnicianSeeder', 'WarehouseAssistantMappingSeeder',
    ];

    private function source(): string
    {
        return (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    }

    public function test_los_seeders_con_datos_del_cliente_no_cuelgan_de_database_seeder(): void
    {
        $code = preg_replace('#//[^\n]*#', '', $this->source());
        foreach (self::CLIENT_DATA as $seeder) {
            $this->assertStringNotContainsString("{$seeder}::class", (string) $code, "{$seeder} no debe sembrarse por defecto");
        }
    }

    public function test_el_demo_solo_corre_dentro_del_bloque_de_entornos_no_productivos(): void
    {
        $code = (string) preg_replace('#//[^\n]*#', '', $this->source());
        $gate = strpos($code, "app()->environment(['local', 'dev', 'testing'])");
        $this->assertNotFalse($gate, 'falta el bloque de entornos de demo');

        foreach (self::DEMO as $seeder) {
            $pos = strpos($code, "{$seeder}::class");
            $this->assertNotFalse($pos, "{$seeder} debería seguir sembrándose en demo");
            $this->assertGreaterThan($gate, $pos, "{$seeder} está fuera del bloque de demo");
        }
    }

    public function test_en_produccion_no_se_siembran_cuentas_bancarias_ni_identidad_fiscal_ni_proveedores_ajenos(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContBankAccount::query()->delete();
        AdminContFiscalSettings::query()->where('id', 1)->update(['razon_social' => '', 'nombre_comercial' => '', 'ruc' => '', 'direccion_fiscal' => '']);
        DB::table('atlantic_compras.providers')->where('name', 'Lafayette Lighting')->delete();
        Tenant::forgetCurrent();

        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            // `--force`: en producción `db:seed` pide confirmación.
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, AdminContBankAccount::query()->count(), 'no debe crear cuentas bancarias');
        $this->assertSame('', (string) AdminContFiscalSettings::query()->where('id', 1)->value('razon_social'), 'no debe cargar identidad fiscal');
        $this->assertSame('', (string) AdminContFiscalSettings::query()->where('id', 1)->value('ruc'));
        $this->assertSame(0, DB::table('atlantic_compras.providers')->where('name', 'Lafayette Lighting')->count(), 'no debe crear proveedores ajenos');
        $this->assertSame(0, DB::table('atlantic_ventas_diseno.catalog_products')->where('reference', 'like', 'UAT-%')->count(), 'no debe crear el catálogo UAT');
    }

    public function test_ningun_seeder_fuera_de_demo_fija_una_contrasena_igual_al_correo(): void
    {
        // Los únicos que crean usuarios con contraseña = correo son de demo (ver DEMO); cualquier otro que asigne `password_hash` es una regresión.
        $demoUserSeeders = ['AuthUserSeeder', 'CoreUserSeeder', 'BusinessRoleUserSeeder'];
        foreach (glob(database_path('seeders/*.php')) ?: [] as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $demoUserSeeders, true)) {
                continue;
            }
            $this->assertStringNotContainsString('password_hash', (string) file_get_contents($file), "{$name} no debe asignar contraseñas");
        }
    }

    public function test_en_produccion_no_se_vuelven_a_crear_las_cuentas_demo(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // Quita las cuentas demo de la base de pruebas dentro de la transacción del test (savepoint por si alguna tiene filas dependientes).
        $emails = ['superadmin3@test.com', 'gerencia4@test.com'];
        DB::transaction(fn () => DB::table('atlantic_auth.users')->whereIn('email', $emails)->delete());
        Tenant::forgetCurrent();

        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, DB::table('atlantic_auth.users')->whereIn('email', $emails)->count(), 'producción no debe sembrar cuentas demo');
    }
}
