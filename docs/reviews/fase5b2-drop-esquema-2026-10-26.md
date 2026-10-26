# Senior Review — Fase 5b-2: retirar el esquema de Legacy/Lafayette y los campos «gemelo ICG»

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase5b-eliminar-legacy-lafayette.md` (§6, §8) · **Veredicto**: APROBADO, 0 bloqueantes. **Es una migración destructiva e irreversible.**

## Qué se revisó
- `2026_10_26_200000_retire_legacy_and_lafayette_schema.php`: orden de las operaciones (trigger → bodega → columnas → tablas), uso de `IF EXISTS` (re-ejecutable ante estados parciales), y las dos guardas (referencias a la bodega en cualquier entorno; datos en
  Legacy/Lafayette/gemelos fuera de local/dev/testing). `down()` falla a propósito con un mensaje claro: no hay forma honesta de restaurar datos borrados.
- Hallazgos de la propia suite que se corrigieron antes de entregar: (1) un SQL crudo en `ArchitectCommissionService` filtraba por `q.origin = 'comercial'`; (2) `GrantAppRoleCommand` falla si el schema `{tenant}_legacy` no existe → la migración borra sus
  tablas pero **conserva el schema vacío** (lo usan `Tenant::schemas()`, ese comando y las migraciones históricas); (3) el test de «8 bodegas» pasó a 7.
- Simplificación de la facturación: se quitaron las ramas de «pedido gemelo» de `InvoiceService` e `InvoiceBillingPlanner` y `ReceptorFiscalGuard::evaluarGemelo`. Sin gemelos en datos, el comportamiento de los pedidos normales no cambia (las suites de facturación, comisiones y notas de crédito pasan sin tocar).
- Se CONSERVA `catalog_products.search_text` (búsqueda del catálogo de Compras) y la restricción de `Warehouse::existsRule()` (la bodega debe existir).

## Verificación
- Backend: suite completa en una base fresca (las migraciones históricas crean las tablas y la nueva las borra: el mismo SQL que correría en un tenant existente), PHPStan nivel 8 limpio. Frontend: 234 archivos / 2944 tests, `tsc` y build limpios.
- Datos reales de la base local de desarrollo: 0 filas en las 34 tablas (salvo 1 fila de ajustes por defecto), 0 pedidos/cotizaciones gemelas, 0 productos con `external_source`, 0 referencias a la bodega «Zona Libre».

## Hallazgos (sin bloqueantes)
1. **Hay que hacer respaldo antes de desplegar** en cualquier entorno con datos: la migración es irreversible. Las guardas protegen contra olvidos, no sustituyen un respaldo.
2. **El schema `{tenant}_legacy` queda vacío** hasta aplanar las migraciones históricas (fase futura).
3. **Specs e2e históricos** (`e2e/preqa-bodegas`, `preqa-scrum752…`, `preqa-scrum329…`) mencionan «Bodega Zona Libre»; no corren en CI y quedan obsoletos.
4. **BD local de desarrollo**: para aplicar la migración sobre ella hay que usar el ciclo de reinicio ya documentado (`down -v` + `tenant:create` + `db:seed`); no hay atajo incremental con este setup.
