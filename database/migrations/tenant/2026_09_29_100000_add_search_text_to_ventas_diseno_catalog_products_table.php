<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-992 — búsqueda de catálogo con ~30K productos: `pg_trgm` + `unaccent`.
 * Spec: docs/specs/catalog-search-performance-trgm.md (sección "Diseño técnico").
 *
 * - Las extensiones y la función wrapper viven en `public`, UNA vez por base de datos (no por
 *   tenant); cada tenant las reafirma con `IF NOT EXISTS`/`CREATE OR REPLACE`, idempotente.
 * - `unaccent()` no es IMMUTABLE, así que no se puede usar en una columna generada ni en un
 *   índice: `public.f_unaccent()` es el wrapper IMMUTABLE estándar (con el diccionario
 *   `public.unaccent` calificado, sin depender del search_path).
 * - `search_text` = columna GENERATED STORED (referencia + referencia de fábrica + nombre +
 *   marca, en minúsculas y sin acentos): Postgres la mantiene sola en cada INSERT/UPDATE,
 *   incluido el sync de Lafayette, sin código de sincronización. No entra en `$fillable`.
 * - Índice GIN trigram sobre `search_text`. Se crea SIN `CONCURRENTLY`: con ~30K filas el
 *   bloqueo de escritura es de una fracción de segundo, y `CONCURRENTLY` no puede correr dentro
 *   de la transacción de la migración.
 *
 * `down()` solo revierte lo propio del tenant (índice + columna): las extensiones y la función
 * son compartidas por todos los tenants de la base de datos, nunca se eliminan acá.
 *
 * Requisito del rol de migración (DB_MIGRATE_USERNAME): poder ejecutar `CREATE EXTENSION`
 * (pg_trgm y unaccent son "trusted" desde PG13 — basta ser dueño de la base) y `CREATE FUNCTION`
 * en `public`. Si falla por permisos, un superusuario crea las extensiones una sola vez.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent SCHEMA public');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.f_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        $table = $this->table('catalog_products');

        DB::statement(<<<SQL
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS search_text text
            GENERATED ALWAYS AS (
                public.f_unaccent(lower(
                    coalesce(reference, '') || ' ' || coalesce(factory_reference, '') || ' ' ||
                    coalesce(name, '') || ' ' || coalesce(brand, '')
                ))
            ) STORED
        SQL);

        DB::statement("CREATE INDEX IF NOT EXISTS catalog_products_search_text_trgm_idx ON {$table} USING gin (search_text public.gin_trgm_ops)");
    }

    public function down(): void
    {
        $schema = $this->schema();
        $table = $this->table('catalog_products');

        DB::statement("DROP INDEX IF EXISTS {$schema}.catalog_products_search_text_trgm_idx");
        DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS search_text");
    }
};
