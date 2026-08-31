<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307) — mapeo configurable vendedor→Asistente de Bodega, la
 * fuente de verdad de `Order.assistant_id` al aprobar una cotización (REQ-308). RN3 de REQ-307 es
 * explícito: "esta tabla de mapeo debe ser configurable (no codificada de forma fija)" — nunca un
 * `match()`/array hardcodeado con los nombres reales (Asistente Bodega Test 2, Asistente Bodega Test, etc., ver
 * `database/seeders/WarehouseAssistantMappingSeeder.php` para el seed inicial real).
 *
 * `is_active` permite reasignar un vendedor a otro asistente sin perder el historial de quién
 * tuvo el mapeo antes — el índice único parcial de abajo garantiza una sola fila ACTIVA por
 * vendedor a la vez (una fila inactiva vieja del mismo vendedor puede seguir existiendo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';
        $table = $this->table('warehouse_assistant_mapping');

        Schema::create($table, function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->foreignId('seller_user_id')
                ->constrained("{$authSchema}.users", 'id', 'warehouse_assistant_mapping_seller_fk');
            $table->foreignId('assistant_user_id')
                ->constrained("{$authSchema}.users", 'id', 'warehouse_assistant_mapping_assistant_fk');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('seller_user_id', 'warehouse_assistant_mapping_seller_idx');
        });

        // Blueprint no soporta índices únicos parciales (WHERE) directamente — solo una fila
        // ACTIVA por vendedor a la vez, sin impedir guardar el historial de mapeos inactivos.
        DB::statement("CREATE UNIQUE INDEX warehouse_assistant_mapping_active_seller_uq ON {$table} (seller_user_id) WHERE is_active = true");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('warehouse_assistant_mapping'));
    }
};
