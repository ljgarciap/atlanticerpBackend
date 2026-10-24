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
 * SCRUM-884 — cubre `ventas_diseno:backfill-pipeline-card-created-by`: prioriza el `user_id` real
 * registrado en `audit_logs` para el evento de creación de la tarjeta, y cae a `owner_id` actual
 * cuando esa fila no existe (tarjetas creadas antes de que `LogsAuditTrail` cubriera este modelo).
 */
class VentasDisenoBackfillPipelineCardCreatedByCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCard(int $ownerId): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Backfill '.uniqid()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
    }

    public function test_backfillea_desde_audit_logs_cuando_existe_el_registro_de_creacion(): void
    {
        $ownerId   = $this->userIdByEmail('designer@atlantic.test');
        $realCreatorId = $this->userIdByEmail('management@atlantic.test');
        $card = $this->makeCard($ownerId);

        // makeCard() ya dispara un audit_logs con user_id NULL (LogsAuditTrail, sin contexto
        // JWT en un test) — se agrega a mano la fila "real" que un request HTTP sí habría
        // dejado, con el creador verdadero distinto del owner actual.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.audit_logs")->insert([
            'user_id' => $realCreatorId, 'action' => 'ventas_diseno.pipeline_card.created',
            'entity_type' => 'pipeline_card', 'entity_id' => (string) $card->id,
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'metadata' => '{}',
            'created_at' => Carbon::now(),
        ]);

        $this->artisan('ventas_diseno:backfill-pipeline-card-created-by', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($realCreatorId, $card->fresh()->created_by);
    }

    public function test_usa_owner_id_como_fallback_sin_registro_en_audit_logs(): void
    {
        // uploads/created_at real de LogsAuditTrail queda con user_id NULL en este test (sin
        // contexto JWT) — exactamente el caso "sin dato disponible" que la RN del ticket cubre
        // con el fallback a owner_id actual.
        $ownerId = $this->userIdByEmail('supervisor@atlantic.test');
        $card = $this->makeCard($ownerId);

        $this->artisan('ventas_diseno:backfill-pipeline-card-created-by', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($ownerId, $card->fresh()->created_by);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $card = $this->makeCard($ownerId);

        $this->artisan('ventas_diseno:backfill-pipeline-card-created-by', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($card->fresh()->created_by);
    }

    public function test_es_idempotente_no_toca_tarjetas_ya_backfilleadas(): void
    {
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $otherUserId = $this->userIdByEmail('management@atlantic.test');
        $card = $this->makeCard($ownerId);
        $card->update(['created_by' => $otherUserId]);

        $this->artisan('ventas_diseno:backfill-pipeline-card-created-by', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($otherUserId, $card->fresh()->created_by);
    }
}
