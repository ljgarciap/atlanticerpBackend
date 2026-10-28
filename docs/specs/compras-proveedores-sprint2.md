# Compras/Proveedores — sprint2

Spec + diseño técnico conjunto (Analista + Arquitecto). Rama `sprint2` en `atlanticerp-backend` y
`atlanticerp-frontend`, ya rebasada sobre `dev` y pusheada a `origin/sprint2` en ambos repos
(autorizado por Luis 2026-07-16). **Este documento fue reescrito el 2026-07-16 para reflejar el
alcance REAL entregado — ver "Historial de cambios de alcance" al final.**

## Alcance real entregado (actualizado 2026-07-16)

El sprint terminó cubriendo **33 tickets** (los 33 que Jira tiene hoy en estado "In Progress" del
sprint "SCRUM Sprint 2 - Compras", id 6), no los 14 originales del daily 2026-07-14:

- **Proveedores (2G)** — SCRUM-183→190 (REQ-120→127), 8 tickets.
- **Nueva Orden (2H)** — SCRUM-191→199 (REQ-128→136), 9 tickets (incluye PDF/email, REQ-136).
- **Ver Órdenes (2A)** — SCRUM-201→209, 212 (REQ-138→146, 149), 10 tickets.
- **Logística (2B)** — SCRUM-214→219 (REQ-151→156), 6 tickets.

Total: 8 + 9 + 10 + 6 = 33. Verificado 1:1 contra `jira_get_sprint_issues(sprintId=6, jql=status
= "In Progress")` el 2026-07-16 — coincide exactamente con esos 33 keys, sin huecos ni sobrantes.

**Ya NO está "diferido a backlog"**: Ver Órdenes (2A) y Logística (2B) — están construidos e
implementados (ver detalle de cada pantalla más abajo, sin cambios respecto al diseño técnico
original salvo lo indicado en "Hallazgos de reconciliación 2026-07-16").

**Sigue diferido a backlog** (61 tickets restantes del sprint de Jira, todos en estado "To Do",
alcance sin cambios): SCRUM-175→182 (REQ-111→118, dashboard "Inicio" de Compras), Inventario (2C,
distinto del Catálogo — ver hallazgo abajo, la extensión de `catalog_products` SÍ se hizo pero la
pantalla "Inventario" dedicada no), Garantías & Reclamos (2E), Reportes (2F), Ingreso de Mercancía
(2J), Pagos, Liquidación Zona Libre. Construir estas pantallas ahora significaría paneles/flujos
contra datos que varias de las tablas de este sprint todavía no producen de punta a punta (ver
riesgos aceptados abajo).

**Nota para cuando se retome Inicio:** Luis ya definió que REQ-113 ("Mi calendario") debe llevar
integración **real con Outlook/Microsoft Graph**, no el patrón de calendario nativo CRUD que se
usó en Ventas & Diseño. Confirmado: no existe ninguna infraestructura de Microsoft Graph en el
proyecto hoy — es desarrollo 100% nuevo cuando se retome.

## Hallazgos de dependencias (Analista + exploración de código)

1. **"Inventario (2C)" de los tickets de Compras no es un módulo nuevo — es el `catalog_products`
   que ya existe en `App\Modules\VentasDiseno`** (`{tenant}_ventas_diseno.catalog_products`,
   creado en SCRUM-129/131 con `stock_quantity`). Le faltan: proveedor asociado, punto de
   reorden, y una referencia de fábrica separada de la pública (hoy `reference` es única
   globalmente y hace de "referencia" genérica). **Decisión de Luis: extender la tabla
   existente**, no duplicarla — ver diseño abajo.
2. **REQ-124 (calificación de proveedor) y parte de REQ-121 (KPI "calificación baja") no van a
   poder mostrar datos reales en este sprint** — la fórmula depende de "entregas a tiempo/
   completas" (requiere que una orden llegue a estado `recibido`, lo cual pasa por Logística/
   Ingreso de Mercancía, diferidos) y de "reclamos" (módulo Garantías, diferido). Esto es
   **comportamiento correcto, no un bug**: RN2 del propio REQ-124 dice que sin órdenes recibidas
   el proveedor debe mostrar "Sin calificar", nunca 0/100 — QA debe probar ese estado vacío, no
   el cálculo real con datos de punta a punta.
