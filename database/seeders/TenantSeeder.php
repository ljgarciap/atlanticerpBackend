<?php

namespace Database\Seeders;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class TenantSeeder extends Seeder
{
    /**
     * Crea el primer tenant del proyecto (`atlantic`): sus schemas
     * Postgres, el registro landlord y corre sus migrations de módulo.
     */
    public function run(): void
    {
        if (Tenant::where('slug', 'atlantic')->exists()) {
            return;
        }

        Artisan::call('tenant:create', [
            'slug'     => 'atlantic',
            'name'     => 'Atlantic',
            '--domain' => 'atlanticerp.ai',
        ]);
    }
}
