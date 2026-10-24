<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\AdminCont\Models\CommissionMonthSnapshot;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\VentasDiseno\Models\CommissionCohort;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (Pre-QA MEDIO-2) — «Ver como usuario» es de solo lectura también para los GET que, al calcular, congelaban un snapshot de comisión de un
 * mes cerrado (Admin&Cont `commission_month_snapshots`, Ventas & Diseño `commission_cohorts`). Con el claim `act` se calcula y se devuelve lo MISMO sin
 * persistir; sin `act` el snapshot se sigue creando igual que siempre.
 */
final class ImpersonationSnapshotsTest extends FeatureTestCase
{
    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMakeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userId(string $email): int
    {
        $this->reMakeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** Pone una request con claim `act` como la actual (igual que la dejaría AuthenticateJwt). */
    private function actAsImpersonation(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', [
            'sub' => $this->userId('designer@atlantic.test'),
            'act' => ['sub' => $this->userId('superadmin@atlantic.test'), 'imp_id' => (string) Str::uuid()],
            'tenant' => 'atlantic',
        ]);
        $this->app->instance('request', $request);
    }

    private function actAsNormalUser(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', ['sub' => $this->userId('designer@atlantic.test'), 'tenant' => 'atlantic']);
        $this->app->instance('request', $request);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function ordersOf(CarbonImmutable $closedMonth, float $total): Collection
    {
        return collect([['mes_cierre' => $closedMonth, 'total_pedido' => $total]]);
    }

    // ── Admin&Cont: commission_month_snapshots ────────────────────────────────────────────────────────────

    public function test_con_act_el_mes_cerrado_se_calcula_y_no_se_congela_el_snapshot(): void
    {
        $vendor = $this->userId('designer@atlantic.test');
        $month  = CarbonImmutable::now()->subMonths(2)->startOfMonth();
        $this->actAsImpersonation();

        $result = app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 12000.0));

        $this->reMakeCurrent();
        $this->assertSame(0, CommissionMonthSnapshot::query()->count(), 'ninguna fila nueva');
        $this->assertSame(12000.0, $result['total']);
        $this->assertTrue($result['fijo'], 'devuelve lo mismo que con el snapshot ya congelado');
        $this->assertGreaterThan(0, $result['porcentaje']);
    }

    public function test_sin_act_el_snapshot_se_sigue_creando_y_los_datos_son_los_mismos(): void
    {
        $vendor = $this->userId('designer@atlantic.test');
        $month  = CarbonImmutable::now()->subMonths(2)->startOfMonth();

        $this->actAsImpersonation();
        $viewed = app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 12000.0));

        $this->actAsNormalUser();
        $real = app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 12000.0));

        $this->reMakeCurrent();
        $this->assertSame(1, CommissionMonthSnapshot::query()->count(), 'sin act se congela como siempre');
        $this->assertEquals($real, $viewed, 'el observador ve exactamente lo que verá la persona');
    }

    public function test_con_act_un_snapshot_ya_congelado_se_lee_sin_cambios(): void
    {
        $vendor = $this->userId('designer@atlantic.test');
        $month  = CarbonImmutable::now()->subMonths(2)->startOfMonth();
        $this->actAsNormalUser();
        app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 12000.0));
        $this->reMakeCurrent();
        $before = CommissionMonthSnapshot::query()->firstOrFail()->getAttributes();

        $this->actAsImpersonation();
        $result = app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 99999.0));

        $this->reMakeCurrent();
        $this->assertSame(1, CommissionMonthSnapshot::query()->count());
        $this->assertEquals($before, CommissionMonthSnapshot::query()->firstOrFail()->getAttributes());
        $this->assertSame(12000.0, (float) $result['total'], 'lee el valor congelado, no recalcula');
    }

    public function test_el_mes_en_curso_no_cambia_con_act(): void
    {
        $vendor = $this->userId('designer@atlantic.test');
        $month  = CarbonImmutable::now()->startOfMonth();
        $this->actAsImpersonation();

        $result = app(CommissionOrderService::class)->percentForVendorMonth($vendor, $month, $this->ordersOf($month, 5000.0));

        $this->assertFalse($result['fijo']);
        $this->reMakeCurrent();
        $this->assertSame(0, CommissionMonthSnapshot::query()->count());
    }

    // ── Valores por defecto que se crean de forma perezosa en la primera lectura ──────────────────────────

    /** @return array<string, array{0: class-string, 1: class-string, 2: string}> servicio, modelo de la tabla y método de lectura */
    public static function ajustesPerezosos(): array
    {
        return [
            'Admin&Cont' => [\App\Modules\AdminCont\Services\AdminContSettingsService::class, \App\Modules\AdminCont\Models\AdminContSetting::class, 'fraudeMontoVentanaDias'],
            'Bodega'     => [\App\Modules\Bodega\Services\BodegaSettingsService::class, \App\Modules\Bodega\Models\BodegaSetting::class, 'urgenteWindowDays'],
            'CRM'        => [\App\Modules\CRM\Services\CrmSettingsService::class, \App\Modules\CRM\Models\CrmSetting::class, 'proximoDias'],
            'Servicios'  => [\App\Modules\Servicios\Services\ServiciosSettingsService::class, \App\Modules\Servicios\Models\ServiciosSetting::class, 'itbmsPercent'],
        ];
    }

    /**
     * @param class-string $service
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ajustesPerezosos')]
    public function test_con_act_el_valor_por_defecto_se_devuelve_sin_crear_la_fila_y_sin_act_se_crea(string $service, string $model, string $method): void
    {
        $this->reMakeCurrent();
        $model::query()->delete();

        $this->actAsImpersonation();
        $viewed = app($service)->{$method}();
        $this->reMakeCurrent();
        $this->assertSame(0, $model::query()->count(), 'con act no se escribe nada');

        $this->actAsNormalUser();
        $real = app($service)->{$method}();
        $this->reMakeCurrent();
        $this->assertSame(1, $model::query()->count(), 'sin act la fila por defecto se crea como siempre');
        $this->assertSame($real, $viewed, 'mismo valor en ambos casos');
    }

    // ── Ventas & Diseño: commission_cohorts (HTTP) ────────────────────────────────────────────────────────

    private function makeApprovedCard(int $ownerId, float $amount, \Carbon\Carbon $stageChangedAt): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'amount' => $amount, 'stage_changed_at' => $stageChangedAt,
        ]);
    }

    public function test_el_reporte_de_comisiones_no_congela_la_cohorte_durante_la_sesion_y_si_fuera_de_ella(): void
    {
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $designer = $this->userId('designer@atlantic.test');
        $month    = now()->subMonths(5);
        $this->makeApprovedCard($designer, 25000, $month);
        $this->reMakeCurrent();
        $before = CommissionCohort::query()->count();

        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $token = (string) $this->postJson("/api/users/{$designer}/impersonate", ['reason' => 'SCRUM-1158 ver comisiones', 'operator_password' => self::PASSWORD], $admin)
            ->assertStatus(201)->json('token');

        $url = '/api/ventas-diseno/reports/commissions?scope=own&period=year';
        $viewed = $this->getJson($url, $this->authHeaders($token))->assertOk();
        $viewedRow = collect($viewed->json('cohorts'))->firstWhere('month', $month->format('Y-m'));

        $this->reMakeCurrent();
        $this->assertSame($before, CommissionCohort::query()->count(), 'la sesión de solo lectura no congela la cohorte');
        $this->assertNotNull($viewedRow);
        $this->assertEquals(25000, $viewedRow['total_amount']);
        $this->assertEquals(2.0, $viewedRow['commission_percent']);
        $this->assertEquals(500, $viewedRow['commission_amount']);

        // La persona, en su propia sesión, sí la congela y ve los mismos números.
        $own = $this->getJson($url, $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer')))->assertOk();
        $ownRow = collect($own->json('cohorts'))->firstWhere('month', $month->format('Y-m'));
        $this->reMakeCurrent();
        $this->assertSame($before + 1, CommissionCohort::query()->count());
        $this->assertNotNull($ownRow['id']);
        $this->assertEquals($viewedRow['total_amount'], $ownRow['total_amount']);
        $this->assertEquals($viewedRow['commission_percent'], $ownRow['commission_percent']);
        $this->assertEquals($viewedRow['commission_amount'], $ownRow['commission_amount']);
    }

    public function test_la_pantalla_de_inicio_del_observado_responde_200_con_los_mismos_datos(): void
    {
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $designer = $this->userId('designer@atlantic.test');

        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $token = (string) $this->postJson("/api/users/{$designer}/impersonate", ['reason' => 'SCRUM-1158 ver inicio', 'operator_password' => self::PASSWORD], $admin)
            ->assertStatus(201)->json('token');

        $viewed = $this->getJson('/api/ventas-diseno/home/summary', $this->authHeaders($token))->assertOk();
        $own    = $this->getJson('/api/ventas-diseno/home/summary', $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer')))->assertOk();

        $this->assertEquals($own->json(), $viewed->json());
    }
}
