<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Jobs\ArchiveAuditLogsJob;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

final class ArchiveAuditLogsCommand extends Command
{
    protected $signature   = 'audit-logs:archive';
    protected $description = 'Despacha ArchiveAuditLogsJob (REQ-636) para cada tenant activo';

    public function handle(): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // spatie/laravel-multitenancy trata todo Job como tenant-aware por defecto y
            // necesita el tenant activo EN EL MOMENTO DEL DISPATCH — mismo patrón que
            // ArchiveReadNotificationsCommand/SecurityAnalyzeCommand.
            $tenant->makeCurrent();

            try {
                ArchiveAuditLogsJob::dispatch($tenant->slug);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("ArchiveAuditLogsJob despachado para {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