3. **REQ-123 ("Última compra") sí es 100% verificable en este sprint** — se calcula desde
   `purchase_orders` (tabla nueva de este sprint), basta con que exista una orden creada para el
   proveedor, no requiere que llegue a ningún estado final.
4. **REQ-133 (asignar línea a "Pedido Aprobado")** necesita un query nuevo — no existe hoy un
   filtro `document_status=approved` en `QuoteListService`/`QuoteListController`
   (`routes/ventas-diseno.php:102`, `GET /quotes`). El estado "Aprobado" de una cotización es
   **computado** (`Quote::documentStatus()`, `app/Modules/VentasDiseno/Models/Quote.php:293-306`)
   vía join con `PipelineCard.stage`, no una columna simple. Se agrega un filtro nuevo a
   `QuoteListService` en vez de reimplementar la regla en Compras.
5. **REQ-118, aunque diferido, contiene una regla de negocio que SÍ aplica a Nueva Orden (2H,
   en alcance):** "Si Tipo de envío = Aéreo y Quién paga = Atlantic → requiere aprobación de
   Aprobador". El propio REQ-118 dice literalmente que reutiliza "los mismos campos y reglas" de Nueva
   Orden — es decir, esta regla vive conceptualmente en el wizard que sí estamos construyendo
   (SCRUM-191/192), aunque el texto completo solo aparece escrito en el ticket diferido. Se
   incluye en el diseño de `purchase_orders` de este sprint.
6. **Roles y permisos ya están completamente sembrados**, sin trabajo adicional: módulo `compras`
   en `ModuleCatalog`, rol `lider_compras` en la tabla `roles`, visibilidad en
   `role_module_visibility`, capacidades en `security_level_module_permissions` (niveles 11-14,
   incluyendo `can_approve` para Gerencia Total), y la usuaria real Lider Compras Test
   (`gerencia2@atlantic.com.pa`) ya con ese rol asignado.
7. **No existe mock visual de Compras** (solo hay mocks de Ventas & Diseño en
   `Illumination/sprint1/Mock/`). El diseño de pantalla se basa en los criterios de aceptación de
   cada ticket, sin referencia visual del cliente.
8. **REQ-142/145/154 (auto-confirmación de ingreso y "recepción por línea") NO están construidos
   — dependen de Ingreso de Mercancía (2J), que sigue sin existir.** Confirmado en código: el
   docblock de `PurchaseOrderController::advance()` dice explícitamente *"El efecto 'auto-confirma
   productos Por ingresar en Inventario' (REQ-142 Escenario 1) queda diferido — depende de Ingreso
   de Mercancía (2J), que no existe todavía"*, y no existe ninguna columna `received_quantity` (ni
   equivalente) en `purchase_order_lines` — REQ-145 Escenario 1 ("Recibido parcial — 10 de 120")
   no es demostrable, no hay ningún dato que lo respalde. Mismo patrón de riesgo aceptado que
   REQ-124/121 (punto 2 arriba), pero no estaba documentado como tal en la versión anterior de
   este spec — se agrega explícitamente en "Deuda/riesgos aceptados".
