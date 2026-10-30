<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\AdminContFiscalIdentitySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;

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
        // Fase 4a: la identidad fiscal de ejemplo ya no cuelga de `DatabaseSeeder`; la suite la sigue necesitando (para los PDF),
        // así que la carga acá, en el mismo estado que antes.
        $this->call([DatabaseSeeder::class, AdminContFiscalIdentitySeeder::class, FixtureSpecialPermissionSeeder::class]);
    }
}
