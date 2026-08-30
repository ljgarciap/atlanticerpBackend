<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grupo 1d (REQ-019): "+ Nuevo Proyecto" tipo Lead crea una tarjeta sin cliente
 * vinculado ("cliente —"), pero todo pipeline_card exige un sales_project. Hasta acá
 * sales_projects.sub_client_id era NOT NULL porque el único origen de un proyecto era
 * la regla de Lead automático (REQ-068), que siempre parte de un Subcliente ya
 * existente. Sin doctrine/dbal en el proyecto, se altera la constraint directo con SQL
 * en vez de Blueprint::change().
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('sales_projects');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN sub_client_id DROP NOT NULL");
    }

    public function down(): void
    {
        $table = $this->table('sales_projects');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN sub_client_id SET NOT NULL");
    }
};