9. **REQ-140/REQ-146 ("Proyecto asignado" en la tabla principal de Ver Órdenes) es un hallazgo
   nuevo, no un riesgo aceptado — es un gap real que el PM debe asignar.** `formatSummary()`
   (`PurchaseOrderController.php:424-444`, el payload de `GET /api/compras/orders`) no incluye
   ningún campo de proyecto — ni un nombre simple ni un indicador de "múltiples proyectos". El
   frontend (`OrdersPage.tsx`) no tiene ninguna columna de proyecto (`grep -i "proyecto\|project"`
   no devuelve nada). REQ-140 Escenario 1 exige que la tabla muestre "Ver detalle" cuando una orden
   tiene líneas de 2+ proyectos distintos — hoy no hay ninguna columna que muestre ni un nombre ni
   ese texto. `formatDetail()` sí expone `sales_project_name` por línea, pero no hay ningún
   endpoint/campo que agregue "cuántos proyectos distintos tiene esta orden" para la tabla
   principal ni una vista de desglose agrupado (REQ-146 Escenario 1, "Ver detalle" agrupado por
   proyecto). Filtro por proyecto sí existe en el backend (`sales_project_id` vía
   `whereHas('lines', ...)`, `PurchaseOrderController.php:60-62`) — solo falta la columna/vista.
10. **Lider Compras Test (`lider_compras`), la única persona real identificada para operar todo este
    módulo, tiene hoy `security_level` = 1 ("Read Only") por default de su rol — sin ningún
    permiso de edición en `compras`.** Confirmado en
    `database/migrations/tenant/2026_07_18_100002_reuse_existing_levels_for_role_defaults.php`:
    el array `$defaults` mapea `'lider_compras' => 1`, y la fila de
    `security_level_module_permissions` para nivel 1 + módulo `compras` es
    `can_edit=false, can_approve=false` (líneas 100-102 del archivo). `BusinessRoleUserSeeder.php`
    no le da ningún override individual (a diferencia de Gerencia5, que sí tiene uno a Nivel 8) — Lidercompras
    queda con el default puro del rol. **Esto es inconsistente con el resto del roster**: los
    otros 4 roles "líder de área" (`lider_admin_contab`, `lider_bodega`, `lider_servicios`, y
    `vendedor_disenador`) tienen default Nivel 4 ("Editor sin aprobar", `can_edit=true`) — solo
    `lider_compras` y `lider_operaciones` quedaron en Nivel 1. Todo indica que es un valor mal
    asignado en esa migración (2026-07-10, antes de que existiera este sprint de Compras), no una
    decisión de negocio deliberada — no hay ningún comentario ni ADR que justifique que Compras
    deba ser de solo lectura para su propia líder. **Consecuencia real, más severa que
    `compras.settings.configure` (hallazgo B4 del Senior Review): con el dato actual, Lidercompras no
    puede crear proveedores, crear órdenes, avanzar estados ni subir documentos de logística — es
    decir, ninguna de las acciones de escritura de los 33 tickets de este sprint, no solo el
    umbral de calificación baja.** Ver sección de permisos más abajo para la recomendación
    concreta; esto requiere confirmación de Luis antes de aplicar el fix (cambio de nivel de una
    persona real).

## Diseño técnico (Arquitecto)

### Módulo nuevo: `App\Modules\Compras`

Mismo patrón estructural que `App\Modules\VentasDiseno` (Models/Http/Controllers/Http/Requests/
Services), multi-tenant por schema (`{tenant}_compras`), migraciones extendiendo
`App\Shared\Multitenancy\TenantSchemaMigration` con `protected string $module = 'compras';`.
Modelos con `getTable()` resolviendo `"{$tenant->slug}_compras.{tabla}"` (mismo patrón que
`CatalogProduct.php:48-56`), trait `LogsAuditTrail` en todos.

### Tablas nuevas (schema `compras`)

