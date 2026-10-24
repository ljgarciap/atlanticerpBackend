<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-990 — `warehouses.solo_consulta`: bodega de solo consulta (catálogo de una empresa externa que NO forma
 * parte del inventario de Atlantic). Reemplaza a `mostrar_sin_stock` (SCRUM-973), que solo servía a "Bodega Zona
 * Libre"; esa columna se conserva, obsoleta, hasta el Release E para que el rollback inmediato siga posible.
 *
 * Mismo patrón que `excluye_disponible` (SCRUM-851): un booleano por bodega en vez de un nombre fijo en el código.
 * Backfill: "Bodega Zona Libre" = true.
 *
 * Defensa en profundidad: un trigger en `product_warehouse_stock` rechaza INSERT/UPDATE de filas de una bodega
 * `solo_consulta`, incluso por SQL directo o por `Model::query()->update()` (que no dispara eventos de Eloquent).
 * No bloquea los DELETE: la limpieza de SCRUM-990 (`lafayette:aislar-catalogo`) borra las filas históricas de Zona Libre.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->boolean('solo_consulta')->default(false)->after('mostrar_sin_stock');
        });

        DB::table($this->table('warehouses'))
            ->where('name', 'Bodega Zona Libre')
            ->update(['solo_consulta' => true]);

        $schema = $this->schema();
        $function = "{$schema}.reject_stock_en_bodega_solo_consulta";

        DB::unprepared(str_replace(
            ['__FUNCTION__', '__SCHEMA__'],
            [$function, $schema],
            <<<'SQL'
            CREATE OR REPLACE FUNCTION __FUNCTION__() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM __SCHEMA__.warehouses w WHERE w.id = NEW.warehouse_id AND w.solo_consulta) THEN
                    RAISE EXCEPTION 'La bodega % es de solo consulta: no admite stock.', NEW.warehouse_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL,
        ));

        DB::unprepared("DROP TRIGGER IF EXISTS product_warehouse_stock_solo_consulta ON {$schema}.product_warehouse_stock");
        DB::unprepared(
            "CREATE TRIGGER product_warehouse_stock_solo_consulta BEFORE INSERT OR UPDATE OF warehouse_id, quantity "
            . "ON {$schema}.product_warehouse_stock FOR EACH ROW EXECUTE FUNCTION {$function}()"
        );
    }

    public function down(): void
    {
        $schema = $this->schema();

        DB::unprepared("DROP TRIGGER IF EXISTS product_warehouse_stock_solo_consulta ON {$schema}.product_warehouse_stock");
        DB::unprepared("DROP FUNCTION IF EXISTS {$schema}.reject_stock_en_bodega_solo_consulta()");

        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->dropColumn('solo_consulta');
        });
    }
};
