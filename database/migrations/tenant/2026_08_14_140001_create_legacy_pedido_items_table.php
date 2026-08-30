<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-758 (épica SCRUM-749). Staging de PEDVENTALIN — mismo problema y misma solución que
 * `legacy.cotizacion_items` (PRESUPUESTOSLIN, SCRUM-757): PEDVENTALIN **no declara ninguna llave
 * natural propia** en el MER de origen (Illumination/migracion-icg-diseno.md §2) — solo el FK
 * compuesto real `(NUMSERIE,NUMPEDIDO,N) → PEDVENTACAB`. No hay una columna de "número de línea"
 * documentada para diferenciar líneas dentro de la misma cabecera.
 *
 * DECISIÓN DE DISEÑO (dentro del alcance aprobado, ver docblock de
 * `App\Modules\Legacy\Services\PedidoStagingLoader`): mismo patrón delete-then-insert scoped a las
 * cabeceras de la corrida actual (nunca a todo el historial de `legacy.pedido_items`) que
 * `CotizacionStagingLoader` — re-correr el mismo extracto produce exactamente el mismo conjunto de
 * filas cada vez. `id` es un autoincremental interno sin significado de negocio, no se referencia
 * desde ICG.
 *
 * `icg_codarticulo` nullable — mismo criterio que `icg_codcliente` en `legacy.pedidos`: FK "por
 * convención" (⚠️→ARTICULOS, §2), sin constraint real, puede no resolver a un
 * `legacy.productos.icg_codarticulo` conocido. No se valida contra `legacy.productos` en este
 * batch (fuera de alcance de SCRUM-758).
 *
 * Sin timestamps — alcance explícito del ticket, mismo criterio que `legacy.cotizacion_items`; las
 * filas se recrean en cada corrida vía el patrón delete-then-insert de arriba.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('pedido_items'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legacy_pedido_id')
                ->constrained($this->table('pedidos'), 'id', 'legacy_pedido_items_pedido_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('icg_codarticulo')->nullable();
            $table->string('referencia')->nullable();
            $table->string('descripcion')->nullable();
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();

            $table->index('legacy_pedido_id');
            $table->index('icg_codarticulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pedido_items'));
    }
};
