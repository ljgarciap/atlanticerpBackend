<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Jobs\SecurityAnalysisJob;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

final class SecurityAnalyzeCommand extends Command
{
    protected $signature   = 'security:analyze';
    protected $description = 'Despacha SecurityAnalysisJob para cada tenant activo (Epic 11 — Seguridad Agéntica)';

    public function handle(): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // spatie/laravel-multitenancy trata todo Job como tenant-aware por defecto
            // (config('multitenancy.queues_are_tenant_aware_by_default')) y necesita el
            // tenant activo EN EL MOMENTO DEL DISPATCH para inyectar tenantId en el payload —
            // no alcanza con que el job lo resuelva por slug dentro de handle().
            $tenant->makeCurrent();

            try {
                SecurityAnalysisJob::dispatch($tenant->slug);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("SecurityAnalysisJob despachado para {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
