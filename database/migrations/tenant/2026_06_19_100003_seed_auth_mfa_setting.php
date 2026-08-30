<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('system_settings'))->insertOrIgnore([
            'key'        => 'mfa_mode',
            'value'      => DB::raw("'\"optional\"'::jsonb"),
            'updated_by' => null,
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('system_settings'))->where('key', 'mfa_mode')->delete();
    }
};
