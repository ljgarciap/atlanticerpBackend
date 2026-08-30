<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-757 (épica SCRUM-749). Staging de PRESUPUESTOSLIN — a diferencia de
 * `legacy.contactos_cliente` (SCRUM-755, cuya PK real de ICG `CONTACTOSCLIENTES.ID` sirve de llave
 * natural para el upsert idempotente), PRESUPUESTOSLIN **no declara ninguna llave natural propia**
 * en el MER de origen (Illumination/migracion-icg-diseno.md §2) — solo el FK compuesto real
 * `(NUMSERIE,NUMPRESUPUESTO,N,VERSION) → PRESUPUESTOSCAB`. No hay una columna de "número de línea"
 * documentada para diferenciar líneas dentro de la misma cabecera.
 *
 * DECISIÓN DE DISEÑO (dentro del alcance aprobado, ver docblock de
 * `App\Modules\Legacy\Services\CotizacionStagingLoader`): en vez de inventar una PK que ICG no
 * declara, el Loader-A idempotiza esta tabla con un patrón **delete-then-insert scoped a las
 * cabeceras de la corrida actual** (nunca a todo el historial de `legacy.cotizacion_items`) — re-
 * correr el mismo extracto produce exactamente el mismo conjunto de filas cada vez. `id` es un
 * autoincremental interno sin significado de negocio, no se referencia desde ICG.
 *
 * `icg_codarticulo` nullable — mismo criterio que `icg_codcliente` en `legacy.cotizaciones`: FK
 * "por convención" (⚠️→ARTICULOS, §2), sin constraint real, puede no resolver a un
 * `legacy.productos.icg_codarticulo` conocido. No se valida contra `legacy.productos` en este
 * batch (fuera de alcance de SCRUM-757 — un futuro batch podría agregar esa referencia cruzada si
 * hace falta).
 *
 * Sin timestamps — alcance explícito del ticket (a diferencia de `legacy.cotizaciones`, que sí
 * tiene `created_at`); las filas se recrean en cada corrida vía el patrón delete-then-insert de
 * arriba, así que no hay un "cuándo se creó esta línea" significativo que preservar entre
 * corridas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('cotizacion_items'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legacy_cotizacion_id')
                ->constrained($this->table('cotizaciones'), 'id', 'legacy_cotizacion_items_cotizacion_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('icg_codarticulo')->nullable();
            $table->string('referencia')->nullable();
            $table->string('descripcion')->nullable();
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('descuento', 5, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->decimal('costo', 12, 2)->nullable();

            $table->index('legacy_cotizacion_id');
            $table->index('icg_codarticulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cotizacion_items'));
    }
};
