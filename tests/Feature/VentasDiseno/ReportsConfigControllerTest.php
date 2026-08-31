<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\ReportSettings;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Reportes-B (REQ-072/075/076/SCRUM-146). Ver memory/project_backlog_ventas_diseno_req.md.
 */
class ReportsConfigControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantReportsConfigure(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.reports.configure'])]);
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    // ── goals ─────────────────────────────────────────────────────────────────

    public function test_goals_sin_permiso_reports_configure_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/goals')
            ->assertStatus(403);
    }

    public function test_listar_goals_incluye_todos_los_disenadores(): void
    {
        $this->grantReportsConfigure('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        SalesGoal::create(['user_id' => $designerId, 'goal_amount' => 15000, 'goal_plus_amount' => 25000]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/goals')
            ->assertStatus(200);

        $data = $response->json('data');
        $row = collect($data)->firstWhere('user_id', $designerId);
        $this->assertNotNull($row);
        $this->assertEquals(15000, $row['goal_amount']);
        $this->assertEquals(25000, $row['goal_plus_amount']);
    }

    public function test_listar_goals_incluye_vendedores_reales_de_business_role_user_seeder(): void
    {
        // Pre-QA 2026-07-30 (SCRUM-146) — goalsIndex() filtraba por el enum legado `user_roles`
        // (role='designer'), que los 9 vendedor_disenador reales (BusinessRoleUserSeeder, con
        // role_id nuevo, sin fila en user_roles) nunca tuvieron. La pantalla "Meta por vendedor"
        // los mostraba a cero — imposible editar su Meta desde la UI.
        $this->grantReportsConfigure('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/goals')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('user_name');
        $this->assertTrue($names->contains('Vendedor Disenador Test 8'), 'Un vendedor real sembrado por BusinessRoleUserSeeder debe listarse.');
    }

    public function test_upsert_goal_crea_y_actualiza(): void
    {
        $this->grantReportsConfigure('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/reports/goals/{$designerId}", ['goal_amount' => 10000])
            ->assertStatus(200)
            ->assertJsonPath('goal_amount', 10000);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/reports/goals/{$designerId}", ['goal_amount' => 12000])
            ->assertStatus(200)
            ->assertJsonPath('goal_amount', 12000);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, SalesGoal::where('user_id', $designerId)->count());
    }

    public function test_goal_amount_que_desborda_la_columna_retorna_422(): void
    {
        // Pre-QA 2026-07-16: sales_goals.goal_amount es decimal(12,2) -- sin el max:,
        // el valor llegaba intacto a Postgres y causaba 500 en vez de 422 (mismo patron SCRUM-81).
        $this->grantReportsConfigure('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/reports/goals/{$designerId}", ['goal_amount' => 999999999999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal_amount');
    }

    public function test_goal_amount_negativo_retorna_422(): void
    {
        $this->grantReportsConfigure('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/reports/goals/{$designerId}", ['goal_amount' => -500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal_amount');
    }

    // ── settings ──────────────────────────────────────────────────────────────

    public function test_get_settings_es_legible_solo_con_ventas_diseno_read(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/settings')
            ->assertStatus(200);

        $response->assertJsonPath('low_sales_threshold', 20000);
        $response->assertJsonPath('low_sales_months', 3);
        $response->assertJsonPath('quote_probability_percent', 40);
        $response->assertJsonPath('proposal_probability_percent', 70);
    }

    public function test_actualizar_settings_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/reports/settings', ['low_sales_threshold' => 30000])
            ->assertStatus(403);
    }

    public function test_actualizar_settings_con_permiso(): void
    {
        $this->grantReportsConfigure('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/reports/settings', ['low_sales_months' => 4])
            ->assertStatus(200)
            ->assertJsonPath('low_sales_months', 4)
            ->assertJsonPath('low_sales_threshold', 20000);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(4, ReportSettings::current()->low_sales_months);
    }
}
