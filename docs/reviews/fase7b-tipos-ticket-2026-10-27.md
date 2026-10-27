# Senior Review — Fase 7b: tipos de ticket como catálogo (2026-10-27)

**Alcance**: backend `fase7b/tipos-ticket-infra` + `fase7b/tipos-ticket-consumidores` (6 commits) y frontend las dos ramas homónimas (2 commits).
**Veredicto**: APROBADO. 0 bloqueantes. 1 hallazgo corregido durante la revisión, 4 sugerencias abiertas.

## Qué se revisó
- Equivalencia con los datos por defecto: la siembra reproduce prefijos INS/GAR/REC/RET, subtipos y el SLA **vigente** de cada empresa (copia el ajuste `sla_dias_*` si estaba editado). `TicketTypeCatalogTest` fija esos valores como literales.
- Lógica por comportamiento, no por clave: grep de cierre sin comparaciones `tipo === '...'` en `app/`; quedan solo `TIPO_*` (claves de sistema) en el modelo, las migraciones y el seeder demo.
- Permisos: no se creó permiso nuevo. El CRUD del catálogo sigue bajo `superadmin.all`; `PATCH sla-settings` sigue siendo solo de Gerencia (test existente `test_lider_servicios_no_puede_ajustar_sla`). Sin riesgo de fuga por nivel compartido (lección de la Fase 2).
- Integridad: una entrada de sistema no se borra ni se desactiva; una entrada en uso por tickets tampoco, así que ningún ticket queda con un tipo inactivo (la regla `in:` de Update no puede rechazar un ticket existente).
- Migraciones: aditivas y reversibles (`down` borra las filas del dominio / quita el color). Los 4 ajustes `sla_dias_*` quedan sin lectores; no se borran.

## Hallazgo corregido en la revisión
- **`color` y `prefijo` aceptaban cualquier texto.** El color se pinta en un `style` del frontend (un valor como `url(...)` provocaría una petición externa) y el prefijo arma el folio. Ahora el esquema admite `pattern`: prefijo `^[A-Z0-9]{1,5}$`, color `^#[0-9a-fA-F]{6}$`. Tests nuevos para ambos.

## Sugerencias abiertas (no bloquean)
1. **Sin caché**: `CatalogService::prop()` consulta la BD en cada llamada (la spec §6 hablaba de caché por tenant). Es paridad con antes (cada SLA ya hacía una consulta a `servicios_settings`), pero `TicketTypes::label()/color()` dentro de bucles por ticket suman consultas. Una memo por petición lo resuelve; no se hizo para no arriesgar fugas entre tenants en los tests.
2. **Los subtipos conservan significado de sistema**: `installation` (venta previa → cotización e informe «No aplica»), `inspection` y `replacement_inspection` (plantillas del informe y reporte de cotizadas vs. realizadas). Un tipo nuevo con comportamiento `instalacion` solo hereda ese atajo si define un subtipo llamado `installation`. Conviene documentarlo en el manual del panel de Catálogos o volverlo una propiedad (`subtipo_sin_cotizacion`) en una fase posterior.
3. **Los subtipos nuevos no tienen traducción**: la etiqueta cae a la propia clave (`preventivo`). Es la limitación de «una etiqueta por entrada» ya anotada en la 7a.
4. **Plantillas del informe de inspección**: un tipo nuevo hereda la plantilla de su comportamiento; `generico` no tiene campos propios. Decisión de producto si se quieren plantillas por tipo.

## Verificación
- Backend: PHPStan L8 0 errores; suite completa 5114 tests, 0 fallos (antes de retirar las constantes y del endurecimiento del esquema); tras ambos, Servicios + Shared 422/422 y catálogos 23/23.
- Frontend: `tsc` limpio; 241 archivos / 2973 tests.
- Pendiente: verificación visual con Playwright CLI (requiere resetear la BD local de desarrollo).
