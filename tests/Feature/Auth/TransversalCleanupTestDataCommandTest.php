<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\CRM\Models\Project;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-901 — cobertura del comando `data:cleanup-test-data`. Corre siempre en modo dry-run
 * (nunca --force) salvo el test explícito de ejecución, que verifica sobre una BD de test
 * descartable (RefreshDatabase) — nunca contra dev/test reales.
 */
final class TransversalCleanupTestDataCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_dry_run_identifica_fila_con_fk_not_null_como_candidata_a_borrar(): void
    {
        $purgeUserId = $this->userIdByEmail('management@atlantic.test');

        // refresh_tokens.user_id es NOT NULL — candidata directa a DELETE.
        $tokenId = DB::table('atlantic_auth.refresh_tokens')->insertGetId([
            'user_id'    => $purgeUserId,
            'token_hash' => hash('sha256', 'scrum-901-test-'.self::uniqueTestNumber()),
            'expires_at' => now()->addDay(),
            'revoked'    => false,
            'created_at' => now(),
        ]);

        $report = $this->runReport('vendedor@atlantic.test');

        $refreshTokenCandidate = collect($report['delete_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_auth.refresh_tokens');

        $this->assertNotNull($refreshTokenCandidate);
        $this->assertContains($tokenId, $refreshTokenCandidate['ids']);

        // Dry-run: la fila sigue existiendo.
        $this->assertDatabaseHas('atlantic_auth.refresh_tokens', ['id' => $tokenId]);
    }

    public function test_dry_run_identifica_fk_nullable_como_candidata_a_null_out_sin_borrar_la_fila(): void
    {
        $purgeUserId = $this->userIdByEmail('management@atlantic.test');

        // crm.projects.created_by es NULLABLE — candidata a poner en NULL, la fila se conserva.
        $project = Project::create([
            'nombre'     => 'Proyecto de prueba SCRUM-901 #'.self::uniqueTestNumber(),
            'etapa'      => 'lead',
            'tipo'       => 'diseno',
            'created_by' => $purgeUserId,
        ]);

        $report = $this->runReport('vendedor@atlantic.test');

        $projectCandidate = collect($report['null_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_crm.projects');

        $this->assertNotNull($projectCandidate);
        $this->assertContains($project->id, $projectCandidate['ids']);
        $this->assertDatabaseHas('atlantic_crm.projects', ['id' => $project->id, 'created_by' => $purgeUserId]);
    }

    public function test_data_del_usuario_a_conservar_nunca_aparece_como_candidata(): void
    {
        $keepUserId = $this->userIdByEmail('vendedor@atlantic.test');

        $project = Project::create([
            'nombre'     => 'Proyecto de Vendedor/keep-user #'.self::uniqueTestNumber(),
            'etapa'      => 'lead',
            'tipo'       => 'diseno',
            'created_by' => $keepUserId,
        ]);

        $report = $this->runReport('vendedor@atlantic.test');

        // El proyecto es del keep-user — la fila entera de atlantic_crm.projects ni
        // siquiera debería calificar como candidata (ni a NULL ni a DELETE). Comparar SOLO
        // contra esta tabla específica: un id de otra tabla (ej. una fila de config con id=1)
        // no tiene relación con el id de este proyecto.
        $projectsNullCandidate = collect($report['null_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_crm.projects');
        $projectsDeleteCandidate = collect($report['delete_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_crm.projects');

        if ($projectsNullCandidate !== null) {
            $this->assertNotContains($project->id, $projectsNullCandidate['ids'], "project {$project->id} (keep-user) apareció como null_candidate");
        } else {
            $this->assertTrue(true, 'atlantic_crm.projects no aparece como null_candidate — correcto, nada del keep-user calificó.');
        }

        if ($projectsDeleteCandidate !== null) {
            $this->assertNotContains($project->id, $projectsDeleteCandidate['ids'], "project {$project->id} (keep-user) apareció como delete_candidate");
        } else {
            $this->assertTrue(true, 'atlantic_crm.projects no aparece como delete_candidate — correcto, nada del keep-user calificó.');
        }

        // Aserción positiva real (no vacía): la fila del keep-user sigue intacta, con su dueño
        // original, sin importar qué haya pasado con las demás.
        $this->assertDatabaseHas('atlantic_crm.projects', ['id' => $project->id, 'created_by' => $keepUserId]);
    }

    /**
     * SCRUM-901 — encontrado corriendo el dry-run real contra test.atlanticerp.ai: el guard de
     * referencia activa bloqueaba de más, excluyendo `quotes` de la limpieza solo porque sus
     * propias `quote_parts` (relación padre-hijo normal, `quote_id` CASCADE) las referencian —
     * ninguna fila sobrevive de verdad ahí, cascadearía sola. El guard solo debe bloquear
     * referencias RESTRICT/NO ACTION (riesgo real de romper una fila sobreviviente), nunca
     * CASCADE/SET NULL/SET DEFAULT.
     */
    public function test_guard_de_referencia_activa_no_bloquea_relacion_padre_hijo_con_cascade(): void
    {
        $purgeUserId = $this->userIdByEmail('management@atlantic.test');

        $quote = Quote::create(['owner_id' => $purgeUserId]);
        QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida de prueba']);

        $report = $this->runReport('vendedor@atlantic.test');

        $quotesCandidate = collect($report['delete_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_ventas_diseno.quotes');

        $this->assertNotNull($quotesCandidate, 'quotes debería calificar como delete_candidate (owner_id NOT NULL)');
        $this->assertContains($quote->id, $quotesCandidate['ids'], 'el quote_part (CASCADE) no debería bloquearlo — el guard solo bloquea RESTRICT/NO ACTION');

        $excludedForThisQuote = collect($report['excluded'])
            ->first(fn (array $e): bool => $e['table'] === 'atlantic_ventas_diseno.quotes' && $e['id'] === $quote->id);
        $this->assertNull($excludedForThisQuote, 'no debería aparecer en excluded — quote_parts cascadea, no bloquea');
    }

    /**
     * SCRUM-901 — encontrado ejecutando --force real contra test.atlanticerp.ai: `audit_logs.user_id`
     * es nullable, así que calificaba como null_candidate, pero Postgres revoca UPDATE/DELETE
     * sobre esa tabla para el rol de conexión de la app (`atlanticerp_app`, ver
     * GrantAppRoleCommand::revokeAuditLogsWrite(), REQ-634/SCRUM-810 — inmutabilidad de
     * auditoría a propósito). execute() tiraba "permission denied", la transacción completa
     * hacía rollback (verificado que no dejaba nada a medias) pero bloqueaba cualquier corrida
     * real. audit_logs nunca debe aparecer como candidata — conservar su user_id histórico es
     * lo correcto de todos modos, dado que el usuario nunca se borra.
     */
    public function test_audit_logs_nunca_aparece_como_candidata_es_inmutable(): void
    {
        $purgeUserId = $this->userIdByEmail('management@atlantic.test');

        AuditLog::create(['user_id' => $purgeUserId, 'action' => 'test.action.created']);

        $report = $this->runReport('vendedor@atlantic.test');

        $auditLogsCandidate = collect($report['null_candidates'])
            ->first(fn (array $c): bool => $c['table'] === 'atlantic_auth.audit_logs');

        $this->assertNull($auditLogsCandidate, 'audit_logs nunca debería calificar como candidata — es inmutable a nivel de rol de BD');
    }

    public function test_force_ejecuta_el_delete_y_el_null_out_reales(): void
    {
        $purgeUserId = $this->userIdByEmail('management@atlantic.test');

        $tokenId = DB::table('atlantic_auth.refresh_tokens')->insertGetId([
            'user_id'    => $purgeUserId,
            'token_hash' => hash('sha256', 'scrum-901-force-test-'.self::uniqueTestNumber()),
            'expires_at' => now()->addDay(),
            'revoked'    => false,
            'created_at' => now(),
        ]);
        $project = Project::create([
            'nombre'     => 'Proyecto a null-out #'.self::uniqueTestNumber(),
            'etapa'      => 'lead',
            'tipo'       => 'diseno',
            'created_by' => $purgeUserId,
        ]);

        Artisan::call('data:cleanup-test-data', [
            '--tenant'       => 'atlantic',
            '--keep-user'    => 'vendedor@atlantic.test',
            '--force'        => true,
            '--env-override' => true,
            '--json'         => true,
        ]);

        $this->assertDatabaseMissing('atlantic_auth.refresh_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('atlantic_crm.projects', ['id' => $project->id, 'created_by' => null]);
    }

    protected function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    /**
     * @return array{
     *   delete_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   null_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     * }
     */
    private function runReport(string $keepUserEmail): array
    {
        Artisan::call('data:cleanup-test-data', [
            '--tenant'    => 'atlantic',
            '--keep-user' => $keepUserEmail,
            '--json'      => true,
        ]);

        /** @var array{delete_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>, null_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>} $decoded */
        $decoded = json_decode(Artisan::output(), true);

        return $decoded;
    }
}
