# Arquitectura — Módulo Servicios (Fase 4)

**Estado: APROBADO por Luis (2026-08-02).** Incluye el rename `Installations` → `Servicios`.
Este documento cubre el diseño estático (contexto, decisiones de arquitectura, schema,
eventos, permisos) — **el desglose de PM en batches (checklist vivo, se actualiza cada
sesión) vive aparte en `servicios-fase4-batches.md`** (separado 2026-08-15 — la sección 8
se re-leía en cada sesión para saber "qué batch sigue" pagando el costo de las 7 secciones
estáticas de arriba, que ya casi no cambian una vez implementadas; regla de cadencia
workspace-wide en `.claude/agents/pm.md`, ver
`memory/project_sesion_20260727_analisis_tiempos_calidad.md`).

## 0. Contexto

87 requerimientos (REQ-206→REQ-292, épica **SCRUM-330 "Servicios"**), especificados por Gerencia1
Gerencia1 desde el 16/07/2026 con criterios de aceptación reales, mockup (`5__Servicios_Home.html`) y
Excel (`5__Requerimientos_Servicios.xlsx`) adjuntos por ticket. Cubre: gestión de visitas técnicas
de instalación/garantía/reclamo/retrofit, cotización de servicio, informe de inspección
(desktop+móvil+firma), equipo técnico (interno/externo), herramientas e insumos, reportes.

**Reemplaza por completo** el modelo simplificado documentado en el `CLAUDE.md` actual (schema
`installations`: `cases`, `case_assignees`, `case_comments`, `case_photos`) — ese modelo nunca se
implementó (carpeta `App\Modules\Installations` solo tiene `.gitkeep`, sin código; 4 migraciones
del 2026-06-16 nunca corrieron sobre datos reales). No hay nada que migrar, es una reescritura
limpia del plan.

**Fuente de verdad por prioridad** (mismo criterio que Ventas & Diseño): ticket de Jira > Excel >
mockup HTML.

---

## 1. Decisión de nombres — renombrar `Installations` → `Servicios`

El catálogo de permisos ya tiene reservada la clave `servicios` desde SCRUM-144/145
(`App\Modules\Auth\Support\ModuleCatalog::SERVICIOS`), y la épica/todos los tickets de Jira dicen
"Servicios", no "Installations". Recomiendo:

- Namespace PHP: `App\Modules\Servicios` (no `Installations`)
- Schema Postgres: `{tenant}_servicios` (no `{tenant}_installations`)
- Borrar las 4 migraciones viejas de `installations_*` (sin uso real) y el folder vacío

Costo cero — nada se implementó todavía sobre el nombre viejo. Mantiene consistencia con
Ventas&Diseño/Compras/Bodega (namespace y schema en español, alineado al vocabulario de negocio) y
con la clave de permisos que ya existe.

---

## 2. Decisión de arquitectura clave — inventario compartido (REQ-291)

REQ-291 exige que Servicios, Bodega y Compras vean **el mismo número real** de disponible en todo
momento — no una copia eventualmente consistente. Revisé el módulo Bodega y **ya existe toda la
infraestructura necesaria**, construida para Compras/Bodega (SCRUM-244, SCRUM-451, SCRUM-467→472):

| Necesidad de REQ-291 | Ya existe en `App\Modules\Bodega\Models` |
|---|---|
| Tabla maestra por producto+ubicación | `ProductWarehouseStock` (`catalog_product_id`, `warehouse_id`, `ubicacion`, `quantity`) |
| Catálogo de ubicaciones físicas | `Warehouse` (catálogo fijo de 7 bodegas, sembrado por migración) |
| Kardex inmutable de movimientos (Entrada/Reubicación/Consumo) | `InventoryMovement` (`tipo`, `referencia_entidad_tipo`, `referencia_entidad_id` — **ya polimórfico**, pensado para que otros módulos referencien su propio origen) |
| Traslado con aprobación (Bodega Central → Reserva Servicios, REQ-277 RN2) | `RelocationRequest` (flujo Bodega solicita → Aprobador aprueba/rechaza, ya construido) |
| Escritura atómica del kardex | `KardexService::registrar()` — único punto de escritura, inmutable |

**Decisión: Servicios NO tiene tabla de inventario propia.** Se agrega:
1. Una 8va fila en el catálogo fijo de `Warehouse` — "Reserva Servicios" (requiere migración en
   `bodega`, coordinada con Backend Dev de Bodega, no es un cambio unilateral de Servicios sobre
   otro schema).
