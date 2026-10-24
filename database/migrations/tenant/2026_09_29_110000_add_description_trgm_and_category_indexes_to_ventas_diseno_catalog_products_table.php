<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-992 (Pre-QA ronda 2, H11) — `GET /api/ventas-diseno/catalog` con texto tardaba 455–487 ms
 * (criterio: < 300 ms). Causa medida con EXPLAIN: la consulta busca cada palabra en `search_text`
 * OR descripción OR categoría OR nombre de familia, y ni la descripción ni la categoría tenían
 * índice, así que Postgres hacía un Parallel Seq Scan de los ~30K productos, dos veces por request
 * (conteo + página).
 *
 * - Índice GIN trigram sobre la descripción, con EXACTAMENTE la misma expresión que usa
 *   `CatalogService::whereWordMatches()` (`public.f_unaccent(lower(coalesce(description, '')))`):
 *   el planner solo usa un índice de expresión si coincide byte a byte. NO toca `search_text`.
 * - Índice btree sobre `category`: sin él, la rama `category IN (...)` (solo cuando la palabra
 *   coincide con una etiqueta de categoría) no es indexable y vuelve a forzar el Seq Scan de todo el
 *   OR. ~224 kB con 30K filas. Para una palabra que coincide con la categoría dominante (LAMPARAS,
 *   ~87% del catálogo) el Seq Scan sigue siendo el plan correcto: no hay índice que lo mejore.
 *
 * Requiere `public.f_unaccent()` y `pg_trgm` (migración 2026_09_29_100000). Sin `CONCURRENTLY` por la
 * misma razón que aquélla (no corre dentro de la transacción de la migración; con ~30K filas el
 * bloqueo de escritura es de una fracción de segundo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('catalog_products');

        DB::statement("CREATE INDEX IF NOT EXISTS catalog_products_description_trgm_idx ON {$table} USING gin ((public.f_unaccent(lower(coalesce(description, '')))) public.gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS catalog_products_category_idx ON {$table} (category)");
    }

    public function down(): void
    {
        $schema = $this->schema();

        DB::statement("DROP INDEX IF EXISTS {$schema}.catalog_products_category_idx");
        DB::statement("DROP INDEX IF EXISTS {$schema}.catalog_products_description_trgm_idx");
    }
};
