<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7a — los tipos de asociado pasan a ser un catálogo de la empresa (`catalog_entries`, dominio `architect_type`), así que la lista fija
 * del CHECK de `architects.type` (SCRUM-1121) ya no puede decidir qué tipos existen: la validación vive en la aplicación (contra el catálogo)
 * y la columna admite claves de hasta 60 caracteres, igual que el catálogo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $architects = $this->table('architects');
        DB::statement("ALTER TABLE {$architects} DROP CONSTRAINT IF EXISTS architects_type_check");
        Schema::table($architects, function (Blueprint $table): void {
            $table->string('type', 60)->default('architect')->change();
        });
    }

    public function down(): void
    {
        // Solo es reversible mientras todos los tipos guardados sean de la lista original; si no, el CHECK falla y hay que reasignarlos antes.
        $architects = $this->table('architects');
        Schema::table($architects, function (Blueprint $table): void {
            $table->string('type', 20)->default('architect')->change();
        });
        DB::statement("ALTER TABLE {$architects} ADD CONSTRAINT architects_type_check CHECK (type IN ('architect','designer','contractor','engineer','consultant','other'))");
    }
};
