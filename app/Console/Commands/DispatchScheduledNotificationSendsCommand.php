<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Notifications\Jobs\DispatchScheduledSendsJob;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

final class DispatchScheduledNotificationSendsCommand extends Command
{
    protected $signature   = 'notifications:dispatch-scheduled';
    protected $description = 'Despacha DispatchScheduledSendsJob para cada tenant activo';

    public function handle(): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            try {
                DispatchScheduledSendsJob::dispatch($tenant->slug);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("DispatchScheduledSendsJob despachado para {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
