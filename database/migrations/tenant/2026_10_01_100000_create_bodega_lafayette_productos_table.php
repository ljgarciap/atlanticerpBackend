<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-990 — espejo de solo consulta del catálogo de Lafayette Lighting, AISLADO del catálogo de AtlanticERP.
 *
 * Hasta SCRUM-971 el sincronizador escribía los 25.8K productos de Lafayette en `ventas_diseno.catalog_products`
 * y su `disponible` en `product_warehouse_stock` (bodega "Zona Libre"): eso mezclaba un catálogo que controla otra
 * empresa con el inventario de Atlantic (Zona Libre pesaba 20x el stock real) y sobrescribía productos ICG.
 * Decisión de Luis (2026-09-30): tabla propia; Zona Libre la consulta en solo lectura; usar un producto en AtlanticERP
 * es un alta manual. Ver docs/architecture/scrum990-aislar-lafayette-diseno.md, sección 1.
 *
 * Vive en el schema `bodega` (único consumidor: Bodega > Zona Libre; ya está en `Tenant::schemas()`).
 *
 * - `lafayette_id` UNIQUE = `id` de `vista_productos_lighting`: clave de upsert.
 * - `search_text` = columna GENERATED STORED (mismo patrón y misma función `public.f_unaccent()` que
 *   `catalog_products.search_text`, SCRUM-992) + índice GIN trigram, para el buscador de Zona Libre.
 * - Sin `LogsAuditTrail` en el modelo: repetiría la inundación de ~172K eventos de `audit_logs` del catálogo.
 * - `fuera_de_fuente_at`: el producto que desaparece de la fuente se marca y se oculta, nunca se borra.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        // Idempotente y compartido por todos los tenants de la base (ver migración de SCRUM-992): se reafirma aquí
        // para que esta migración no dependa del orden de las de `ventas_diseno`.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent SCHEMA public');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.f_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        Schema::create($this->table('lafayette_productos'), function (Blueprint $table): void {
            $table->id();
            $table->string('lafayette_id', 50)->unique();
            $table->string('referencia', 100)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('marca', 255)->nullable();
            $table->string('categoria', 255)->nullable();
            $table->text('url_imagen')->nullable();
            $table->integer('disponible')->default(0);
            $table->decimal('precio_5', 15, 4)->nullable();
            $table->timestampTz('fuente_actualizado_at')->nullable();
            $table->timestampTz('fuera_de_fuente_at')->nullable();
            $table->timestampsTz();
        });

        $t = $this->table('lafayette_productos');

        DB::statement(<<<SQL
            ALTER TABLE {$t} ADD COLUMN search_text text
            GENERATED ALWAYS AS (
                public.f_unaccent(lower(
                    coalesce(lafayette_id, '') || ' ' || coalesce(referencia, '') || ' ' ||
                    coalesce(descripcion, '') || ' ' || coalesce(marca, '')
                ))
            ) STORED
        SQL);

        DB::statement("CREATE INDEX lafayette_productos_search_text_trgm_idx ON {$t} USING gin (search_text public.gin_trgm_ops)");
        DB::statement("CREATE INDEX lafayette_productos_categoria_idx ON {$t} (categoria) WHERE fuera_de_fuente_at IS NULL");
        DB::statement("CREATE INDEX lafayette_productos_disponible_idx ON {$t} (disponible) WHERE fuera_de_fuente_at IS NULL");
        DB::statement("CREATE INDEX lafayette_productos_referencia_idx ON {$t} (referencia) WHERE fuera_de_fuente_at IS NULL");
    }

    public function down(): void
    {
        // Las extensiones y la función son compartidas por todos los tenants: nunca se eliminan aquí.
        Schema::dropIfExists($this->table('lafayette_productos'));
    }
};
