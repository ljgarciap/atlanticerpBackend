<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1128 (rebote de Pre-QA) — `can_edit` del detalle del Pipeline debe reflejar el permiso real
 * de escritura (`ventas_diseno.edit`, el mismo que exigen las rutas). Contabilidad (solo lectura vía
 * `pipeline.read_all`) lo recibía en true en toda etapa no Aprobada y la UI le ofrecía acciones
 * que el backend rechazaba con 403.
 */
class PipelineCanEditTest extends FeatureTestCase
{
    private const FELIX = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function sellerId(): int
    {
        return (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');
    }

    private function makeCard(string $stage): PipelineCard
    {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->sellerId(), 'stage_changed_at' => Carbon::now(),
        ]);
    }

    /** @return array<string, string> */
    private function headersFor(string $email, string $password, string $role): array
    {
        $headers = $this->authHeaders($this->loginAs($email, $password, $role));
        $this->tenant();

        return $headers;
    }

    /** @return list<array{0: string}> */
    public static function etapasNoAprobadas(): array
    {
        return [['lead'], ['design'], ['quote'], ['proposal']];
    }

    /**
     * SCRUM-1139 — Contabilidad ahora SÍ edita el Pipeline (`ventas_diseno.pipeline.edit`, ver PipelineEditPermissionTest). Lo que
     * este test protege (SCRUM-1128) es que `can_edit` refleje el permiso real: quien solo lee con `pipeline.read_all`
     * (sin `.edit` ni `pipeline.edit`) recibe false, y las rutas de escritura lo rechazan con 403.
     *
     * @dataProvider etapasNoAprobadas
     */
    public function test_quien_solo_lee_con_read_all_recibe_can_edit_false_en_etapas_no_aprobadas(string $stage): void
    {
        $this->felixComoEnScrum1139();
        $contabilidad = \App\Models\User::where('email', self::FELIX)->firstOrFail();
        $contabilidad->update(['extra_permissions' => array_values(array_diff($contabilidad->extra_permissions ?? [], ['ventas_diseno.pipeline.edit']))]);

        $card    = $this->makeCard($stage);
        $headers = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', false);

        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['name' => 'X'])->assertStatus(403);
    }

    /** @dataProvider etapasNoAprobadas */
    public function test_vendedor_dueno_conserva_can_edit_true(string $stage): void
    {
        $card    = $this->makeCard($stage);
        $headers = $this->headersFor('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', true);
    }

    /** @dataProvider etapasNoAprobadas */
    public function test_gerencia_conserva_can_edit_true(string $stage): void
    {
        $card    = $this->makeCard($stage);
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', true);
    }

    public function test_superadmin_conserva_can_edit_true(): void
    {
        $card    = $this->makeCard('design');
        $headers = $this->headersFor('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', true);
    }
}
