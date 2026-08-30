<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('system_settings'), function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->default(DB::raw("'null'::jsonb"));
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Seed defaults
        DB::table($this->table('system_settings'))->insert([
            ['key' => 'default_language',    'value' => DB::raw("'\"es\"'::jsonb"),  'updated_by' => null, 'updated_at' => null],
            ['key' => 'default_theme',       'value' => DB::raw("'\"light\"'::jsonb"), 'updated_by' => null, 'updated_at' => null],
            ['key' => 'session_timeout_min', 'value' => DB::raw("'60'::jsonb"),      'updated_by' => null, 'updated_at' => null],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('system_settings'));
    }
};
