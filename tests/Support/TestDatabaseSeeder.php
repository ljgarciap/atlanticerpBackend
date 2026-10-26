<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\AdminContFiscalIdentitySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ZonaLibreProviderSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de la base de tests: el DatabaseSeeder real + las concesiones por persona de binax
 * (FixtureSpecialPermissionSeeder). Lo corre `migrate:fresh --seed` UNA sola vez por corrida, fuera de las
 * transacciones de cada test. NO sembrar esto desde `afterRefreshingDatabase()`: ese hook corre en CADA test,
 * y un `$this->seed()` ahí construye la app de Artisan (con todos los comandos y sus dependencias inyectadas)
 * antes de que el test registre sus fakes — los comandos de Legacy terminaban usando el extractor real.
 */
final class TestDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Fase 4a: estos dos seeders de «datos del cliente» ya no cuelgan de `DatabaseSeeder`; la suite los sigue necesitando (identidad fiscal de ejemplo
        // para los PDF y el proveedor fijo de Zona Libre), así que los carga acá, en el mismo estado que antes.
        $this->call([DatabaseSeeder::class, AdminContFiscalIdentitySeeder::class, ZonaLibreProviderSeeder::class, FixtureSpecialPermissionSeeder::class]);

        // Fase 6b — la base de pruebas arranca SIN proveedor fiscal (`none`): con `digifact` wireado, cualquier factura creada en un test
        // intentaría certificar contra ese PAC. Los tests de FE eligen su proveedor (`msl`, `digifact`…) de forma explícita.
        DB::table('atlantic_admin_contab.fiscal_settings')->update(['pac_provider' => 'none']);
    }
}
