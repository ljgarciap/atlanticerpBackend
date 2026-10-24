<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 — `source_hash` para los comandos incrementales de Albaranes (venta) y Albaranes de
 * Compra. Solo en la tabla de CABECERA, no en items — ver docblock de
 * App\Modules\Legacy\Services\AlbaranIncrementalStagingLoader para la decisión de diseño de por
 * qué (items no tienen llave natural propia y se paginan para evitar el OOM ya documentado —
 * agregarles hash individual reintroduciría el problema que la paginación resuelve).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('albaranes'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('observaciones');
        });

        Schema::table($this->table('albaranes_compra'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('icg_numfac');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('albaranes'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('albaranes_compra'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
