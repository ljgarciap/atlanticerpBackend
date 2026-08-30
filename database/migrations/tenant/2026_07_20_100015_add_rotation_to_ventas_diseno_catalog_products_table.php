<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-232 (REQ-169 RN/Escenario 1) — el KPI "En atención" debe excluir productos de rotación
 * "baja" en 0 unidades (solo cuentan en "Sin stock"), pero no existía ningún campo de rotación en
 * `catalog_products`. Nullable: producto sin clasificar cuenta en "En atención" (conservador —
 * se prefiere sobre-mostrar hasta que alguien lo clasifique, a ocultarlo por default).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->string('rotation', 10)->nullable()->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn('rotation');
        });
    }
};
