<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-237/240 (rebotes de Daniela Amaya 2026-08-12, ver estrategia de Batch 4 en memoria) —
 * causa raíz real: `catalog_products` solo tenía UNA columna de texto (`description`), que hacía
 * doble función de "nombre visible" del producto y "descripción" — por eso editar la Descripción
 * en el modal de SCRUM-237 cambiaba el nombre que se ve en toda la app (tablas, búsqueda, PDF,
 * Excel, candidatos de SCRUM-246, etc.).
 *
 * Backfill: `name = description` actual (preserva el nombre visible que ya existe), luego
 * `description = ''` (vacío, NO duplicado — decisión ya tomada por el Arquitecto, ver memoria de
 * la estrategia: mostrar el mismo texto 2 veces hasta que alguien lo edite a mano sería peor que
 * dejarlo vacío). Sin doctrine/dbal en el proyecto (ver
 * 2026_07_13_100001_make_sub_client_id_nullable...), `name` se agrega nullable, se backfillea, y
 * recién después se fuerza NOT NULL con SQL directo — nunca `Blueprint::change()`.
 *
 * `catalog_products` es tabla compartida entre Compras/Bodega/Servicios (ver
 * feedback_catalog_product_shared_table_pollution.md) — todo lugar que hoy lee `description` como
 * "el nombre" pasa a leer `name` en el mismo commit que esta migración (Backend Dev grep-eó los 3
 * módulos, no solo Compras).
 *
 * `description` pasa de NOT NULL a NULLABLE con default `''` (hallazgo real corrido contra el
 * seeder local, 2026-08-15): la columna original se creó `$table->string('description')` sin
 * `nullable()`, y todos los Request de creación de producto (Store/UpdateInventoryProductRequest,
 * StorePurchaseOrderRequest) ya la validan como `nullable` — sin este ajuste, cualquier alta que
 * no mande `description` explícito (la inmensa mayoría desde ahora) revienta con
 * "null value in column description violates not-null constraint" en vez de guardar vacío.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('catalog_products');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('name')->nullable()->after('reference');
        });

        DB::statement("UPDATE {$table} SET name = description");
        DB::statement("UPDATE {$table} SET description = ''");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN name SET NOT NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN description DROP NOT NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN description SET DEFAULT ''");
    }

    public function down(): void
    {
        $table = $this->table('catalog_products');

        // Recupera el nombre en `description` solo donde quedó vacía por el backfill de arriba —
        // si alguien ya editó `description` de verdad tras esta migración, ese texto se preserva
        // en vez de pisarlo con `name`.
        DB::statement("UPDATE {$table} SET description = name WHERE description = '' OR description IS NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN description DROP DEFAULT");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN description SET NOT NULL");

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('name');
        });
    }
};
