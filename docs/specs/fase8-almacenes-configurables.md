# Spec — Fase 8: almacenes configurables (retira la excepción «almacenes configurables» del contrato de neutralidad)

**Estado**: decisiones de Luis registradas (§6, 2026-10-27); diseño de 8b detallado abajo — pendiente de su «adelante» para implementar.
**Origen**: excepciones temporales con dueño «almacenes configurables» en `tests/Support/TerminosDelClienteAnterior::PERMITIDOS` (`showroom`, `obarrio`); spec 4b §6.

## 1. Hechos verificados (medidos hoy en `master`)
- **La lógica de venta ya NO depende de nombres**: reserva, picking, despacho y «Disponible» se rigen por banderas de `warehouses` (`permite_venta`, `excluye_disponible`, `despacho_por_defecto`, `modo_detalle`), editables por superadmin
  (`WarehouseSaleRulesController`, panel `WarehouseSaleRulesPanel`). Esto simplifica el trabajo: no hay que reescribir las reglas de ventas.
- **Los almacenes los crean las migraciones**, iguales en todo tenant: 9 filas con nombre fijo (`Bodega Central`, `Bodega Zona Libre`, `Showroom Obarrio`, `Showroom SM`, `Showroom Cliente`, `Merma`,
  `Reclamos y Devoluciones`, `Reserva Servicios`…). No existe alta, renombre ni baja de almacenes en la aplicación; solo se editan las banderas de venta.
- **Dependencias de nombre que sí quedan en el código**:
  1. `InsumoService`: busca por nombre `'Reserva Servicios'` y `'Bodega Central'` (2 consultas).
  2. `AdminContLocationController`: decide el tipo `showroom`/`bodega` con `str_starts_with($w->name, 'Showroom')`.
  3. El dato «en showroom»: método `enShowroomMap()`, campo de API `stock_showroom` (3 controladores/servicios, 3 tipos TS), y textos i18n «Showroom Obarrio: N» (es/en, 5 claves) en Compras, Ventas y Catálogo.
  4. Comentarios/docblocks y 2 migraciones históricas que citan «Showroom Obarrio» (las migraciones no se editan).
- Tests: 42 archivos del backend (~420 menciones de nombres de almacén) y 33 del frontend (src + e2e) citan estos nombres; la mayoría son fixtures por nombre.

## 2. Diseño propuesto
**8a (núcleo, cierra el contrato)**
1. **Migración de renombre** (idempotente, ids y stock intactos): `Showroom Obarrio`, `Showroom SM`, `Showroom Cliente` pasan a nombres neutros (§6-1). `down()` restaura los nombres.
2. **Columna `funcion`** (nullable, única cuando tiene valor) en `warehouses`: hoy un solo valor, `reserva_servicios`. `InsumoService` deja de buscar por nombre: reserva = `funcion = 'reserva_servicios'`, central = `despacho_por_defecto` (ya
   existe y es exactamente una, de venta). Backfill por nombre dentro de la migración.
3. **Columna `tipo`** (`bodega`|`exhibicion`, default `bodega`) reemplaza el `str_starts_with('Showroom')` de Admin&Cont. Backfill: las tres ex-«Showroom» pasan a `exhibicion`.
4. **Dato «aparte»**: `enShowroomMap` → `enOtrasBodegasDeVentaMap`; campo `stock_showroom` → `stock_aparte`; i18n con texto genérico (§6-3). Cambio de contrato backend/frontend: se despliegan juntos.
5. **Demo**: `ProductWarehouseStockBackfillSeeder` y fixtures usan los nombres nuevos.
6. **Contrato**: se quitan de `PERMITIDOS` `showroom` y `obarrio`; se prohíben los nombres viejos.

**8b (entra en esta fase, decisión de Luis)**: administración de almacenes desde la aplicación, para que un cliente tenga SUS almacenes sin migración.
1. **Endpoints** sobre `WarehouseSaleRulesController` (mismo gate `superadmin.all`; sin permiso nuevo → sin riesgo de fuga por nivel compartido): `POST /bodega/admin/warehouses` (nombre, tipo, `modo_detalle`, banderas de venta),
   `PUT` (hoy solo banderas; suma nombre y tipo) y `DELETE`. Nombre único, 2–60 caracteres.
2. **Baja protegida**: no se borra un almacén con `funcion` (reserva de servicios), con `despacho_por_defecto`, con stock distinto de cero, ni referenciado por recepciones, pedidos, asignaciones, mapeos de asistente o ubicaciones. En su lugar se **desactiva**
   (`is_active = false`, columna nueva): desaparece de los selectores y de las reglas de venta, conserva su historial.
3. **`funcion`** no es editable desde la UI en 8b (solo los de sistema la llevan); `despacho_por_defecto` se cambia como hoy (siempre exactamente uno, de venta, activo).
4. **Auditoría**: el modelo ya usa `LogsAuditTrail`; alta/renombre/baja quedan en el audit log.
5. **Frontend**: el panel de reglas de venta pasa a «Almacenes» (lista + alta/edición/desactivar) con textos vía i18n.
6. **Tests**: matriz de la baja (cada referencia la bloquea), unicidad, desactivado fuera de selectores, y el test de 8a que renombra todos.

## 3. Fuera de alcance
Catálogo de productos genérico (fase aparte), `Bodega Zona Libre` (concepto del flujo de solicitudes de la Fase 5), permisos del módulo Bodega, ubicaciones internas (`warehouse_locations`).

## 4. Criterios de aceptación
1. Ningún archivo versionado fuera de migraciones históricas y `docs/` contiene `showroom` ni `obarrio` (contrato sin esos permitidos).
2. Renombrar un almacén desde la base (o 8b) no rompe Insumos, picking, Admin&Cont ni el catálogo (test que renombra todos y recorre los flujos).
3. En un entorno ya desplegado, la migración conserva ids, stock, banderas y pedidos; `down()` la revierte.
4. PHPStan L8, suite completa del backend y del frontend en verde; Playwright CLI: Catálogo, Inventario, hoja de picking y Ubicaciones sin el vocabulario viejo.

## 5. Riesgos
- 8b suma una columna `is_active`: cada consulta que lista almacenes debe filtrarla (grep de cierre sin exclusiones, lección de la 7c).
- Renombre mecánico de ~75 archivos de test/e2e (mismo método de las Fases 3, 4b y 4c).
- `stock_showroom` → `stock_aparte` rompe a un cliente que consuma la API con el nombre viejo: no hay consumidores externos conocidos; backend y frontend se mezclan en orden (backend primero) como siempre.
- Un entorno con datos reales debe respaldarse antes de migrar (regla del workspace para cambios de BD).

## 6. Decisiones de Luis (2026-10-27)
1. Nombres: **Sala de exhibición 1 / Sala de exhibición 2 / Exhibición en cliente** (ex Obarrio / SM / Cliente). ✅
2. **8a y 8b juntas** en esta fase. ✅
3. Texto del dato «aparte»: **«En exhibición (aparte): N»**; campo de API `stock_aparte`. ✅
4. Entrega propuesta (a confirmar con el «adelante»): 2 PR por repo apilados — PR 1 = 8a, PR 2 = 8b — como en la Fase 7.
