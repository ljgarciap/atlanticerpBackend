<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-759 (épica SCRUM-749). Staging de ALBVENTALIN — mismo problema y misma solución que
 * `legacy.pedido_items` (PEDVENTALIN, SCRUM-758): ALBVENTALIN **no declara ninguna llave natural
 * propia** en el MER de origen (Illumination/migracion-icg-diseno.md §2) — solo el FK compuesto
 * real `(NUMSERIE,NUMALBARAN,N) → ALBVENTACAB` (✅ declarada, a diferencia del link "por
 * convención" `CODCLIENTE`/`CODVENDEDOR`/`CODARTICULO`). No hay una columna de "número de línea"
 * documentada para diferenciar líneas dentro de la misma cabecera.
 *
 * DECISIÓN DE DISEÑO (dentro del alcance aprobado, ver docblock de
 * `App\Modules\Legacy\Services\AlbaranStagingLoader`): mismo patrón delete-then-insert scoped a las
 * cabeceras de la corrida actual (nunca a todo el historial de `legacy.albaran_items`) que
 * `PedidoStagingLoader`/`CotizacionStagingLoader` — re-correr el mismo extracto produce exactamente
 * el mismo conjunto de filas cada vez. `id` es un autoincremental interno sin significado de
 * negocio, no se referencia desde ICG.
 *
 * `icg_codarticulo` nullable — mismo criterio que en `legacy.pedido_items`: FK "por convención"
 * (⚠️→ARTICULOS, §2), sin constraint real, puede no resolver a un `legacy.productos.icg_codarticulo`
 * conocido. No se valida contra `legacy.productos` en este batch (fuera de alcance de SCRUM-759).
 *
 * Sin timestamps — alcance explícito del ticket, mismo criterio que `legacy.pedido_items`; las
 * filas se recrean en cada corrida vía el patrón delete-then-insert de arriba.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('albaran_items'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legacy_albaran_id')
                ->constrained($this->table('albaranes'), 'id', 'legacy_albaran_items_albaran_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('icg_codarticulo')->nullable();
            $table->string('referencia')->nullable();
            $table->string('descripcion')->nullable();
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();

            $table->index('legacy_albaran_id');
            $table->index('icg_codarticulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('albaran_items'));
    }
};
