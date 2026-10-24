# Spec: Búsqueda/autocomplete de catálogo lenta con ~30K productos — `pg_trgm` + `unaccent`

**Date**: 2026-09-28
**Requested by**: Luis ("el autocompletado tarda una eternidad")
**Ticket**: SCRUM-992
**Status**: APROBADA por Luis 2026-09-28, lista para PM (sin implementar). Diseño propuesto por análisis de
Arquitecto/DBA de la sesión 2026-09-28 (transcript `2b01a6c3…`), retomado y ajustado acá.
**Project**: AtlanticERP — `atlanticerp-backend` (módulos VentasDiseno, Compras, Bodega, Servicios) + `atlanticerp-frontend`
**Relacionada**: `compras-inventory-full-catalog-load-500.md`, `bodega-inventory-full-catalog-load-500.md`

## Problem
Los buscadores/autocompletes de productos son lentos con el catálogo actual (~29.6K productos activos,
~87% de Lafayette Lighting, cargados desde 2026-09-27). Antes eran ~3.7K y el problema no se notaba.

## Root cause
1. `FuzzySearchService` (`app/Modules/VentasDiseno/Services/FuzzySearchService.php`) calcula
   **Levenshtein en PHP** sobre un array completo, O(N × palabras × longitud), sin índice.
2. Cada buscador hace primero `->get()->all()` del catálogo y aplica el fuzzy después, así que la
   búsqueda cuesta lo mismo con 1 letra que con 10.
3. Frontend sin debounce: `CatalogPage.tsx` dispara `setSearch` → refetch en cada tecla
   (REQ-617 pide "tiempo real, sin botón intermedio"; eso no exige un request por tecla).

Call sites de `fuzzySearch->search(` en backend (a auditar uno por uno en la tarea de Arquitecto):
`Compras\ProviderController`, `Compras\CatalogProductSearchController`, `Compras\InventoryController`,
`Servicios\ProductLookupController`, `Servicios\ClientLookupService` (×2, clientes y subclientes),
`Bodega\BodegaInventoryController`, `Bodega\WarehouseController`, `Bodega\AdjustmentRequestController`,
`VentasDiseno\QuoteListService` (×2), `VentasDiseno\OrdersService`. Además
`VentasDiseno\CatalogProductController` ya NO usa fuzzy (SCRUM-796 sec. 6, coincidencia exacta/parcial
sin resultados "sin relación clara").

## Diseño propuesto
Usar Postgres, sin motor de búsqueda externo (Elasticsearch/Meilisearch/Typesense descartados: a 30K
filas `pg_trgm` sobra, y un servicio nuevo suma contenedor, sincronización y punto de falla).

1. **Migración**: `CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;`
   Verificado en dev: ambas están en `pg_available_extensions` de la imagen `postgres:16-alpine`
   (`pg_trgm 1.6`, `unaccent 1.1`), con `installed_version` vacío — **no hay que instalar paquetes ni
   cambiar la imagen, solo activar la extensión**. Confirmar que el rol de migración puede crearlas
   (ambas son *trusted* desde PG13; validar con `DB_MIGRATE_USERNAME` en dev antes de test/prod).
2. **Columna `search_text`** en `catalog_products` (schema `ventas_diseno`): concatenación normalizada
   (`unaccent(lower(...))`) de `reference`, `factory_reference`, `name`, `brand`, con **índice GIN**
   `gin_trgm_ops`. Nota técnica: `unaccent()` no es IMMUTABLE, no sirve directo en una columna
   generada; usar una función wrapper IMMUTABLE (patrón estándar) o mantener la columna por trigger.
   El Arquitecto elige y documenta en un ADR corto.
3. **Un único `CatalogProduct::scopeSearch()`** con `LIMIT` siempre en SQL, que reemplaza los
   `->get()->all()` + `FuzzySearchService` en los buscadores de catálogo.
4. **Frontend**: debounce de 250–300 ms en los inputs de búsqueda en tiempo real, y cancelar la
   petición anterior (TanStack Query lo hace con `queryKey` estable + `keepPreviousData`).

## Diseño técnico (Arquitecto, 2026-09-28) — decisiones de la migración
- **`unaccent()` no es IMMUTABLE**, así que no sirve directo en una columna generada ni en un índice.
  Se crea una función wrapper en el schema `public` (una vez por base de datos, no por tenant):
  `CREATE FUNCTION public.f_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
  $$ SELECT public.unaccent('public.unaccent', $1) $$;`
- **`search_text` como columna generada `STORED`** (no trigger): `GENERATED ALWAYS AS
  (public.f_unaccent(lower(coalesce(reference,'') || ' ' || coalesce(factory_reference,'') || ' ' ||
  coalesce(name,'') || ' ' || coalesce(brand,'')))) STORED`. Postgres la mantiene en cada
  INSERT/UPDATE, incluido el sync de Lafayette, sin código de sincronización. Un trigger solo se usa si
  el rol de migración no puede crear la función IMMUTABLE.
- **Índice**: `CREATE INDEX CONCURRENTLY IF NOT EXISTS ... USING gin (search_text gin_trgm_ops)` sobre
  `{tenant}_ventas_diseno.catalog_products`. Nota: `CONCURRENTLY` no corre dentro de una transacción de
  migración; si Laravel envuelve la migración en transacción, se declara `$withinTransaction = false`.
- **Extensiones**: `CREATE EXTENSION IF NOT EXISTS pg_trgm; ... unaccent;` en `public`. Se prueba primero
  en dev con el rol `DB_MIGRATE_USERNAME` real (ambas son *trusted* desde PG13; si falla por permisos, un
  superusuario las crea una sola vez y la migración solo verifica que existan).
