<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        $table = $this->table('project_documents');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS project_documents_categoria_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT project_documents_categoria_check CHECK (categoria IN ('cotizacion','diseno','presentacion','plano','render','ficha','modelo_3d','otro'))");
    }

    public function down(): void
    {
        $table = $this->table('project_documents');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS project_documents_categoria_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT project_documents_categoria_check CHECK (categoria IN ('cotizacion','diseno','presentacion','plano','render','ficha','otro'))");
    }
};
