<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch de endurecimiento FE/MSL (2026-09-29) — motivo textual del rechazo del PAC (ej.
 * "9026 - Falló la validación de algunos escenarios" + regla VALIDATE_B07a). Hasta ahora solo
 * quedaba en `laravel.log`, y diagnosticar un rechazo exigía SSH al servidor. Aditiva y nullable:
 * nadie la lee hasta que `FiscalCertificationService::persistirResultado()` la escribe.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        foreach (['invoices', 'credit_notes'] as $tabla) {
            Schema::table($this->table($tabla), function (Blueprint $table): void {
                $table->text('fe_detalle')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'credit_notes'] as $tabla) {
            Schema::table($this->table($tabla), function (Blueprint $table): void {
                $table->dropColumn('fe_detalle');
            });
        }
    }
};
