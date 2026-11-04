<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\AdminContFiscalIdentitySeeder;
use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
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

        // SCRUM-1150 (binax) — Contabilidad replicaba a Gerencia en Ventas & Diseño. Atlantic ya no lo siembra (Fase 21, B1: solo crea y vincula),
        // pero muchos tests heredados ejercitan ese comportamiento: se reproduce solo aquí.
        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant !== null) {
            $tenant->makeCurrent();
            $user = User::where('email', 'contabilidad@test.com')->first();
            if ($user !== null) {
                UserModuleVisibility::firstOrCreate(
                    ['user_id' => $user->id, 'module' => 'ventas_diseno'],
                    ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true, 'can_edit' => true, 'can_approve' => true],
                );
            }
            Tenant::forgetCurrent();
        }
    }
}
