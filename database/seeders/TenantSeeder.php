<?php

namespace Database\Seeders;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class TenantSeeder extends Seeder
{
    /**
     * Crea el primer tenant del proyecto (`illuminations`): sus schemas
     * Postgres, el registro landlord y corre sus migrations de módulo.
     */
    public function run(): void
    {
        if (Tenant::where('slug', 'illuminations')->exists()) {
            return;
        }

        Artisan::call('tenant:create', [
            'slug'     => 'illuminations',
            'name'     => 'Illuminations',
            '--domain' => 'atlanticerp.ai',
        ]);
    }
}
