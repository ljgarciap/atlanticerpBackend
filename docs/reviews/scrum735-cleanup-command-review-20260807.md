# Senior Review — SCRUM-735 (ventas-diseno:cleanup-test-data)

**Fecha:** 2026-08-07
**Commits revisados:** `c1e1a99` (implementación) + `96ee7b3` (fix del gap de `CatalogProduct`)
**Archivos:** `app/Console/Commands/VentasDisenoCleanupTestDataCommand.php`,
`app/Modules/VentasDiseno/Services/TestDataCleanupService.php`,
`tests/Feature/VentasDiseno/TestDataCleanupServiceTest.php`

## Metodología
Leí ambos archivos completos (no solo el diff), corrí `infra/test.sh --filter=TestDataCleanupServiceTest`
(13/13 verde) y `phpstan analyse --memory-limit=1G` sobre los 2 archivos nuevos (0 errores) de forma
independiente, sin confiar únicamente en el reporte del subagente que implementó.

## Hallazgos

🔴 **Ninguno.**

🟡 **Sugerencia (no bloqueante):** si este comando se corre repetidamente contra `test.atlanticerp.ai` con
un `audit_logs` que crece con el tiempo, considerar un índice compuesto `(user_id, action, entity_type)`
— hoy no existe y la query de `buildReport()` haría table scan. No es un problema real para una
herramienta operativa de uso puntual; solo vale la pena si se vuelve rutina.

## Verificación del diseño contra la spec del Arquitecto
- Candidatos = `audit_logs` con `user_id=Gerencia1`, `action='...created'` (nunca `updated`/`deleted`) ✅
- `SalesProject` inferido por `sub_client_id` candidato + ventana `created_at` ✅
- Marcadores `[DEMO-711]`/`[QA-VD]`/`[QA-VD-409]` protegidos, heredados por cadena de FKs hacia arriba ✅
- Guards de referencia activa para `MasterClient`/`SubClient`/`SalesProject` **y `CatalogProduct`**
  (gap real encontrado en esta misma revisión sobre el commit `c1e1a99`, corregido en `96ee7b3` —
  ver docblock de `CROSS_MODULE_CATALOG_PRODUCT_REFERENCES`, cubre las 13 tablas cross-schema reales
  de Compras/Bodega/Servicios que referencian `catalog_products.id`) ✅
- `catalog_product` faltaba en `DELETE_ORDER` en el commit original — corregido en `96ee7b3` ✅
- Solo corre en `--dry-run` hasta ahora; `--force` solo se ejecutó dentro de PHPUnit, nunca contra un
  entorno real ✅ (correcto, coincide con la instrucción de no correrlo en real sin OK de Luis)

## Veredicto

🟢 **APROBADO.** Listo para Pre-QA. El borrado real contra `test.atlanticerp.ai` sigue pendiente de una
revisión humana explícita del reporte `--dry-run` por parte de Luis — este ticket no debe transicionar
a "hecho" solo por este Senior Review.
