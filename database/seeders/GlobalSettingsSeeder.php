<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\GlobalSetting;
use Illuminate\Database\Seeder;

class GlobalSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate only inserts when the key is absent, keeping admin-set values
        // intact across deploys. updateOrCreate would overwrite on every push to master.
        // Using the model so the encrypted cast is applied correctly.
        GlobalSetting::firstOrCreate(
            ['key' => 'documents.max_size_mb'],
            ['value' => '500', 'updated_by' => null, 'updated_at' => now()],
        );

        GlobalSetting::firstOrCreate(
            ['key' => 'notifications.archive_read_after_days'],
            ['value' => '30', 'updated_by' => null, 'updated_at' => now()],
        );
    }
}