```sql
providers(
  id, name (required, NOT NULL),
  category, currency (default 'USD'),
  contact_name, phone, email,
  bank_name, bank_account_number, bank_account_type,
  notes,
  is_active (default true),
  created_by, created_at, updated_at
)

purchase_orders(
  id, provider_id (FK providers, RESTRICT — no se borra un proveedor con órdenes),
  status (enum: por_aprobar | pendiente_liquidar | ordenado | en_transito | en_aduana |
          en_transito_local | recibido — mismas 7 etapas ya documentadas en REQ-117/182 aunque
          Ver Órdenes/Logística estén diferidos; default 'por_aprobar'),
  shipping_type (enum: aereo | maritimo | terrestre, nullable),
  who_pays_shipping (enum: atlantic | proveedor, nullable),
  requires_mark_approval (boolean, calculado y guardado al crear — RN3 de REQ-118/hallazgo 5),
  approved_by (FK users, nullable), approved_at (nullable),
  currency, total_amount (decimal 12,2, recalculado por trigger de aplicación al agregar/quitar
    líneas, no por columna generada de Postgres — mismo criterio que el resto del proyecto),
  sales_project_id (FK proyecto, nullable — si TODAS las líneas comparten un mismo pedido; si son
    mixtas queda null y la asignación real vive por línea, ver abajo),
  notes,
  created_by, created_at, updated_at
)

purchase_order_lines(
  id, purchase_order_id (FK purchase_orders, CASCADE),
  catalog_product_id (FK catalog_products EN SCHEMA ventas_diseno — cross-schema, ver nota),
  quantity, unit_cost, subtotal (quantity * unit_cost),
  additional_cost_percent (nullable — REQ-131 costo adicional estimado, solo informativo hasta
    que Ingreso de Mercancía, diferido, lo reemplace por el costo real),
  sales_project_id (FK proyecto, nullable — REQ-133, "Ninguno (stock)" si null; el buscador solo
    ofrece proyectos con document_status=approved, ver hallazgo 4),
  created_at, updated_at
)
```

**Constraint nombrado explícitamente** en el FK cross-schema `purchase_order_lines.
catalog_product_id → ventas_diseno.catalog_products.id` (nombre de tabla largo con schema
calificado ya truncó constraints autogenerados antes — ver
`memory/feedback_tenant_migration_constraint_name_truncation.md`).

### Extensión de `catalog_products` (schema `ventas_diseno`, migración `ALTER TABLE`)

```sql
ALTER TABLE ventas_diseno.catalog_products ADD:
  provider_id (FK compras.providers, nullable — cross-schema, RESTRICT)
  factory_reference (varchar, nullable)
  reorder_point (integer, nullable)

-- reference (columna existente) pasa a representar la "referencia pública" (REQ-131 Escenario 3:
-- única globalmente, sin excepción — ya lo es hoy, no cambia comportamiento).
-- factory_reference es única solo DENTRO del mismo provider_id (REQ-131 Escenario 1/2):
CREATE UNIQUE INDEX ... ON catalog_products (provider_id, factory_reference)
  WHERE factory_reference IS NOT NULL;
```

Migración nueva en `database/migrations/tenant/`, mismo módulo `ventas_diseno` (se está
extendiendo una tabla ajena, no creando una propia de `compras`) — el Arquitecto asume el riesgo
de tocar una tabla en producción de un módulo ya entregado; **por eso este ALTER se prueba con la
suite completa de VentasDiseno antes de darlo por bueno, no solo con tests nuevos de Compras.**

### Endpoints (`routes/compras.php`) — actualizado 2026-07-16 con lo realmente entregado

```
GET    /api/compras/providers                  permission:compras.read
POST   /api/compras/providers                  permission:compras.write
GET    /api/compras/providers/{id}              permission:compras.read
PUT    /api/compras/providers/{id}              permission:compras.edit

GET    /api/compras/orders                      permission:compras.read   (filtros: provider_id, status, created_by, sales_project_id; chip=activos|critical|zona_libre)
POST   /api/compras/orders                      permission:compras.write  (wizard completo: proveedor + líneas)
GET    /api/compras/orders/{id}                  permission:compras.read
PUT    /api/compras/orders/{id}                  permission:compras.edit    (solo mientras "por_aprobar", REQ-144)
PATCH  /api/compras/orders/{id}/approve          permission:compras.approve (gate de Aprobador, RN3)
PATCH  /api/compras/orders/{id}/advance          permission:compras.edit    (REQ-141/142/143 — bloquea si falta aprobación de Aprobador)
GET    /api/compras/orders/{id}/pdf              permission:compras.read    (REQ-136/149, ?include_cost=)
POST   /api/compras/orders/{id}/send-email       permission:compras.edit    (REQ-136)
PATCH  /api/compras/orders/{id}/shipping-info     permission:compras.edit    (REQ-156 — contenedor/naviera/llegada real)
GET    /api/compras/orders/{id}/documents         permission:compras.read    (REQ-155)
POST   /api/compras/orders/{id}/documents         permission:compras.edit    (REQ-155)

GET    /api/compras/approved-projects             permission:compras.read    (REQ-133/146 — buscador de Pedidos Aprobados)
GET    /api/compras/products                      permission:compras.read    (REQ-130 — buscador CON costo)
GET    /api/compras/settings                      permission:compras.read
PUT    /api/compras/settings                      permission:compras.settings.configure  (REQ-121, umbral configurable — ver hallazgo 10/B4, sin nadie con este permiso hoy)
```

