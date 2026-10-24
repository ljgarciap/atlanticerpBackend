# Spec: Auditoría de valores de negocio hardcodeados + vista consolidada de superadmin

**Date**: 2026-09-23
**Requested by**: Luis
**Status**: Approved — aprobada por Luis el 2026-09-23 (implementada; Senior Review y Pre-QA limpios, ver `docs/reviews/scrum944-945-*` y `docs/pre-qa/scrum939-945-*`)
**Project**: AtlanticERP (Illumination) — épica SCRUM-944, este ticket SCRUM-945 (fase de Analista)

## Problem

El proyecto respeta razonablemente bien la regla global ("Nunca hardcodear umbrales/márgenes/
porcentajes/divisores de negocio — todo va en tabla paramétrica con CRUD y vista de
administración") pero con dos gaps reales, relevados por Luis en la descripción de SCRUM-944:

1. No hay garantía de que TODO valor de negocio esté ya parametrizado — nadie audita el código
   contra la regla, solo se aplica caso a caso cuando alguien construye la feature.
2. Los valores que SÍ están parametrizados están repartidos en ≥8 pantallas de configuración
   distintas (Fiscal, Fraude, Compras, Pricing, Orders, Conditions, Reports, Bodega, Servicios)
   más el key/value genérico `GlobalSetting`, sin ninguna vista que los agregue — y con gating de
   permisos inconsistente (Fiscal es exclusiva de Mark, no de superadmin).

Este ticket (SCRUM-945) es la fase de Analista: clarificar el alcance real antes de que el
Arquitecto diseñe la solución. No autoriza implementar todavía.

## Solution summary

Dos entregables, ambos de la épica SCRUM-944:

1. **Auditoría real** (grep + revisión manual) de `app/Modules/**/Services`, `Controllers`, `Jobs`
   en busca de constantes numéricas que encajen en la definición acordada de "valor de negocio"
   (ver Alcance abajo). Cada hallazgo real se migra a la tabla paramétrica de su módulo (o a
   `GlobalSetting` si es genuinamente transversal), con su fila sembrada por seeder — nunca un
   default hardcodeado "razonable" en el código.
2. **Vista consolidada NUEVA, exclusiva de superadmin**, que agrega/expone en un solo lugar todos
   los valores de negocio parametrizados de todos los módulos — sin reemplazar, ocultar ni
   re-gatear ninguna de las 8 pantallas existentes. Mark conserva su acceso exclusivo a Fiscal tal
   como hoy; la vista nueva es adicional, no sustitutiva.

## Alcance de la auditoría (decidido por Luis, 2026-09-23)

**Definición usada — "estrecha"**: cuenta como valor de negocio a auditar toda constante numérica
que alimente un **cálculo o decisión de negocio** con impacto monetario o de aprobación: umbrales
de aprobación, porcentajes/márgenes, divisores de negocio, días de crédito/plazo, límites de
intentos que cortan un flujo de negocio (ej. `primary_approval_threshold`,
`comision_externa_default_percent`, `dias_credito_factura`, `petty_cash_max_intentos_rechazo`).

**Explícitamente NO cuenta** (no se audita, no se migra): timeouts HTTP/circuit breaker, TTLs de
cache, tamaños de paginación/buffer, epsilon de redondeo de punto flotante (ej. los `0.001`/
`0.005` ya encontrados en `PendingAlertsService`/`PaymentService` al samplear el código para esta
spec — son tolerancia técnica de comparación de floats, no negocio), tamaños de conexión DB,
configuración de infraestructura.

Sample rápido hecho para esta spec (no es la auditoría completa, solo verificación de que la
definición es aplicable): `AdminCont/Services/*` ya resuelve sus umbrales/porcentajes reales
(`primary_approval_threshold`, `comision_externa_default_percent`) vía
`AdminContFiscalSettings::current()`, consistente con el criterio de arriba — buena señal de que
el proyecto viene aplicando la regla bien, el gap real es cobertura completa + consolidación de
vista, no una violación masiva.

## Vista consolidada — decidido por Luis, 2026-09-23

**Nueva vista agregadora**, exclusiva de superadmin (permiso `superadmin.all`). Expone y permite
editar TODOS los valores de negocio parametrizados, agrupados por módulo, escribiendo siempre a la
MISMA tabla que ya usa la pantalla existente de ese módulo (nunca un storage paralelo/duplicado).
Ninguna de las 8 pantallas actuales se elimina, oculta ni cambia su gating — Mark sigue con acceso
exclusivo a Fiscal igual que hoy. `superadmin.all` ya bypassa toda restricción de permisos en el
modelo actual, así que esto no requiere ningún permiso nuevo.

## Users and roles

- **Superadmin** — único rol con acceso a la vista consolidada nueva. Puede ver y editar cualquier
  valor de negocio parametrizado de cualquier módulo desde un solo lugar.
- **Mark / otros roles con acceso a una pantalla de configuración específica** (ej. Fiscal) — sin
  cambios: siguen viendo/editando solo lo que ya ven hoy, por la pantalla que ya usan hoy.
- Cualquier otro rol → 403 en la vista consolidada, igual que hoy en cada pantalla existente.

## Acceptance criteria

**Auditoría:**
- [ ] `app/Modules/**/Services`, `Controllers`, `Jobs` revisados (grep + lectura manual) contra la
      definición de "valor de negocio" acordada arriba.
- [ ] Evidencia de la auditoría documentada (ej. `docs/architecture/auditoria-valores-parametricos.md`
      o similar) — por cada constante encontrada: archivo:línea, valor, decisión (migrada /
      excluida-por-técnica) y motivo de una línea.
- [ ] Cada hallazgo real (valor de negocio hardcodeado) migrado a su tabla paramétrica de módulo (o
      `GlobalSetting` si es transversal), con seeder — nunca un default hardcodeado dejado en el
      código.
- [ ] Grep final de verificación (mismo patrón usado al iniciar la auditoría) no encuentra
      constantes de negocio nuevas sin resolver en `app/Modules/**/Services|Controllers|Jobs`.

**Vista consolidada:**
- [ ] Ruta nueva, exclusiva de `superadmin.all`, lista TODOS los valores de negocio parametrizados
      de todos los módulos (Fiscal, Fraude, Compras, Pricing, Orders, Conditions, Reports, Bodega,
      Servicios + lo que la auditoría haya migrado), agrupados por módulo.
- [ ] Un usuario sin `superadmin.all` (incluido Mark con rol `management`) recibe 403 al acceder a
      la ruta/vista nueva.
- [ ] Editar un valor desde la vista consolidada escribe en la MISMA tabla que ya usa la pantalla
      existente de ese módulo — un cambio hecho desde la vista nueva se refleja de inmediato en la
      pantalla existente correspondiente, y viceversa (sin storage paralelo).
- [ ] Ninguna de las 8 pantallas existentes cambia su gating de permisos actual por este ticket
      (Fiscal sigue exclusiva de Mark, etc.).
- [ ] Cambios hechos desde la vista consolidada quedan capturados por `audit_logs` (SCRUM-788,
      trait `LogsAuditTrail` o `AuditService::log()` según corresponda) — mismo nivel de trazabilidad
      que ya tienen los cambios hechos desde cada pantalla existente.

## Edge cases and error scenarios

- Un usuario sin `superadmin.all` pero con varios `extra_permissions` individuales (ej. Mark) →
  403 en la vista nueva, igual que cualquier otro rol no-superadmin.
- Un valor genuinamente transversal a ≥2 módulos (no específico de uno solo) → va a
  `GlobalSetting`, no se duplica por módulo.
- Un valor hoy solo accesible vía `GlobalSetting`/`SettingsController` (endpoint bespoke, sin
  listado genérico) → debe aparecer igual en la vista consolidada aunque hoy no tenga pantalla
  propia.
- Tabla paramétrica de un módulo sin fila sembrada (no debería pasar dado el patrón `::current()`
  ya usado, pero si ocurre) → la vista debe mostrar el default seedeado, nunca una fila vacía o un
  error.
- Edición concurrente de un mismo valor desde la vista nueva y la pantalla existente al mismo
  tiempo → sigue la garantía de consistencia que YA tiene cada módulo hoy (esta spec no introduce
  locking nuevo).

## Out of scope

- La ejecución completa de la auditoría (grep + migración de hallazgos) no es parte de ESTE
  ticket — esta spec define el método y los criterios de aceptación; el PM la desglosa en tarea(s)
  de Backend Dev recién después de que el Arquitecto diseñe sobre esta spec aprobada.
- Cambiar el VALOR de negocio de nada encontrado — la auditoría migra almacenamiento (tabla +
  seeder), nunca cambia el número vigente, salvo que Luis señale explícitamente una discrepancia
  durante la revisión.
- Eliminar, fusionar o rediseñar las 8 pantallas de configuración existentes (decisión ya tomada
  arriba: se agregan, no se reemplazan).
- Permisos/roles nuevos — se usa `superadmin.all`, ya existente.
- SCRUM-815 (canal de backup inmutable para `audit_logs`) — ticket separado, sin relación.

## Open questions

- [Arquitecto] ¿La vista consolidada incluye también las claves NO-negocio de `GlobalSetting` (ej.
  `anthropic_api_key`, `documents.max_size_mb` — configuración operativa, no valor de negocio per
  la definición acordada) bajo una sección separada, o quedan fuera de esta vista por completo
  (siguen solo en su endpoint bespoke actual)? No bloquea el diseño — se resuelve dentro del
  diseño aprobado.
- [Arquitecto] Ruta/menú exacto de la vista nueva (ítem propio en `Sidebar.tsx` vs. sub-pestaña
  dentro de una pantalla de Seguridad ya existente) — decisión de UX dentro del diseño aprobado.
- [Backend Dev] Al migrar un hallazgo real a tabla paramétrica, ¿hace falta backfill por tenant
  (hoy solo `atlantic`) o alcanza el seeder estándar? Debería alcanzar con seeder dado el
  patrón ya usado (`ComprasSettings::current()`, etc.) — se confirma caso por caso solo si la
  auditoría encuentra algo atípico.

## References

- Épica: SCRUM-944 — descripción completa con el relevamiento de las 8 pantallas + `GlobalSetting`.
- Regla global: `CLAUDE.md` raíz del workspace, sección "Nunca" (hardcodear umbrales/márgenes/
  porcentajes/divisores de negocio).
- Pantallas relevadas (verificadas presentes al escribir esta spec, 2026-09-23):
  `atlanticerp-frontend/src/pages/admin-contab/FiscalConfigPage.tsx`,
  `atlanticerp-frontend/src/pages/admin-contab/FraudSettingsPage.tsx`,
  `atlanticerp-frontend/src/pages/compras/ComprasSettingsPage.tsx`,
  `atlanticerp-frontend/src/components/{Pricing,Orders,Conditions,Reports}SettingsPanel.tsx` /
  `ReportsConfigPanel.tsx`, `atlanticerp-frontend/src/pages/BodegaSettingsPage.tsx`,
  `atlanticerp-frontend/src/pages/servicios/ServiciosSettingsPage.tsx`.
- `GlobalSetting`: `app/Modules/Auth/Models/GlobalSetting.php`,
  `app/Modules/Auth/Http/Controllers/SettingsController.php`.
- Patrón de seeder/singleton ya usado: `ComprasSettings::current()`,
  `AdminContFiscalSettings::current()`.
