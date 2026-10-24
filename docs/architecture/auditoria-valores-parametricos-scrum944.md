# Auditoría de valores de negocio hardcodeados — SCRUM-944/945

**Fecha**: 2026-09-23
**Alcance**: `app/Modules/**/Services`, `Controllers`, `Jobs` — todos los módulos backend.
**Definición usada** (spec `docs/specs/auditoria-valores-parametricos-superadmin.md`, decidida por
Luis 2026-09-23): constantes que alimentan un **cálculo o decisión de negocio** con impacto
monetario o de aprobación (umbrales de aprobación, %/márgenes, divisores de negocio, días de
crédito/plazo, límites de intentos que cortan un flujo de negocio). Excluye configuración técnica
pura (timeouts, TTLs, paginación, epsilon de redondeo de punto flotante).

Método: grep dirigido (comparaciones numéricas, ventanas de días, multiplicadores de porcentaje,
palabras clave de negocio) sobre los 14 módulos de `app/Modules/`, seguido de lectura manual de
cada candidato.

## Hallazgos reales — migrados

### 1. CRM — `DashboardService`/`ProjectService` (MIGRADO)

- **`app/Modules/CRM/Services/DashboardService.php:66`** (antes) — `now()->addDays(3)` para
  "próximo a vencer" del Dashboard. **Migrado** a `CrmSettingsService::proximoDias()`.
- **`app/Modules/CRM/Services/DashboardService.php:69`** (antes) — `now()->subDays(21)` para
  proyecto "frío" (sin actividad). **Migrado** a `CrmSettingsService::freshnessStaleDias()`.
- **`app/Modules/CRM/Services/ProjectService.php:93`** (antes) — mismo `addDays(3)` duplicado en
  el filtro `urgency=proximo`. **Migrado**.
- **`app/Modules/CRM/Services/ProjectService.php:100-101`** (antes) — `subDays(7)`/`subDays(21)`
  duplicados en el filtro `freshness=recent|stale`. **Migrados**.

**Motivo**: 3 umbrales de negocio duplicados en 2 archivos (riesgo real de drift), sin tabla
paramétrica — CRM no tenía ninguna. Nueva tabla `crm_settings` (key/value, mismo patrón que
`bodega_settings`/`BodegaSettingsService`), sin pantalla propia todavía — solo expuesta vía
`GlobalSettingsPage` (vista consolidada de superadmin, SCRUM-944/945). Sin tests previos que
cubrieran estos filtros — se agregaron en `tests/Feature/CRM/CrmSettingsControllerTest.php`.

### 2. Gerencia — `GerenciaHomeService` (MIGRADO, 2 pasadas)

- **`app/Modules/Gerencia/Services/GerenciaHomeService.php:138`** (antes) —
  `Carbon::now()->subDays(30)` para "CxC vencidas" (tarjeta `kpis.cxc_al_dia`).
- **`app/Modules/Gerencia/Services/GerenciaHomeService.php:141`** (antes) — ventana
  `[subDays(30), subDays(23)]` para "por vencer en 7 días".
- **`app/Modules/Gerencia/Services/GerenciaHomeService.php:487`** (antes) — mismo `subDays(30)`
  duplicado en `salud_modulos.admin_contab.cuentas_al_dia`.

