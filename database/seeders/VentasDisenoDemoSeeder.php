<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos demo para el Grupo 1a (tablero base de Pipeline) del módulo Ventas & Diseño.
 * Ver memory/project_backlog_ventas_diseno_req.md para el plan completo.
 */
class VentasDisenoDemoSeeder extends Seeder
{
    private const DEMO_MARKER = '[DEMO]';

    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — VentasDisenoDemoSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed($tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(string $slug): void
    {
        if (MasterClient::where('name', 'like', self::DEMO_MARKER . '%')->exists()) {
            return;
        }

        $designerId = $this->userIdByRole($slug, ['designer']);
        $managerId  = $this->userIdByRole($slug, ['management', 'superadmin']);

        if ($designerId === null || $managerId === null) {
            $this->command->warn('Usuarios demo (designer/management) no encontrados — VentasDisenoDemoSeeder skipped.');
            return;
        }

        $masterA = MasterClient::create(['name' => self::DEMO_MARKER . ' Grupo Constructor Delta', 'default_price_type' => 'project']);
        $masterB = MasterClient::create(['name' => self::DEMO_MARKER . ' Inversiones Costa Bella', 'default_price_type' => 'special']);

        $subA = SubClient::create([
            'master_client_id' => $masterA->id,
            'business_name'    => self::DEMO_MARKER . ' Delta Residencial S.A.',
            'tax_id'            => '155-1234-1-2026',
            'category'          => 'b_architect_designer',
        ]);
        $subB = SubClient::create([
            'master_client_id' => $masterB->id,
            'business_name'    => self::DEMO_MARKER . ' Costa Bella Torres S.A.',
            'tax_id'            => '155-5678-1-2026',
            'category'          => 'c_bidding',
        ]);

        $projectA = SalesProject::create(['sub_client_id' => $subA->id, 'name' => self::DEMO_MARKER . ' Torre Delta Fase 1', 'tag' => 'both']);
        $projectB = SalesProject::create(['sub_client_id' => $subB->id, 'name' => self::DEMO_MARKER . ' Lobby Costa Bella', 'tag' => 'design']);
        $projectC = SalesProject::create(['sub_client_id' => $subA->id, 'name' => self::DEMO_MARKER . ' Amenidades Delta', 'tag' => null]);

        $cards = [
            ['sales_project_id' => $projectA->id, 'stage' => 'lead',     'master_client_id' => $masterA->id, 'sub_client_id' => $subA->id, 'owner_id' => $designerId, 'amount' => null,    'stage_changed_at' => Carbon::now()->subDays(2)],
            ['sales_project_id' => $projectB->id, 'stage' => 'design',   'master_client_id' => $masterB->id, 'sub_client_id' => $subB->id, 'owner_id' => $designerId, 'amount' => null,    'stage_changed_at' => Carbon::now()->subDays(20)], // estancada
            ['sales_project_id' => $projectC->id, 'stage' => 'quote',    'master_client_id' => $masterA->id, 'sub_client_id' => $subA->id, 'owner_id' => $managerId,  'amount' => 18500.0, 'stage_changed_at' => Carbon::now()->subDays(4)],
            ['sales_project_id' => $projectA->id, 'stage' => 'proposal', 'master_client_id' => $masterA->id, 'sub_client_id' => $subA->id, 'owner_id' => $managerId,  'amount' => 42750.5, 'stage_changed_at' => Carbon::now()->subDays(1)],
            ['sales_project_id' => $projectB->id, 'stage' => 'approved', 'master_client_id' => $masterB->id, 'sub_client_id' => $subB->id, 'owner_id' => $designerId, 'amount' => 9800.0,  'stage_changed_at' => Carbon::now()->subDays(30)],
            ['sales_project_id' => $projectC->id, 'stage' => 'lost',     'master_client_id' => $masterA->id, 'sub_client_id' => $subA->id, 'owner_id' => $designerId, 'amount' => null,    'stage_changed_at' => Carbon::now()->subDays(10)],
        ];

        foreach ($cards as $card) {
            PipelineCard::create($card);
        }
    }

    /** @param list<string> $roles */
    private function userIdByRole(string $slug, array $roles): ?int
    {
        $table = "{$slug}_auth.user_roles";

        foreach ($roles as $role) {
            $userId = DB::table($table)->where('role', $role)->value('user_id');
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }
}
