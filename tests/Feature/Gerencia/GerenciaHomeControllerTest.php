<?php

declare(strict_types=1);

namespace Tests\Feature\Gerencia;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-160 — Daniela Amaya, comentario 2026-08-26: cuando el rango filtrado del panel
 * "Proyectos" solo tenía datos reales en un mes, `chart_mensual` traía un único ítem — el
 * `BarChart` del frontend (flex-1 con un solo hijo) lo estiraba al 100% del ancho, y se veía
 * como un bloque grueso en vez de una barra dentro de un gráfico de barras. Ver
 * GerenciaHomeService::zeroFillMeses().
 */
class GerenciaHomeControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // La UserFactory del proyecto está rota (referencia columnas legadas `rol`/`nombre`/`activo`
    // que ya no existen en `atlantic_auth.users`, ver ADR-004/006) — se inserta el owner
    // directo por query builder, reusando el security_level_id de un usuario real ya sembrado,
    // en vez de depender de esa factory (fuera de alcance de este ticket arreglarla).
    private function createOwner(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        $securityLevelId = DB::table("{$slug}_auth.users")
            ->where('email', 'superadmin@atlantic.test')
            ->value('security_level_id');

        return DB::table("{$slug}_auth.users")->insertGetId([
            'first_name'        => 'Owner',
            'last_name'         => 'SCRUM-160',
            'email'             => 'owner-scrum-160@atlantic.test',
            'password_hash'     => bcrypt('irrelevant'),
            'security_level_id' => $securityLevelId,
            'created_at'        => Carbon::now(),
            'updated_at'        => Carbon::now(),
        ]);
    }

    public function test_proyectos_chart_mensual_rellena_con_cero_los_meses_sin_datos(): void
    {
        $ownerId = $this->createOwner();

        $master = MasterClient::create(['name' => 'Cliente SCRUM-160']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente SCRUM-160',
            'tax_id'            => '155-0000-9-2026',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto SCRUM-160']);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => 'lead',
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'amount'           => 51000,
            'stage_changed_at' => Carbon::parse('2026-08-15'),
        ]);
        $card->forceFill(['created_at' => Carbon::parse('2026-08-15')])->save();

        // loginAs() hace un round-trip HTTP real que olvida el tenant "actual" al terminar
        // (mismo gotcha que AdminContInvoiceControllerTest) — fixtures cross-schema antes de loguear.
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/gerencia/home?desde=2026-06-01&hasta=2026-08-31&vendedor_id={$ownerId}")
            ->assertStatus(200);

        $chart = $response->json('proyectos.chart_mensual');

        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_column($chart, 'mes'));
        $this->assertEquals(0.0, $chart[0]['monto']);
        $this->assertEquals(0.0, $chart[1]['monto']);
        $this->assertEquals(51000.0, $chart[2]['monto']);
    }
}
