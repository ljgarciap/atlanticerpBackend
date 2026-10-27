<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Database\Seeders\Support\DemoDataset;

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

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — VentasDisenoDemoSeeder skipped.');
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

        $masterA = MasterClient::create(['name' => DemoDataset::clienteMaestro('A'), 'default_price_type' => 'project']);
        $masterB = MasterClient::create(['name' => DemoDataset::clienteMaestro('B'), 'default_price_type' => 'special']);

        $subA = SubClient::create([
            'master_client_id' => $masterA->id,
            'business_name'    => DemoDataset::subcliente('A'),
            'tax_id'            => '155-1234-1-2026',
            'category'          => 'b_architect_designer',
        ]);
        $subB = SubClient::create([
            'master_client_id' => $masterB->id,
            'business_name'    => DemoDataset::subcliente('B'),
            'tax_id'            => '155-5678-1-2026',
            'category'          => 'c_bidding',
        ]);

        $projectA = SalesProject::create(['sub_client_id' => $subA->id, 'name' => DemoDataset::proyectoVenta('Alfa'), 'tag' => 'both']);
        $projectB = SalesProject::create(['sub_client_id' => $subB->id, 'name' => DemoDataset::proyectoVenta('Beta'), 'tag' => 'design']);
        $projectC = SalesProject::create(['sub_client_id' => $subA->id, 'name' => DemoDataset::proyectoVenta('Gamma'), 'tag' => null]);

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

    /**
     * Fase 2: por clave de rol real (`roles.key`), no por el enum legado `user_roles`. `designer` (legado) = `vendedor`.
     *
     * @param list<string> $roles
     */
    private function userIdByRole(string $slug, array $roles): ?int
    {
        $keys = array_map(static fn (string $r): string => $r === 'designer' ? 'vendedor' : $r, $roles);
        $userId = User::query()->withRoleKey($keys)->orderBy('id')->value('id');

        return $userId !== null ? (int) $userId : null;
    }
}
