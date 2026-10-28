<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-146 — Meta/Meta Plus reales de los vendedor sembrados por
 * BusinessRoleUserSeeder. Corre en TODOS los entornos (dato de negocio real,
 * mismo criterio que CoreUserSeeder/BusinessRoleUserSeeder) — sin esto, Reportes
 * muestra "Sin Meta configurada todavía" para el roster real (síntoma reportado
 * por Gerencia el 2026-07-27 en SCRUM-146).
 *
 * Create-only-if-not-exists (igual que CoreUserSeeder): la Meta es un valor que
 * Gerencia edita a mano desde Reportes > Configuración (REQ-072, "no es un
 * promedio automático") — este seeder solo siembra el punto de partida, nunca
 * pisa un valor ya ajustado manualmente.
 *
 * Metas de ejemplo para las cuentas demo `vendedor[N]@test.com`. `vendedor6@test.com` queda sin Meta
 * configurada a propósito (cubre el caso «vendedor sin meta»).
 */
class VentasDisenoSalesGoalsSeeder extends Seeder
{
    /** @var array<string, array{goal_amount: float, goal_plus_amount: float}> */
    private const GOALS_BY_EMAIL = [
        'vendedor8@test.com' => ['goal_amount' => 4328.87, 'goal_plus_amount' => 1500000.00],
        'vendedor9@test.com' => ['goal_amount' => 494.60,  'goal_plus_amount' => 420000.00],
        'vendedor5@test.com' => ['goal_amount' => 812.48,  'goal_plus_amount' => 700000.00],
        'vendedor10@test.com' => ['goal_amount' => 951.07,  'goal_plus_amount' => 700000.00],
        'vendedor2@test.com' => ['goal_amount' => 603.28,  'goal_plus_amount' => 420000.00],
        'vendedor@test.com' => ['goal_amount' => 779.10,  'goal_plus_amount' => 420000.00],
        'vendedor3@test.com' => ['goal_amount' => 750.00,  'goal_plus_amount' => 420000.00],
        'vendedor7@test.com' => ['goal_amount' => 1116.47, 'goal_plus_amount' => 700000.00],
        'vendedor4@test.com' => ['goal_amount' => 533.30,  'goal_plus_amount' => 420000.00],
        // vendedor6@test.com — sin meta a propósito.
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — VentasDisenoSalesGoalsSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $slug = $tenant->slug;
            $users = DB::table("{$slug}_auth.users")
                ->whereIn('email', array_keys(self::GOALS_BY_EMAIL))
                ->select('id', 'email')
                ->get();

            $missing = array_diff(array_keys(self::GOALS_BY_EMAIL), $users->pluck('email')->all());
            if ($missing !== []) {
                $this->command?->warn('Faltan vendedores (correr BusinessRoleUserSeeder primero): '.implode(', ', $missing));
            }

            foreach ($users as $row) {
                if (SalesGoal::where('user_id', $row->id)->exists()) {
                    continue; // create-only-if-not-exists, mismo criterio que CoreUserSeeder
                }

                $goal = self::GOALS_BY_EMAIL[$row->email];
                SalesGoal::create([
                    'user_id' => $row->id,
                    'goal_amount' => $goal['goal_amount'],
                    'goal_plus_amount' => $goal['goal_plus_amount'],
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
