# Senior Review — Fase 7a: infraestructura de catálogos (2026-10-26)

**Veredicto**: aprobada, 0 bloqueantes. Spec: `docs/specs/fase7-catalogos.md` §6-§7.

## Verificado
- Con los datos sembrados el comportamiento es el de antes (origen local solo para `locales`; validación de identificación solo en `locales`); suites afectadas verdes, PHPStan 0.
- Administración exclusiva de `superadmin.all`: ningún permiso fino nuevo, por tanto sin riesgo de fuga por `security_level` compartido (lección de la Fase 2 del esquema de permisos).
- Entradas de sistema y entradas en uso protegidas contra borrado/desactivación; propiedades validadas contra el esquema del dominio (propiedad o valor desconocido → 422).
- Migraciones aditivas; la del CHECK de `architects.type` es reversible solo mientras los tipos guardados sean los 6 originales (documentado en `down()`).

## Sugerencias (no bloqueantes)
1. `CatalogService::keys()` hace una consulta por validación; si algún día se valida en bucle, memoizar por petición.
2. Etiquetas por idioma (hoy un solo texto por entrada) cuando una empresa opere en dos idiomas.
3. Mostrar los tipos de asociado desde el catálogo en el frontend (7b).
4. `ProviderOrigin`/tránsito aduanal siguen decididos por `origin` derivado: correcto, pero la secuencia de estados de una orden internacional sigue siendo la de Panamá (aduana/Zona Libre).
