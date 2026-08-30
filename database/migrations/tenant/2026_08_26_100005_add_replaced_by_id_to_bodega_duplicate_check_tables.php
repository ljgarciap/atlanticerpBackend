<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-797 (RN5→RN7, "conteos duplicados") — nuevo estado `reemplazada` (valor de string, sin
 * migración de enum — `estado` en ambas tablas ya es `string` libre) + `replaced_by_id`
 * autoreferenciado, para conservar trazabilidad cuando el usuario confirma reemplazar una
 * solicitud/conteo pendiente por uno nuevo (RN7: "debe conservarse la trazabilidad
 * correspondiente" — decisión explícita de Luis, 2026-08-26: estado nuevo en vez del DELETE físico
 * que ya usa el mecanismo de cruce existente en `GeneralCountController::submit()`, SCRUM-464,
 * que queda intacto — es un mecanismo distinto, no lo que este ticket pide cambiar).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        // Constraint nombrada explícitamente (mismo criterio que `orders_family_fk` en
        // `2026_07_22_100002_create_bodega_orders_table.php`) — el nombre autogenerado con schema
        // completo + nombre de tabla largo supera el límite de 63 bytes de Postgres.
        Schema::table($this->table('general_count_requests'), function (Blueprint $table): void {
            $table->foreignId('replaced_by_id')->nullable()->after('estado')
                ->constrained($this->table('general_count_requests'), 'id', 'general_count_requests_replaced_by_fk')
                ->nullOnDelete();
        });

        Schema::table($this->table('adjustment_request_lines'), function (Blueprint $table): void {
            $table->foreignId('replaced_by_id')->nullable()->after('estado')
                ->constrained($this->table('adjustment_request_lines'), 'id', 'adjustment_request_lines_replaced_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // dropConstrainedForeignId() asume el nombre de constraint autogenerado — el de acá se
        // nombró explícito arriba (mismo motivo, límite de 63 bytes de Postgres), así que hay que
        // dropear la FK nombrada y la columna por separado.
        Schema::table($this->table('general_count_requests'), function (Blueprint $table): void {
            $table->dropForeign('general_count_requests_replaced_by_fk');
            $table->dropColumn('replaced_by_id');
        });

        Schema::table($this->table('adjustment_request_lines'), function (Blueprint $table): void {
            $table->dropForeign('adjustment_request_lines_replaced_by_fk');
            $table->dropColumn('replaced_by_id');
        });
    }
};
