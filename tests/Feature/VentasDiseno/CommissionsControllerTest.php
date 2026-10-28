<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CommissionCohort;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Reportes-D (REQ-073). Ver memory/project_backlog_ventas_diseno_req.md.
 */
class CommissionsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function grantMarkPaid(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.commissions.mark_paid'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeApprovedCard(int $ownerId, float $amount, Carbon $stageChangedAt): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'amount' => $amount, 'stage_changed_at' => $stageChangedAt,
        ]);
    }

    public function test_comisiones_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/reports/commissions')->assertStatus(401);
    }

    public function test_mes_cerrado_fija_el_porcentaje_y_queda_pendiente(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        // Mes bien atrás (no solo el pasado) para minimizar la chance de que ya
        // exista una cohorte de datos demo cerrada ahí (CrmDemoSeeder tiende a
        // concentrar actividad reciente, no hace 5 meses) — igual el aserto de
        // abajo sigue siendo válido aunque hubiera algo: exige EXACTAMENTE los
        // valores esperados, así que cualquier contaminación real haría fallar
        // el test de forma visible, no silenciosa.
        $targetMonth = now()->subMonths(5);
        $this->makeApprovedCard($designerId, 25000, $targetMonth);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/commissions?scope=own&period=year')
            ->assertStatus(200);

        $row = collect($response->json('cohorts'))->firstWhere('month', $targetMonth->format('Y-m'));
        $this->assertNotNull($row);
        $this->assertEquals(25000, $row['total_amount']);
        $this->assertEquals(2.0, $row['commission_percent']);
        $this->assertEquals(500, $row['commission_amount']); // 25000 * 2%
        $this->assertNotNull($row['id']); // mes cerrado -> persistido
        $this->assertNull($row['paid_at']);
    }

    // SCRUM-849.4 — una tarjeta asignada a N personas (owner_id + compartido_con) reparte su
    // monto en partes iguales, ANTES de aplicar la tabla escalonada a cada una.
    public function test_tarjeta_con_compartido_con_divide_el_monto_entre_los_asignados(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $targetMonth = now()->subMonths(6);
        $card = $this->makeApprovedCard($designerId, 25000, $targetMonth);
        $card->update(['compartido_con' => [$managerId]]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/commissions?scope=own&period=year')
            ->assertStatus(200);

        $row = collect($response->json('cohorts'))->firstWhere('month', $targetMonth->format('Y-m'));
        $this->assertNotNull($row);
        $this->assertEquals(12500, $row['total_amount']); // 25000 / 2 asignados
        $this->assertEquals(1.5, $row['commission_percent']); // 12500 < 20000
        $this->assertEquals(187.5, $row['commission_amount']);
    }

    public function test_alcance_equipo_incluye_vendedores_reales_de_business_role_user_seeder(): void
    {
        // Pre-QA 2026-07-30 (SCRUM-712) — vendorsInScope() filtraba 'team' por el enum legado
        // user_roles (role='designer'), que los 10 vendedor reales (BusinessRoleUserSeeder,
        // role_id nuevo, sin fila en user_roles) nunca tuvieron. Gerencia veía $0/"Sin comisiones"
        // en alcance Equipo pese a comisiones reales pendientes por vendedor.
        $this->grantVentasDisenoRead('management@atlantic.test');
        $juanManuelId = $this->userIdByEmail('vendedor8@test.com');
        $this->assertGreaterThan(0, $juanManuelId, 'Vendedor Test 8 debe existir (BusinessRoleUserSeeder).');

        $targetMonth = now()->subMonths(5);
        $this->makeApprovedCard($juanManuelId, 25000, $targetMonth);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/commissions?scope=team&period=year')
            ->assertStatus(200);

        $row = collect($response->json('cohorts'))
            ->first(fn (array $c): bool => $c['owner_id'] === $juanManuelId && $c['month'] === $targetMonth->format('Y-m'));
        $this->assertNotNull($row, 'Un vendedor real sembrado por BusinessRoleUserSeeder debe aparecer en alcance Equipo.');
        $this->assertEquals(500, $row['commission_amount']);
    }

    public function test_mes_en_curso_no_se_persiste(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeApprovedCard($designerId, 10000, now());

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/commissions?scope=own&period=month')
            ->assertStatus(200);

        $currentMonth = now()->format('Y-m');
        $row = collect($response->json('cohorts'))->firstWhere('month', $currentMonth);
        $this->assertNotNull($row);
        $this->assertNull($row['id']); // mes en curso -> no persistido todavía

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, CommissionCohort::where('month', now()->startOfMonth()->toDateString())->count());
    }

    public function test_marcar_como_pagada_mueve_de_pendiente_a_pagada(): void
    {
        $this->grantMarkPaid('management@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $before = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/reports/commissions?scope=own&period=year&owner_id={$designerId}")
            ->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->makeApprovedCard($designerId, 25000, now()->subMonths(2));

        $afterCreate = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/reports/commissions?scope=own&period=year&owner_id={$designerId}")
            ->json();
        $newCohort = collect($afterCreate['cohorts'])->first(fn (array $c) => ! collect($before['cohorts'])->contains('id', $c['id']));
        $this->assertNotNull($newCohort);
        $cohortId = $newCohort['id'];

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/reports/commissions/{$cohortId}/mark-paid")
            ->assertStatus(200)
            ->assertJsonPath('id', $cohortId);

        $after = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/reports/commissions?scope=own&period=year&owner_id={$designerId}")
            ->assertStatus(200);

        $this->assertEquals(500, $after->json('paid') - $before['paid']);
        $this->assertEquals(0, $after->json('pending') - $before['pending']);
    }

    public function test_marcar_como_pagada_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $cohort = CommissionCohort::create([
            'owner_id' => $designerId, 'month' => now()->subMonth()->startOfMonth()->toDateString(),
            'total_amount' => 25000, 'commission_percent' => 2, 'commission_amount' => 500,
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/reports/commissions/{$cohort->id}/mark-paid")
            ->assertStatus(403);
    }

    public function test_marcar_como_pagada_id_inexistente_retorna_404(): void
    {
        $this->grantMarkPaid('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/ventas-diseno/reports/commissions/999999/mark-paid')
            ->assertStatus(404);
    }
}