`permission:compras.approve` ya es emitido por `effectivePermissionsV2()` cuando
`security_level_module_permissions.can_approve=true` — Gerencia Total (nivel 9 en la escala 1-10
vigente desde el cutover de Fase C, ADR-006; la referencia a "nivel 11" de la versión anterior de
este documento quedó obsoleta tras `2026_07_18_100002_reuse_existing_levels_for_role_defaults.php`)
ya lo tiene sembrado. `compras.edit`/`compras.write` dependen de `can_edit=true` en esa misma
tabla — ver hallazgo 10 arriba: Lidercompras (`lider_compras`) hoy NO tiene `can_edit=true`.

### Extensión necesaria en `App\Modules\VentasDiseno`

`QuoteListService`/`QuoteListController` (`routes/ventas-diseno.php:102`) — agregar filtro
`document_status=approved` que reutilice `Quote::documentStatus()` en vez de que Compras
reimplemente el join con `PipelineCard`. Este es el único cambio de comportamiento que toca un
módulo ya entregado además del ALTER de `catalog_products` — aditivo (un filtro nuevo, opcional),
sin romper ningún caller existente.

### Reutilización directa, sin cambios

- `FuzzySearchService` (ya compartido en VentasDiseno) para el buscador de proveedores (REQ-122)
  y el buscador de productos por proveedor (REQ-130).
- `canViewTeamV2('compras')` para el alcance Mías/Equipo — funciona sin cambios, la visibilidad ya
  está sembrada (hallazgo 6).
- Patrón de KPIs/paneles de `HomeService` (un método privado por indicador, delegando en
  Services) como referencia de forma para REQ-121, aunque Compras no tiene un "Inicio" en este
  sprint — aplica igual a la respuesta de `GET /api/compras/providers` (KPIs junto al listado).
- Criterio "el costo nunca viaja al buscador" (`CatalogProductController.php:58`) — aplica igual
  al buscador de productos de REQ-130 (no exponer costo hasta seleccionar el producto).

## Deuda/riesgos aceptados para este sprint

> Sección actualizada 2026-07-16 — los primeros 2 puntos son del diseño original (14 tickets);
> los últimos 2 se agregan tras la reconciliación de los 33 tickets reales contra el código
> (ver "Hallazgos de reconciliación 2026-07-16" más abajo).

- Calificación de proveedor (REQ-124) y KPI de calificación baja (REQ-121) quedan en su estado
  vacío correcto ("Sin calificar"), no verificables end-to-end hasta que existan Logística/
  Ingreso de Mercancía/Garantías. **Ya no es un riesgo teórico**: Logística (2B) se implementó
  este mismo sprint, pero Ingreso de Mercancía y Garantías & Reclamos siguen sin existir, así que
  el riesgo se mantiene vigente.
- `purchase_orders.status` nace con 7 valores; hoy sí se usan en la práctica los 5 de la secuencia
  internacional completa (`por_aprobar → ordenado → en_transito → en_aduana → en_transito_local →
  recibido`) y los 4 de la secuencia local (salta tránsito/aduana, REQ-141) — a diferencia de la
  versión anterior de este documento, esto YA NO es riesgo aceptado, Ver Órdenes/Logística lo
  ejercitan de punta a punta. `pendiente_liquidar` sigue sin uso real (depende de "Liquidar" de
  Zona Libre, REQ-147, diferido).
