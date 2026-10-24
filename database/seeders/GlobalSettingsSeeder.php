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

        // SCRUM-812 (REQ-636, Epic SCRUM-788) — retención en caliente antes de archivar a S3 y
        // purgar de Postgres. 12 meses, decisión del CTO 2026-08-30 (ver ArchiveAuditLogsJob).
        GlobalSetting::firstOrCreate(
            ['key' => 'audit_logs.retention_months'],
            ['value' => '12', 'updated_by' => null, 'updated_at' => now()],
        );
    }
}