2. Servicios lee disponible/mínimo con una query cross-schema a `ProductWarehouseStock` filtrada
   por ese `warehouse_id` (mismo patrón que ya usa `ProductWarehouseStock::catalogProduct()`,
   que referencia `App\Modules\VentasDiseno\Models\CatalogProduct` cross-schema — **no es un
   patrón nuevo**, Bodega ya lo hace hoy contra VentasDiseno).
3. Al completar un Informe de Inspección con materiales (REQ-240), Servicios llama
   `KardexService::registrar(tipo: Salida, referencia_entidad_tipo: 'inspection_report',
   referencia_entidad_id: $informe->id, ...)` — no inserta directo en la tabla.
4. La reubicación Bodega Central → Reserva Servicios (REQ-277 RN1-2) reusa `RelocationRequest`
   tal cual existe, sin flujo paralelo.
5. Único elemento nuevo y propio de Servicios: `service_supply_thresholds(catalog_product_id,
   minimum_quantity)` — el "mínimo" configurable de REQ-273 RN1, que no existe en Bodega hoy.

Esto reduce drásticamente el riesgo del ticket más peligroso del batch (REQ-291 es un requerimiento
de arquitectura, no de pantalla) — no hay que inventar sincronización, hay que reusar lo que ya
funciona en producción.

---

## 3. Estructura de módulo

```
app/Modules/Servicios/
├── Models/       Ticket, TicketProduct, InspectionReport, InspectionReportMaterial,
│                 InspectionReportPhoto, ServiceQuote, ServiceQuoteItem, ClaimSheet,
│                 InternalTechnician, ExternalTechnician, ExternalTechnicianRateHistory,
│                 Tool, ToolMovement, ServiceSupplyThreshold,
│                 TicketFolioCounter, InspectionReportFolioCounter, ServiceQuoteFolioCounter
├── Http/Controllers/  Ticket, TicketProduct, InspectionReport, ServiceQuote, ClaimSheet,
│                       InternalTechnician, ExternalTechnician, Tool, Supply, Reports, Home
├── Http/Requests/     Store*Request / Update*Request por recurso
├── Services/           TicketService, TicketFolioService, InspectionReportService,
│                        ServiceQuoteService, ServiceQuoteFolioService, ClaimSheetService,
│                        TechnicianStatusService (cálculo en vivo, ver REQ-256 — no persistido),
│                        ServiceInventoryService (puente de solo-lectura+escritura hacia
│                        Bodega\Services\KardexService y Bodega\Models\ProductWarehouseStock)
└── Exceptions/          TicketCloseBlockedException (gate de REQ-218)
```

Frontend (`atlanticerp-frontend/src/`):
```
pages/servicios/   HomePage, TicketsPage (tabla+tablero), TicketDetailPage/Modal,
                    ServiceQuotePage, InspectionReportPage, InspectionReportMobilePage,
                    ClaimSheetPage, InternalTechniciansPage, ExternalTechniciansPage,
                    ToolsAndSuppliesPage, ReportsPage
components/         TicketTable, TicketBoard, TicketModal, ServiceQuoteItemRow,
                    InspectionReportFindingsForm (campos dinámicos por tipo/subtipo),
                    SignaturePad, TechnicianCard, ToolGroupRow, SupplyRow
api/serviciosApi.ts
i18n/locales/{es,en}/servicios.json
```

Schema PostgreSQL propio: **`{tenant}_servicios`**, agregado a `Tenant::schemas()`.

---

## 4. Modelo de datos (schema `servicios`)