- **`scopeSearch(Builder $q, string $term, bool $fuzzyFallback = false)`**: normaliza el término con la
  misma cadena (`f_unaccent(lower(...))`), busca `search_text ILIKE '%term%'` (usa el índice GIN) y
  ordena por relevancia (referencia exacta > prefijo > resto) con desempate por `id`. Con
  `$fuzzyFallback = true` y **0 resultados**, reintenta con `search_text % term` ordenado por
  `similarity()`. Los buscadores de SCRUM-796 lo llaman con `false`. Siempre con `LIMIT`.
- **Términos cortos**: con menos de 3 caracteres los trigramas no ayudan; se degrada a prefijo
  (`search_text ILIKE 'term%'`) o el frontend no dispara antes de 2–3 caracteres (criterio 8).
- **Frontend**: hook `useDebouncedValue(value, 250)` compartido, aplicado en `CatalogPage.tsx`, Nueva
  Orden e Inventario; `queryKey` estable y `placeholderData: keepPreviousData` para no parpadear.
- **Fase 1** (aprobada): Catálogo (Ventas&Diseño), Nueva Orden (`CatalogProductSearchController`) e
  Inventario (Compras y Bodega). Fase 2: `Servicios\ProductLookupController`, `AdjustmentRequestController`,
  `WarehouseController`, `QuoteListService`, `OrdersService`, y decisión caso por caso para Proveedores y
  clientes (listas chicas, pueden conservar `FuzzySearchService`).
- **Coordinación con SCRUM-993**: el scope se construye primero en la migración y el modelo de esta
  spec; SCRUM-993 puede arrancar en paralelo con el fuzzy sobre proyección liviana y adoptar
  `scopeSearch()` cuando esté listo.

## Acceptance criteria
1. Autocomplete de productos (Nueva Orden, Catálogo, Inventario, buscador de Servicios) responde en
   **p95 < 300 ms** en dev con el catálogo completo, medido en backend (no incluye red).
2. Ninguna búsqueda hidrata más de `per_page`/límite de modelos; verificado con test que cuenta modelos
   o mide memoria (< 32 MB pico por búsqueda).
3. Búsqueda por referencia, referencia de fábrica, nombre y marca, sin distinguir mayúsculas ni
   acentos ("iluminacion" encuentra "Iluminación").
4. Comportamiento por buscador documentado y con test: donde hoy hay coincidencia exacta/parcial
   (SCRUM-796 sec. 6) se mantiene **sin** tolerancia a typos.
5. Índice GIN presente y usado: `EXPLAIN` de la búsqueda muestra `Bitmap Index Scan` sobre el índice
   trigram, no `Seq Scan`. Se adjunta la salida al ticket.
6. La migración es idempotente, reversible y corre bien en dev, test y prod con el rol de migración
   real; el tiempo de creación del índice sobre 30K filas se mide y se reporta.
7. `search_text` se mantiene sola al insertar/actualizar productos (test: crear y editar un producto y
   buscarlo sin ningún paso manual). El sync de Lafayette también la deja consistente.
8. Frontend: una ráfaga de 10 teclas produce 1 request (o 2 como máximo), no 10. Test de vitest con
   fake timers. REQ-617 se sigue cumpliendo (sin botón intermedio).
9. `FuzzySearchService` se retira de los buscadores de catálogo migrados; queda solo donde la lista es
   chica y acotada (Proveedores, clientes) — decisión explícita por call site en el ADR.
10. Suite completa (`infra/test.sh` + `npx vitest run`) verde, PHPStan Level 8 limpio.

## Riesgos / consideraciones
- **Cambio de comportamiento**: Levenshtein tolera typos ("ilumnacion"); `ILIKE`/trigram por
  substring tolera menos. `similarity()`/`%` de `pg_trgm` sí tolera typos. Ver decisión de Luis abajo.
- El extremo del índice: `LIKE '%ab%'` con 1–2 caracteres no aprovecha trigramas (mínimo 3). Definir
  mínimo de 2–3 caracteres antes de disparar la búsqueda, o degradar a prefijo para términos cortos.
- Índice GIN sobre 30K filas es liviano (unos MB); el costo está en escrituras masivas (sync Lafayette).
  Medir el sync antes/después.
- `Compras\InventoryController` y `Bodega\*` tienen además el problema de memoria de sus specs
  respectivas; esta spec no lo reemplaza, se coordinan (mismo `scopeSearch()`).
- Schema-per-tenant: la migración va en `database/migrations/tenant/` y corre con
  `tenants:artisan migrate`; las extensiones son por base de datos, no por schema (crearlas una vez).
- Sin credenciales, sin datos personales nuevos, sin cambio de permisos.

## Decisiones de Luis (2026-09-28, resueltas)
1. **Typos**: coincidencia parcial sin acentos/mayúsculas + `similarity()` (pg_trgm) **solo como fallback
   cuando la parcial da 0 resultados**. Los buscadores que ya pidieron coincidencia exacta/parcial
   (SCRUM-796 sec. 6) quedan **sin** fallback.
2. **Alcance por fases**: Fase 1 = Catálogo (Ventas&Diseño), Nueva Orden (`CatalogProductSearchController`)
   e Inventario (Compras/Bodega), los que hoy causan 5xx/lentitud. Fase 2 = resto de los call sites.
   La migración de extensiones/índice/`search_text` y el `scopeSearch()` van en la Fase 1.

## Fuera de alcance
- Motor de búsqueda externo.
- Búsqueda semántica/embeddings.
- Rediseño de las pantallas.