**Motivo**: el "30" hardcodeado es EXACTAMENTE el mismo concepto que
`AdminContFiscalSettings::current()->dias_credito_factura` (plazo de crédito real, editable desde
Configuración Fiscal, ya usado por `AccountStatementService`, `CashPositionService` e
`InvoiceService`). Gerencia lo reimplementaba con un "30" propio: si Luis cambiaba el plazo de
crédito desde Fiscal, el dashboard de Gerencia quedaba desincronizado en silencio del resto del
sistema. Viola además la regla dura propia del módulo (`app/Modules/Gerencia/CLAUDE.md`: "cada
tarjeta reusa el mismo cálculo que la pantalla nativa del módulo, nunca reimplementa la métrica en
paralelo" — regla nacida de SCRUM-168 v9, mismo tipo de bug).

**1ra pasada (fix mínimo)**: leer `AdminContFiscalSettings::current()->dias_credito_factura` en
vez de "30", sin tocar el resto de la query — documentado entonces como fuera de alcance de la
auditoría (que migra almacenamiento, no reescribe cálculos) reusar
`InvoiceService::cobrablesConVencimientoSigned()`, que ya centraliza esta fórmula exacta.

**2da pasada (pedido explícito de Luis, mismo día)**: se aplicó la sugerencia — `GerenciaHomeService`
ahora reusa `InvoiceService::cobrablesConVencimientoSigned()` (misma fórmula que Antigüedad de
Cartera/Estado de Cuenta/Posición de Caja) en vez de reimplementar la query. Al hacerlo apareció
una segunda pregunta real, NO solo de refactor: la fórmula centralizada usa **saldo neto**
(`monto - saldo_aplicado`) mientras la query vieja de Gerencia sumaba **monto bruto** — dos
definiciones distintas de "Cuentas por Cobrar". Consultado con Luis: **decidió pasar a saldo neto**
(una factura con abono parcial ya no cuenta el monto completo como pendiente) — más correcto
contablemente, y de paso excluye facturas anuladas (`anulada_at`), que la query vieja no filtraba.
Tests: `GerenciaHomeControllerTest::test_cxc_vencidas_usa_el_plazo_de_credito_configurado_no_30_hardcodeado`
(1ra pasada) + `test_cxc_total_usa_saldo_neto_no_monto_bruto` + `test_cxc_excluye_facturas_anuladas`
(2da pasada).

### 3. VentasDiseno — `SalesTotalsService::percentFor()` (MIGRADO — revisión superada 2026-09-23)

- **`app/Modules/VentasDiseno/Services/SalesTotalsService.php:20-29`** (antes) — tabla escalonada
  de comisión de ventas hardcodeada como `match(true)`: 1.5%/2.0%/3.0%/4.0%/4.5%/5.0% en cortes de
  $20k/$35k/$60k/$80k/$120k.

**Historial**: este hallazgo ya había sido relevado y decidido por Luis el 2026-08-25
(`docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md` §1, al construir `commission_tiers`
para Comisiones Internas de Admin&Cont) — en ese momento decidió mantenerlo hardcodeado a
propósito, como deuda técnica conocida y documentada, sin unificar con `commission_tiers` (otro
concepto de negocio). Esta auditoría lo volvió a encontrar (de forma esperada — nunca se había
migrado) y, al reportarlo, **Luis pidió migrarlo esta vez** (2026-09-23). Ver el ADR actualizado
(nota al inicio) para el registro de ambas decisiones.

**Migrado**: nueva tabla `ventas_diseno.sales_commission_tiers` (mismo shape que `commission_tiers`:
`monto_minimo`/`monto_maximo` nullable/`porcentaje`/`orden`, tabla separada a propósito — sigue
siendo otro concepto de negocio, solo dejó de estar hardcodeado). Seed = los mismos 6 tramos que
ya estaban en el código (comportamiento preservado exactamente — `monto_maximo` de cada tramo
termina en ".99" para replicar el corte estrictamente-menor original con el modelo de rango
inclusivo). CRUD completo (`SalesCommissionTierController`, gateado `superadmin.all`, mismo
patrón de re-secuenciado de `orden` que `AdminContInternalCommissionController`). Test dedicado
(`SalesCommissionTierControllerTest::test_percent_for_preserva_los_6_tramos_originales`) verifica
los 12 puntos de corte (justo abajo y justo en cada límite) contra el comportamiento original.

## Hallazgo real — revisado, excluido (zona gris)

### 4. Bodega — `BodegaHomeService::estadoPedidos()` (revisado, excluido)

- **`app/Modules/Bodega/Services/BodegaHomeService.php:484`** — `subDays(7)` para contar pedidos
  que entraron a `STAGE_ENTREGADO` en los últimos 7 días (tarjeta de estado del Home).

**Motivo de exclusión**: es la definición fija de una ventana de reporte ("entregados esta
semana"), no un umbral de negocio que dispare una alerta/aprobación — a diferencia de
`urgente_ventana_dias` (que sí vive en `bodega_settings` y SÍ dispara el chip "🔥 Urgentes"), este
valor no tiene una decisión de negocio detrás que alguien vaya a querer ajustar sin deploy. Zona
gris, marcada acá para que quede trazado — si Luis prefiere parametrizarlo de todos modos, es un
cambio de una línea siguiendo el mismo patrón que el resto de `BodegaSettingsService`.

## Revisado, sin hallazgos (ya parametrizado o técnico)

- **AdminCont** — sample dirigido a `AccountStatementService`, `PaymentService`, `PendingAlertsService`,
  `CreditNoteService`, `ArchitectCommissionService`, `CarteraCommissionService`,
  `InvoiceBookService`, `CashPositionService`. Todos los umbrales/porcentajes de negocio reales ya
  resuelven vía `AdminContFiscalSettings::current()`/tablas de tramos dedicadas
  (`CommissionTier`, `CarteraCommissionTier`, `AdminContItbmsRate`). Las comparaciones `> 0.001` /
  `+ 0.005` encontradas en `PendingAlertsService`/`PaymentService` son epsilon de redondeo de
  punto flotante — técnicas, no de negocio.
- **Servicios** — `CommissionCalculationService.php` ya tiene su propio docblock explícito ("Todos
  los pesos/montos/umbrales de conversión vienen de `ServiciosSettingsService`... reforzada
  2026-08-11 ... tras encontrar que los umbrales de este archivo SÍ lo estaban") — ya fue
  auditado y corregido en una sesión previa. Sin hallazgos nuevos.
- **Compras** — `SubstituteSimilarityService.php` usa pesos de `ComprasSettings` (`similarity_weight_*`);
  los `/100`/`*100` son conversión de escala porcentual, no constantes de negocio.
- **Auth** — `PreferencesService::MIN_SHARE_LINK_EXPIRATION_DAYS`/`MIN_SESSION_INACTIVITY_HOURS`
  son pisos de validación de input (nunca 0 o negativo), no política de negocio ajustable.
- **AI, Integrations, Legacy, Notifications, Soporte, Lumo, Gerencia (resto)** — sin candidatos
  reales tras el grep dirigido (paginación de APIs externas, timeouts, contadores técnicos).

## Verificación final

Grep de cierre (mismos patrones que el inicial) sobre `app/Modules/**/Services|Controllers|Jobs`
no encuentra constantes de negocio nuevas sin resolver. De los 4 hallazgos reales, 3 quedaron
migrados (CRM, Gerencia, VentasDiseno) y 1 quedó documentado como zona gris excluida (Bodega,
hallazgo #4) — ninguno quedó sin resolver en silencio.

## Addendum — Pre-QA SCRUM-945 (2026-09-23): hallazgos A, C y E

Decisiones de Luis (2026-09-23): corregir A (validación en backend), C, y E incluido en SCRUM-945.

- **A — tramos de comisión (`sales_commission_tiers`).**
  - `PUT /api/ventas-diseno/sales-commission-tiers` (`superadmin.all`): reemplazo **atómico** de la
    tabla. Body `{ "tiers": [{ "id"?, "monto_minimo", "monto_maximo" (null = abierto), "porcentaje" }] }`;
    `orden` se deriva por `monto_minimo`. Un `id` existente se actualiza en el lugar, sin `id` se
    crea, los ids ausentes se eliminan. Una sola fila en `audit_logs`
    (`ventas_diseno.sales_commission_tiers.replaced`, antes/después completo) — se suspenden los
    eventos de modelo a propósito.
  - Validación 422 (clave `tiers`): mínimo un tramo, el primero empieza en 0, sin solapamientos, sin
    huecos (convención sembrada: siguiente mínimo = máximo + 0.01), solo el último tramo es abierto
    y el último debe serlo, mínimo <= máximo, porcentaje 0–100, ids inexistentes rechazados.
    Lógica en `VentasDiseno\Support\CommissionTierRules` (centavos enteros, sin floats).
  - Senior Review S1/S2/S5/S6: los POST/PUT/DELETE individuales **se retiraron** (permitían
    reproducir solapamientos/huecos tramo a tramo) — el PUT atómico es el único camino de escritura,
    `GET` se mantiene (POST responde 405, PUT/DELETE `/{id}` 404). `tiers.*.id` con `distinct` (422 en
    `tiers.N.id`), montos con `decimal:0,2`, y snapshot + escritura + audit dentro de una sola
    transacción.
- **C — `CrmSettingsRequest`:** `freshness_recent_dias < freshness_stale_dias`; si llega solo uno se
  compara contra el valor persistido del otro (422 sobre los campos enviados).
- **E — `GlobalSetting` genérico.** `GET /api/admin/global-settings` y
  `PUT /api/admin/global-settings/{key}` (`superadmin.all`), lista blanca única en
  `Auth\Support\GlobalSettingsRegistry` (`documents.max_size_mb` 1–500,
  `notifications.archive_read_after_days` 1–365, `audit_logs.retention_months` 12–120 (grupo `audit_logs`) — mínimo 12 por
  la decisión del CTO de SCRUM-812/REQ-636). Clave fuera de la lista (incluida `anthropic_api_key`) →
  404; valor fuera de rango o no entero → 422; clave ausente en BD → GET devuelve el default. El PUT
  invalida la cache `global:{key}` de los consumidores y deja `audit_logs` (`settings.updated`). `label_key` = `globalSettings.globalTab.labels.<key con "."→"_">`.
  Sin migraciones nuevas; los endpoints bespoke existentes no cambian.