```sql
tickets(
  id, numero,                    -- PREFIJO-AÑO-NNNN, prefijo por tipo: INS/GAR/REC/RET
  tipo,                          -- installation | warranty | claim | retrofit
  subtipo,                       -- solo installation y warranty tienen subtipo (ver REQ-279 RN4);
                                  -- installation: installation | inspection
                                  -- warranty: warranty_generic | replacement_inspection
  tipo_instalacion,              -- internal | subcontracted
  descripcion,
  sales_project_id,              -- FK cross-schema → ventas_diseno.sales_projects (búsqueda
                                  -- Cliente Master→Subcliente→Proyecto, REQ-246; OPCIONAL desde 2026-10-07 —
                                  -- decisión de negocio que revierte REQ-246 RN6 (antes obligatorio); mismo patrón
                                  -- cross-schema que ya usa Bodega contra CatalogProduct)
  cliente, contacto, telefono, email, direccion,   -- snapshot al crear (no editable desde acá)
  requerimientos_especiales,
  estado,                        -- reported | scheduled | on_site | resolved | closed | cancelled
  internal_technician_id,        -- FK nullable → internal_technicians ("Sin asignar")
  scheduled_at, scheduled_ends_at,  -- fin estimado, usado por TechnicianStatusService (REQ-256)
  created_by, created_at, updated_at
)

ticket_products(                 -- productos reclamados/afectados (TICKET_PRODUCTO)
  id, ticket_id,
  catalog_product_id,            -- FK cross-schema → ventas_diseno.catalog_products, nullable
  marca, referencia, descripcion,
  cantidad_reclamo, cantidad_recibida, cantidad_pendiente
)

inspection_reports(
  id, numero,                    -- INF-AÑO-NNNN
  ticket_id,                     -- 1:1 con el ticket (UNIQUE)
  modo,                          -- formulario | archivo_subido (REQ-243)
  fecha_inspeccion, hora_inicio, hora_fin,   -- hora_fin nullable (RN de REQ-238 solo exige selector real, mockup captura ambas)
  internal_technician_id,
  findings,                      -- JSONB array [{label, value, ticket_product_id, is_additional,
                                  -- is_quote_recommendation}] — reconciliado 2026-08-10 contra el
                                  -- Modelo de Datos del Excel (tabla EAV INFORME_HALLAZGO) y el
                                  -- mockup real (saveInforme() arma exactamente este array). Se
                                  -- descarta tanto la tabla EAV separada como un JSONB de forma
                                  -- fija por tipo (lo que decía esta sección antes) — un array de
                                  -- {label,value} es el shape real que el cliente ya validó.
                                  -- is_quote_recommendation lo asigna InspectionReportService según
                                  -- el catálogo de campos por tipo/subtipo, NUNCA se infiere del
                                  -- label — resuelve REQ-239 RN5 (nombres de campo distintos entre
                                  -- tipos) sin matchear strings.
  conclusion,                    -- obligatorio para completar (REQ-241 RN2)
  requiere_seguimiento, proximos_pasos,   -- proximos_pasos NULL si requiere_seguimiento=false
  firma_tecnico_nombre, firma_cliente_nombre,   -- texto, nulo = "Pendiente de firma" (REQ-242 RN3)
  firma_cliente_imagen,          -- storage_key, escrito solo desde el Informe móvil (Batch 10) —
                                  -- mismo registro, nunca un informe paralelo (REQ-242 RN2)
  archivo_adjunto,               -- storage_key, solo si modo=archivo_subido (REQ-243)
  estado,                        -- pending | completed
  created_at
)
-- Diverge del snippet original de esta sección (id, fecha_hora_inspeccion único, findings de forma
-- fija, signature_path único) — reconciliado 2026-08-10 al arrancar Batch 8 contra
-- 5__Requerimientos_Servicios.xlsx (hoja "Modelo de Datos") + el mockup real, ver
-- memory/project_sesion_20260810_batch8_informe_inspeccion.md.

inspection_report_materials(     -- REQ-240 RN1/RN2 — texto libre, SIN FK a catálogo (a propósito,
  id, inspection_report_id,      -- ver nota de alcance abajo)
  nombre_material, cantidad
)
-- REQ-240 RN3 (disparar Consumo real en el kardex de Bodega al guardar) y RN4 (alerta de
-- sobre-consumo) quedan DIFERIDOS a Batch 14 — decisión explícita de Luis 2026-08-10. RN3 depende
-- de la bodega "Reserva Servicios" (punto abierto #2, bloquea Batch 14) que no existe todavía, y
-- además es incompatible tal cual está escrito con RN1 (texto libre no resuelve a
-- catalog_product_id, que es lo que KardexService::registrar() necesita para descontar stock
-- real) — contradicción real del requerimiento, no solo de secuenciación. Batch 8 implementa
-- solo el registro informativo (RN1/RN2); cuando Batch 14 resuelva la bodega, ahí se decide cómo
-- resolver el material de texto libre a un producto real del catálogo (o si se reemplaza RN1 por
-- un buscador, a confirmar con Gerencia1/cliente en ese momento).

inspection_report_photos(id, inspection_report_id, categoria, storage_key, uploaded_by, created_at)
-- categoria: antes | despues (REQ-241 RN1 — dos categorías separadas, no un solo listado)

service_quotes(
  id, numero,                    -- COT-SERV-AÑO-NNNN
  ticket_id, estado,             -- draft | sent | approved | rejected
  subtotal, discount_percent, itbms, total,   -- ITBMS 7%, ver REQ-233 (visible igual en
                                                -- formulario y documento, no solo al final)
  observations, conditions,      -- condiciones configurables por Gerencia (REQ-237)
  created_by, sent_at, decided_at, created_at
)

service_quote_items(
  id, service_quote_id,
  tipo,                          -- product | labor | subcontracted
  catalog_product_id,            -- FK cross-schema, solo si tipo=product
  external_technician_id,        -- FK, solo si tipo=subcontracted
  description, quantity, unit_price, subtotal
)

claim_sheets(                    -- Hoja de Reclamo, solo tickets tipo=claim (REQ-278)
  id, ticket_id,
  complainant_snapshot,          -- JSONB — sección 1, precargado del ticket
  product_snapshot,              -- JSONB — sección 2, precargado de ticket_products
  problem_description, claim_date,
  diagnosis,                     -- defective | not_applicable | misuse | pending_evaluation
  signature_path,
  completed_at,                  -- NULL = editable; NOT NULL = solo lectura (RN5)
  created_by
)

internal_technicians(
  id, user_id,                   -- FK NULLABLE → auth.users. Diverge del diseño original
                                  -- (NOT NULL): REQ-259 (Batch 6) no exige correo único ni pasa
                                  -- por la guardia jerárquica de POST /api/users, así que un
                                  -- perfil de técnico interno no es necesariamente una cuenta de
                                  -- login. Sin user_id, el perfil nunca es seleccionable para
                                  -- agendar (Batch 2, REQ-226 sigue leyendo users por role_id).
  nombre, telefono, email,       -- snapshot propio (independiente de `users`, ver arriba)
  specialty,                     -- general | warranty
  color, working_hours,          -- para el cálculo de estado (REQ-256), nunca editable a mano
  created_at, updated_at
)
-- Estado (Disponible/En ruta/Ocupado/Fuera) NUNCA se persiste — se calcula en cada request
-- desde tickets.scheduled_at/scheduled_ends_at + hora actual en America/Panama (working_hours es
-- hora local de negocio) vía TechnicianStatusService (REQ-256 RN5). Los 4 técnicos reales
-- existentes (Tecnico Servicios Test, Tecnico Servicios Test, [persona] [persona], Garantias Servicios Test) están linkeados
-- vía user_id por InternalTechnicianSeeder.

external_technicians(
  id, nombre, empresa, especialidad, telefono,
  tarifa_dia, tarifa_visible, estado,   -- active | inactive
  created_at
)

external_technician_rate_history(id, external_technician_id, tarifa_dia, changed_by, created_at)

tools(                                   -- Grupo D parte 1 (REQ-268→272, SCRUM-338→342),
  id, nombre, codigo_unico, estado,      -- CERRADO 2026-08-12. Catálogo real de estado (RN de
                                          -- REQ-269, resuelve el punto abierto #3 de abajo):
                                          -- buen_estado | dañada | desgaste | perdida.
  assigned_to_technician_id,             -- FK nullable → internal_technicians; null = "En bodega
                                          -- de herramientas" (REQ-270).
  assigned_since,
  responsable_incidente,                 -- snapshot string, no FK — auto-completado server-side al
                                          -- marcar Dañada/Perdida (RN1), limpiado en Desgaste/Buen
                                          -- estado (RN2/RN4) — el frontend nunca lo escribe.
  created_at, updated_at
)

tool_movements(id, tool_id, tipo, detalle, user_id, created_at)
-- Kardex de herramientas (REQ-269 RN3, REQ-271/272 RN4), independiente del kardex de Bodega —
-- herramientas no son parte del inventario compartido de REQ-291 (eso aplica solo a insumos de
-- consumo). tipo: ingreso | dañada | perdida | desgaste — reasignar (REQ-270) NUNCA genera
-- movimiento, es un traspaso, no un ingreso/baja.

tool_purchase_requests(id, nombre, cantidad, estado, source_tool_id, requested_by, created_at, received_at)
-- REQ-271 (alta nueva, source_tool_id null) / REQ-272 (reposición, source_tool_id = la unidad que
-- disparó el pedido — `nombre` siempre derivado server-side de esa unidad, nunca del cliente).
-- estado: solicitado | recibida. Al marcar "Recibida" se generan N `tools` con código nuevo
-- (nunca reutiliza el código de `source_tool_id`) vía ToolCodeService (mismo patrón atómico que
-- TicketFolioService/InspectionReportFolioService).

tool_code_counters(last_number)          -- fila única, consecutivo de COD-HER-NNN

service_supply_thresholds(catalog_product_id, minimum_quantity)
-- único dato de inventario propio de Servicios — el resto vive en bodega.product_warehouse_stock

ticket_folio_counters(prefijo, year, last_number)         -- PK compuesta (prefijo, year)
inspection_report_folio_counters(year, last_number)        -- PK year
service_quote_folio_counters(year, last_number)             -- PK year
```

