<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Notifications\Jobs\ArchiveReadNotificationsJob;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

final class ArchiveReadNotificationsCommand extends Command
{
    protected $signature   = 'notifications:archive-read';
    protected $description = 'Despacha ArchiveReadNotificationsJob para cada tenant activo';

    public function handle(): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // spatie/laravel-multitenancy trata todo Job como tenant-aware por defecto y
            // necesita el tenant activo EN EL MOMENTO DEL DISPATCH — mismo patrón que
            // SecurityAnalyzeCommand.
            $tenant->makeCurrent();

            try {
                ArchiveReadNotificationsJob::dispatch($tenant->slug);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("ArchiveReadNotificationsJob despachado para {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
