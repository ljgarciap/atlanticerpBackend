# Fase 14f — Retiro de Zona Libre

**Estado**: spec con las decisiones de §5 tomadas por Luis (2026-10-30); pendiente de su «adelante con 14f» y de la confirmación explícita del `DROP`. Decisión de fondo ya tomada por Luis (2026-10-30): **retirar Zona Libre** (es un concepto de Panamá, ajeno a un ERP genérico). Contiene una migración **irreversible**: no se implementa sin su «adelante» y la confirmación explícita del `DROP`.

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
