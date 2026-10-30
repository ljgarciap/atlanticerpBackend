# Fase 14f — Retiro de Zona Libre

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); «adelante con 14f» y confirmación explícita del borrado dadas el mismo día. **14f-1 (código) y 14f-2 (migración) implementadas** (§8-§9), pendientes de mezclar. Decisión de fondo ya tomada por Luis (2026-10-30): **retirar Zona Libre** (es un concepto de Panamá, ajeno a un ERP genérico). Contiene una migración **irreversible**: no se implementa sin su «adelante» y la confirmación explícita del `DROP`.

## 1. Objetivo
Quitar del núcleo todo lo que nombra o depende de «Zona Libre» (la zona franca de Colón, Panamá), como se hizo con Legacy/Lafayette en la Fase 5, sin romper el flujo de compras ni el de liquidación aduanal que hoy cuelga de él.

## 2. Hallazgos (medidos sobre `master`)
Zona Libre aparece en cuatro capas distintas:
1. **Flujo de solicitudes (Bodega → Compras)**: tablas `compras.bodega_zona_libre_requests`, `…_request_lines` y `…_folio_counters`; modelos `BodegaZonaLibreRequest(+Line)` y `ZonaLibreFolioCounter`; `ZonaLibreFolioService`; controladores `BodegaZonaLibreRequestController` y `ComprasZonaLibreRequestController`; rutas en `bodega.php`/`compras.php`; ajuste `compras_settings.zona_libre_provider_id` + `ZonaLibreProviderSeeder`; permiso `compras.zona-libre.approve`; aprobación en `AprobacionGate`, `ApprovalsPendingService` y Gerencia (`GerenciaAprobacionController`, `GerenciaHomeService`).
2. **Modalidad de la orden**: `purchase_orders.modality` = `directo` | `zona_libre` (se elige en «Nueva orden», `StorePurchaseOrderRequest`). De ella dependen el filtro de órdenes, un reporte de Compras (`ComprasReportsController`), la marca `is_zona_libre` del inicio de Bodega y **`PATCH orders/{id}/liquidate`, que solo acepta órdenes de modalidad Zona Libre**.
3. **Agencias de liquidación** (`LiquidationAgency`, tabla `compras.liquidation_agencies`, `OrderLiquidationAssigned`, `LiquidationAgencyController`): se crearon «para órdenes Zona Libre» (SCRUM-210).
4. **Vocabulario y restos**: categoría de proveedor `zona_libre` (`Provider::CATEGORY_ZONA_LIBRE`, entrada sembrada del catálogo `provider_category`), comentarios y reglas de «bodega de solo consulta» en Bodega, textos de notificaciones y del limpiador de datos de prueba, el modal `ZonaLibreWarehousesModal` y las páginas «Nueva orden Zona Libre» / «Órdenes Zona Libre» del frontend.
Tamaño: 69 archivos en el backend (app/rutas/BD/config), 22 de tests y 46 del frontend (más e2e y 4 migraciones históricas que no se tocan).

## 3. Diseño propuesto
- **Backend**: borrar controladores, modelos, servicio, rutas, seeder y permiso de las solicitudes; quitar de `AprobacionGate`/`ApprovalsPendingService`/Gerencia las aprobaciones de Zona Libre; quitar `zona_libre_provider_id` de Ajustes de Compras; quitar el valor `zona_libre` de `modality` (validación, filtro, reporte, marca de Bodega); generalizar las agencias de liquidación (decisión 2): `liquidate` y la agencia dejan de depender de la modalidad y valen para toda orden de un proveedor internacional (`Provider::deriveOrigin()`/propiedad `origen` del catálogo); las órdenes locales siguen respondiendo 422.
- **Migración única e irreversible** (`down()` lanza error, como la de la Fase 5b): borra las 3 tablas, la columna de ajustes y el permiso (de `permission_catalog` y de los niveles de seguridad); convierte las órdenes `zona_libre` existentes a `directo` (decisión 1) y las proveedoras con categoría `zona_libre` a `internacional` (decisión 3). **Guardas**: aborta si hay solicitudes con órdenes aún no recibidas o, fuera de local/dev/testing, si hay datos de solicitudes. **Respaldo obligatorio antes de migrar un entorno con datos.**
- **Frontend**: borrar las 2 páginas, el modal, sus rutas, el menú, el hook/API, textos i18n (es/en) y los tests/e2e asociados; quitar el selector de modalidad de «Nueva orden».
- **Contrato**: añadir `zona libre`/`zona_libre` a la lista prohibida de neutralidad (backend y frontend), con las 4 migraciones históricas como únicas excepciones por ruta.
- **Entrega**: 2 PR por repo apilados (5a código, 5b migración), como la Fase 5b; Senior Review y suite completa antes de cada uno.