- **(Nuevo 2026-07-16) REQ-142 (auto-confirmación de ingreso), REQ-145 (recepción por línea) y
  REQ-154 Escenario 1 (mismo auto-confirmación, visto desde Logística) NO están construidos — ver
  hallazgo 8.** Documentado explícitamente en el código (`PurchaseOrderController::advance()`)
  como diferido por depender de Ingreso de Mercancía (2J). Riesgo aceptado igual que REQ-124/121,
  pero no estaba reflejado en este documento hasta ahora — QA no debe esperar que "Recibido
  parcial — N de M" aparezca en ningún lado, esa columna no existe.
- `sales_project_id` a nivel de `purchase_orders` (para el caso "todas las líneas al mismo
  pedido") es una comodidad de consulta, no una regla de negocio explícita en los tickets — se
  mantiene sin uso por ningún endpoint/pantalla todavía; no bloquea nada, se deja como está.

## Hallazgos de reconciliación 2026-07-16 (Analista + Arquitecto, contra los 33 tickets "In Progress")

Reconciliación 1:1 de los 33 tickets de Jira (sprint id 6, estado "In Progress") contra el código
real de `sprint2`/`dev`. 29 de los 33 están completos y consistentes con su criterio de
aceptación. 4 tienen una discrepancia real:

| Ticket | Discrepancia | Ya conocido por Senior Review (2026-07-16) | Acción |
|---|---|---|---|
| SCRUM-204/190 (REQ-141/127) | `Provider.origin` sin campo en `ProviderFormDrawer.tsx` | Sí — B2 | Frontend Dev agrega el selector |
| SCRUM-214→219 (REQ-151/156, Logística) | `LogisticsPage.tsx` no sincroniza ni persiste bien contenedor/naviera/llegada | Sí — B3 | Frontend Dev |
| — (permiso transversal) | `compras.settings.configure` sin seeder ni UI | Sí — B4 | Backend Dev, ver sección de permisos abajo |
| SCRUM-203/209 (REQ-140/146) | Columna "Proyecto asignado" (single/"Ver detalle") ausente en `formatSummary()` y en `OrdersPage.tsx` | **No — hallazgo nuevo de esta reconciliación** | Backend Dev + Frontend Dev, ver tarea PM abajo |

Además, un hallazgo de permisos que **no es de código sino de datos de configuración**, y es más
severo que B4: **Lider Compras Test (`lider_compras`) tiene `security_level`=1 ("Read Only") por default
de rol — sin `compras.edit`, no solo sin `compras.settings.configure`** (hallazgo 10 en la sección
de dependencias arriba). Ningún ticket de escritura de este sprint es operable por la única
persona real asignada a Compras hoy.

Los 3 blockers de código (B2/B3, más el fix de `compras.settings.configure` en el seeder) ya están
asignados a Backend/Frontend Dev por el PM, fuera de este documento — no se tocan acá.

### Respuesta a la pregunta del Senior Review: ¿quién debe tener `compras.settings.configure`?

**Lider Compras Test (`gerencia2@atlantic.com.pa`, rol `lider_compras`) es la persona real
identificada** — confirmado en código: `database/seeders/BusinessRoleUserSeeder.php:52` la da de
alta con ese rol, `database/migrations/tenant/2026_07_11_100000_create_auth_roles_table.php:35`
define `lider_compras` = "Líder de Compras" (`is_system: true`), y
`2026_07_11_100001_create_auth_role_module_visibility_table.php:53-55` le da visibilidad real del
módulo `compras`. No hay ninguna otra persona ni rol en Jira/código que sea candidato — es ella o
nadie.

**Pero no alcanza con agregarla al `SpecialPermissionSeeder` para `compras.settings.configure` y
darlo por resuelto** (patrón Aprobador/Contabilidad de Ventas & Diseño) — ese patrón asume que la persona ya
tiene el resto de permisos de módulo (`.edit`/`.write`) y solo le falta el permiso angosto. Lidercompras
hoy no tiene ni eso: su `security_level`=1 le bloquea `compras.edit` completo (hallazgo 10). Fix
recomendado, en 2 partes, ambas requieren decisión de Luis antes de implementar (cambia el acceso
real de una persona):

1. **Corregir el `security_level_id` default de `lider_compras`** de Nivel 1 a Nivel 4 ("Editor
   sin aprobar") — el mismo nivel que ya tienen los otros 4 roles "líder de área"
   (`lider_admin_contab`, `lider_bodega`, `lider_servicios`, `vendedor_disenador`). Esto resuelve
   `compras.edit`/`compras.write` para toda la operación normal del módulo (crear proveedores,
   crear/editar/avanzar órdenes, subir documentos de logística).
2. **Agregar `compras.settings.configure` a `SpecialPermissionSeeder.php`** para
   `gerencia2@atlantic.com.pa`, igual patrón que Aprobador/Contabilidad — este permiso angosto no es
   parte del puente `.edit`→`.write` de `effectivePermissionsV2()` (confirmado por B4 del Senior
   Review), así que necesita su propia entrada aunque el fix del punto 1 ya se aplique.

Si Luis prefiere no tocar el nivel de Lidercompras por ahora, la alternativa mínima es (2) solo — pero
eso deja a Lidercompras sin poder operar el resto del módulo (crear órdenes, etc.), lo cual contradice
el propósito completo del sprint. **Se recomienda (1)+(2) juntos.**

## Historial de cambios de alcance

**2026-07-16 (Analista + Arquitecto, esta revisión):** este documento decía "14 tickets, resto
diferido a backlog" desde el daily del 2026-07-14, pero la implementación real —ampliada esa
misma semana (ver memoria `project_sprint2_compras_proveedores.md`, sección "Ampliación
2026-07-14")— llegó a 33 tickets (Ver Órdenes y Logística completos + PDF/email), y ese cambio de
alcance nunca se reflejó por escrito acá. El Senior Review del 2026-07-16
(`atlanticerp/docs/reviews/sprint2-compras-proveedores.md`, hallazgo B1) lo marcó como blocker: "el
documento de spec aprobado por Luis nunca se actualizó... QA formal va a estar validando contra
una spec que dice que Logística/Ver Órdenes no existen todavía". Luis ya había aprobado la
ampliación verbalmente el 14-jul (ver memoria) pero pidió explícitamente el 16-jul que
Analista/Arquitecto/PM "redefinan las specs... usando el avance actual del código y los tickets
correspondientes al Sprint 2-Compras como fuente de verdad" — este documento es el resultado de
ese pedido. Cambios de este pase:
- Sección "Alcance" reescrita: 33 tickets reales (antes 14), resto (61) confirmado sin cambios en
  "To Do".
- "Endpoints" actualizado con los 13 endpoints reales (antes 4) y corregida la referencia a
  "nivel 11" (obsoleta desde el cutover de niveles de Fase C, ADR-006).
- "Deuda/riesgos aceptados" actualizado: 2 riesgos originales revisados (uno ya no aplica, el
  ciclo de estados sí se ejercita), 1 riesgo nuevo agregado (REQ-142/145/154).
- 2 secciones nuevas: "Hallazgos de reconciliación 2026-07-16" (discrepancias ticket-vs-código
  encontradas al reconciliar los 33) y la respuesta documentada a la pregunta de permisos que
  dejó pendiente el Senior Review (`compras.settings.configure`).
- No se tocó ningún diseño de tablas/modelos ya implementado — este pase es documentación de
  alcance real, no rediseño. La única recomendación de cambio real (más allá de actualizar texto)
  es el fix de `security_level` de `lider_compras`, que requiere decisión de Luis antes de
  aplicarse (ver sección de permisos arriba) — no se implementó en este pase.
