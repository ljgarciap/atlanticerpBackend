<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiAnalysis;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-927 (hallazgo lateral, 2026-09-17) — `ai:reset-stale` corría sin resolver el tenant
 * activo primero (`AiAnalysis::getTable()` exige uno), así que en el scheduler real (sin
 * request HTTP que active un tenant) explotaba con "No active tenant when querying ai_analyses"
 * en cada corrida — 598 fallos silenciosos acumulados en prod antes de detectarse. El fix itera
 * `Tenant::all()` igual que `DispatchScheduledNotificationSendsCommand`. Por diseño de
 * FeatureTestCase, `AuthUserSeeder` deja sin tenant activo al terminar de sembrar (mismo estado
 * que tiene el scheduler real al arrancar) — este test corre el comando SIN activar un tenant a
 * mano, para reproducir exactamente el contexto que rompía en prod.
 */
class ResetStaleAiJobsCommandTest extends FeatureTestCase
{
    private function createAnalysis(string $status, \DateTimeInterface $updatedAt): AiAnalysis
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant);
        $tenant->makeCurrent();

        try {
            $job = AiAnalysis::create([
                'entity_type'   => 'project',
                'entity_id'     => 1,
                'analysis_type' => 'summarize',
                'status'        => $status,
            ]);
            $job->forceFill(['updated_at' => $updatedAt])->save();

            return $job;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function statusOf(AiAnalysis $job): string
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant);
        $tenant->makeCurrent();
        try {
            $found = AiAnalysis::find($job->id);
            $this->assertNotNull($found);

            return $found->status;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    public function test_no_explota_sin_tenant_activo_y_resetea_jobs_estancados(): void
    {
        $stale = $this->createAnalysis(AiAnalysis::STATUS_RUNNING, now()->subMinutes(15));
        $fresh = $this->createAnalysis(AiAnalysis::STATUS_PENDING, now());
        $done  = $this->createAnalysis(AiAnalysis::STATUS_COMPLETED, now()->subMinutes(15));

        $this->assertNull(Tenant::current(), 'precondición: sin tenant activo, igual que el scheduler real');

        $exitCode = Artisan::call('ai:reset-stale', ['--minutes' => 10]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(AiAnalysis::STATUS_FAILED, $this->statusOf($stale));
        $this->assertSame(AiAnalysis::STATUS_PENDING, $this->statusOf($fresh));
        $this->assertSame(AiAnalysis::STATUS_COMPLETED, $this->statusOf($done));
    }

    public function test_no_hace_nada_si_no_hay_jobs_estancados(): void
    {
        $this->createAnalysis(AiAnalysis::STATUS_PENDING, now());

        $exitCode = Artisan::call('ai:reset-stale', ['--minutes' => 10]);

        $this->assertSame(0, $exitCode);
    }
}