Numeración: mismo patrón ya establecido (`QuoteFolioCounter`/`OrderFolioCounter`/
`ZonaLibreFolioCounter` + `*FolioService::next()` con lock atómico) — `TicketFolioCounter` es el
único con PK compuesta porque el consecutivo es por prefijo+año, no solo por año (REQ-249 RN1).
El año debe tomarse de la fecha real del servidor, nunca hardcodeado (gotcha ya señalado en el
propio ticket — en el mockup entregado estaba fijo en "2026").

---

## 5. Eventos de dominio

```
servicios.ticket.created
servicios.ticket.stage-changed        -- incluye el gate de REQ-218 (cotización/informe antes de Cerrado)
servicios.ticket.scheduled
servicios.ticket.cancelled
servicios.inspection-report.completed -- listener dispara KardexService::registrar() por cada material
servicios.quote.sent
servicios.quote.decided               -- aprobada | rechazada
servicios.claim-sheet.completed
servicios.tool.status-changed
```

---

## 6. Permisos y roles

`ModuleCatalog::SERVICIOS` (`'servicios'`) ya existe en el catálogo de 7 módulos desde SCRUM-144 —
no hace falta tocar el catálogo de permisos en sí, solo sembrar filas de
`RoleModuleVisibility`/`SecurityLevelModulePermission` para `module='servicios'` por rol.