## 4. Criterios de aceptación
1. No queda código, ruta, permiso, texto ni dato sembrado que nombre Zona Libre fuera de las migraciones históricas y esta spec; el contrato de neutralidad lo verifica en ambos repos.
2. «Nueva orden», pagos, recepción, cambio de monto, reclamos y sustituciones se comportan igual (flujos `e2e:flujos` de Compras y Bodega en verde).
3. Las órdenes existentes con modalidad Zona Libre siguen visibles y operables tras la migración y conservan su agencia de liquidación (decisión 1); una orden internacional cualquiera puede asignar agencia y liquidarse.
4. Un nivel de seguridad que tenía `compras.zona-libre.approve` no queda con un permiso huérfano; ningún rol gana permisos nuevos.
5. La migración aborta sin borrar nada si encuentra datos fuera de lo permitido; PHPStan nivel 8, suites completas y `tsc` en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Órdenes existentes con modalidad Zona Libre**: pasan a `directo` (se conservan). ✅
2. **Agencias de liquidación / `liquidate`**: se **generalizan** (cualquier orden de un proveedor internacional puede asignar una agencia aduanal). ✅
3. **Categoría de proveedor `zona_libre`**: se **elimina**; los proveedores que la tengan pasan a `internacional`. ✅
4. **Solicitudes abiertas al migrar**: hay que **cerrarlas antes** (la migración aborta sin borrar nada si queda alguna sin recibir o rechazar). ✅
5. **Entrega**: 2 PR por repo apilados (código, luego migración), con Senior Review y suite completa antes de cada uno — propuesta, no se preguntó.

## 6. Riesgos
- Borrado irreversible de historial de solicitudes: mitigado con la guarda y el respaldo previo.
- Generalizar las agencias cambia quién puede liquidar: hoy solo las órdenes Zona Libre; después, toda orden internacional. Con los permisos actuales de `liquidate` no se otorga nada nuevo a ningún rol.
- Lo ya registrado en auditoría (`audit_logs`) con nombres de Zona Libre queda como historial; no se reescribe.

## 7. No-alcance
Reglas de aprobación de órdenes (Fase 14e: Zona Libre queda fuera de ella por esta fase), aplanado de migraciones históricas y el catálogo de proveedores fuera de la categoría `zona_libre`.

## 8. Resultado de 14f-1 (código)
- **Backend**: borrados los controladores, modelos, servicio de folio, seeder y tests del flujo de solicitudes; fuera sus rutas, el ajuste `zona_libre_provider_id` (API y modelo), las aprobaciones de Gerencia, el aviso de pendientes, el origen de notificación y el valor `zona_libre` de la modalidad de la orden (el campo queda en `directo`). `liquidate`, las agencias y el reporte de importaciones pasan a depender del origen del proveedor (`internacional`); una orden de proveedor local responde 422. Sin chip ni marca `is_zona_libre`.
- **Frontend**: fuera las dos páginas, el modal de bodegas, las rutas, el menú, el hook/API y los textos (es/en); el detalle de orden muestra la agencia para proveedores internacionales; «Nueva orden» ya no pide modalidad; el panel de Ajustes de Compras ya no pide el proveedor fijo.
- **Se conserva a propósito hasta 14f-2** (la migración): las tablas, la columna `zona_libre_provider_id`, el permiso `compras.zona-libre.approve`, la categoría `zona_libre` (constante y entrada del catálogo) y el contrato de neutralidad. Mientras tanto no hay forma de crear datos nuevos de Zona Libre desde la aplicación.
- **Verificación**: backend 5214 tests y PHPStan 0; frontend 247 archivos / 2969 tests y `tsc` limpio.

## 9. Resultado de 14f-2 (migración y contrato)
- **Migración `2026_11_06_100000_retire_zona_libre` — IRREVERSIBLE** (`down()` lanza error): borra las 3 tablas de solicitudes, `settings.zona_libre_provider_id`, el permiso `compras.zona-libre.approve` (catálogo, `security_levels.permissions` y `users.extra_permissions`) y las reglas de aprobación de Gerencia del tipo `zona_libre`; convierte las órdenes `zona_libre` a `directo`; la categoría de proveedor `zona_libre` pasa a una entrada nueva `internacional` (mismo origen internacional; se crea si no existe) y la entrada vieja se borra.
- **Guardas**: aborta en cualquier entorno si queda una solicitud `pendiente`; fuera de local/dev/testing aborta si las tablas de solicitudes tienen filas. **Respaldo obligatorio antes de migrar un entorno con datos.**
- **No toca `bodega.read`** del Líder de Compras: se le concedió por esta pantalla, pero hoy le da el resto de Bodega; quitarla sería un cambio de acceso aparte (decisión pendiente de Luis).
- **Contrato de neutralidad**: `zona.?libre` pasa a la lista prohibida en ambos repos (migraciones históricas y el test de esta migración quedan exentos). `Provider::CATEGORIES` cambia `zona_libre` por `internacional`; catálogo de permisos 55 → 54 (`compras` 5 → 4).
- **Verificación**: `RetiroZonaLibreMigracionTest` (aborta con una pendiente sin borrar nada; retira tablas, permiso, categoría y reglas y convierte las órdenes; es irreversible), backend 5217 tests y PHPStan 0.
