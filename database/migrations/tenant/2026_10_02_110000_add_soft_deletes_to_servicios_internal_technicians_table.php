<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1015 — "Eliminar" un técnico interno es un soft delete: deja de aparecer en el listado y de
 * ser asignable, pero los tickets/comisiones/herramientas que lo referencian quedan consistentes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('internal_technicians'), function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('internal_technicians'), function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