**Resuelto** — el roster real (`memory/project_roster_usuarios_reales_atlanticerp.md`, consolidación
2026-07-30) ya tiene estas personas/roles:
- **Aaron / Líder de Servicios** = Aaron Leis (`servicio@atlantic.com.pa`), rol
  `lider_servicios`, Nivel 4 — coincide exacto.
- **Técnico interno (general)** = Tecnico Servicios Test, Tecnico Servicios Test, [persona] [persona], rol
  `tecnico_servicios`, hoy Nivel 1 (Solo lectura).
- **Técnico interno (Garantías)** = Garantias Servicios Test (`garantias@atlantic.com.pa`), rol
  `garantias_servicios` — coincide con la distinción "General vs Garantías" de REQ-256 RN2; Miguel
  [persona] es literalmente el técnico usado como ejemplo en varios criterios de aceptación (REQ-238).
- **Gerencia** = rol `management` ya existente (Gerencia1, Gerencia2, Aprobador).
- **Vendedor/Diseñador** = rol ya existente (10 personas) — solo necesita alcance de solo-lectura
  agregado sobre `servicios` (ver REQ-279 RN6, REQ-308 RN4).

**Único pendiente real de esta sección**: `tecnico_servicios`/`garantias_servicios` son Nivel 1
("Read Only") hoy porque Servicios no existía funcionalmente — para que puedan generar/editar
Informe y Cotización de SU ticket asignado (REQ-238 RN5, REQ-292 RN7) hace falta una fila de
`SecurityLevelModulePermission` con `can_edit=true` para `module='servicios'` en esos 2 niveles,
sin tocar su nivel en el resto de módulos (el modelo ya soporta esto por módulo, no hace falta
subir de nivel globalmente). Tarea de seeding en Batch 1 (Backend Dev), no decisión de negocio.

---

## 7. Puntos abiertos (no bloquean el desglose, sí bloquean batches puntuales — ver sección 8)

1. ~~Mapeo de roles/personas~~ — **resuelto**, ver sección 6.
2. **Nueva bodega "Reserva Servicios"** en el catálogo fijo de `Warehouse` — coordinar con quien
   tenga contexto de Bodega (posible Backend Dev que construyó SCRUM-451), no es unilateral de
   Servicios. Bloquea Batch 14.
3. ~~Catálogo exacto de estados de herramienta~~ — **resuelto** 2026-08-12 al implementar Grupo D
   parte 1 (REQ-268→272): el texto real de REQ-269 (Jira, Gerencia1) da los 4 estados completos —
   **buen_estado | dañada | desgaste | perdida** — ver `tools.estado` arriba.
4. **Reportes (REQ-280→286)** — para el MVP los trato como queries agregadas sobre las tablas de
   arriba, sin tabla propia, salvo "Biblioteca de reportes" (REQ-286) que puede necesitar una tabla
   de definiciones si los reportes son configurables — confirmar alcance real con PM al desglosar.
   Bloquea Batch 18.
5. ~~Confirmar con Luis el rename~~ — **aprobado**, ver estado del documento arriba.

