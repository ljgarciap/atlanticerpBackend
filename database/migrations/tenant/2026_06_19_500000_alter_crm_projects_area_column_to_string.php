<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        // area was incorrectly typed as decimal(10,2) — it stores a project category
        // (e.g. Residencial, Comercial) not a surface measurement.
        $schema = $this->schema();
        DB::statement("ALTER TABLE \"{$schema}\".\"projects\" ALTER COLUMN area TYPE varchar(100) USING area::text");
    }

    public function down(): void
    {
        $schema = $this->schema();
        DB::statement("ALTER TABLE \"{$schema}\".\"projects\" ALTER COLUMN area TYPE decimal(10,2) USING NULL");
    }
};
