# Pre-QA — SCRUM-939 (Excel numérico) y SCRUM-945/944 (auditoría + vista consolidada de superadmin)

**Fecha:** 2026-09-23 · **Entorno:** `dev.atlanticerp.ai` (HEAD `b43be85`, confirmado por SSH) · **Ejecutó:** Pre-QA (fork)
**Visual Review:** **N/A en ambos** — `jira_get_attachments` devuelve 0 adjuntos en SCRUM-939, SCRUM-945 y SCRUM-944 (no hay mockup).
**Pasadas:** (1) ~12:10–12:15, cortada por un corte de acceso a dev; (2) ~12:55–13:10, completa, con throttling
estricto. Esta versión del reporte reemplaza a la de la pasada 1.

## Veredicto

| Ticket | Veredicto | Motivo |
|---|---|---|
| **SCRUM-939** | **Pasada limpia** | Los 3 Excel numéricos y los 3 PDF intactos, sin diferencias contra la API. 2 observaciones fuera de alcance. |
| **SCRUM-945/944** | **PASADA LIMPIA (5ª pasada, 15:42–15:52) — ver la sección final.** Las pasadas 2–4 encontraron y verificaron la corrección de A, B, C y E. | Reemplaza el veredicto "con hallazgos" de la 2ª pasada. |

## Incidente de infraestructura (pasada 1) — resuelto, causa no confirmada

`dev.atlanticerp.ai` dejó de responder (TCP 443/22) ~12:15 y volvió ~12:52 (≈38 min); test y prod respondían normal.
Causa no confirmada (baneo de IP por ráfaga vs. caída de la instancia). En la pasada 2 se aplicó throttling:
**0 logins nuevos** (se reutilizaron los tokens de la pasada 1, vigentes 10 h), pausa de 1 s entre llamadas,
83 llamadas HTTP de la batería API + un spec de navegador, `/up` verificado antes y después de cada bloque,
y **dev no volvió a dejar de responder** en ningún momento. Los 4 PDF (los que se colgaron en la pasada 1) ahora
responden en ~1 s cada uno, así que el cuelgue de entonces fue del corte, no de los reportes.

## SCRUM-939 — Excel de Estado de Cuenta, Posición de Caja y Facturas con montos numéricos

Criterios (Jira): (1) los 3 Excel escriben montos como celdas numéricas con `$#,##0.00`; (2) los negativos
(notas de crédito) quedan numéricos; (3) ninguna otra columna ni dato cambia; (4) tests que abren el `.xlsx`.

| Escenario (ruptura intentado) | Resultado |
|---|---|
| Facturas (`tab=cobrable`, 23 filas): tipo y formato de la columna Monto | 23/23 celdas `float`, formato `$#,##0.00`. Encabezado sin formato (correcto). |
| Facturas: montos del Excel vs. API `GET /invoices?view=plana` (2 páginas) | 0 diferencias; suma 63 313.29 en ambos. |
| Facturas: monto `null` (entrega "Pendiente por facturar") | Sale `0.0` numérico, no celda vacía (`strictNullComparison`). Antes del cambio también salía `0.00`. |
| Facturas: sin datos (`tab=incobrable`, `cliente=ZZZ_NO_EXISTE`) | 200, archivo solo con encabezados, sin error. |
| Facturas: `format=csv` | 422 con mensaje claro. |
| Estado de Cuenta (cliente 7040, 7 movimientos): Débito/Crédito/Saldo y "Saldo actual" | Todas numéricas con `$#,##0.00`; 0 diferencias contra `GET /account-statement`. Ceros como `0.0`, no vacías. |
| Estado de Cuenta: N° de cuenta bancaria (`03-01-01-074455-4`) | Sigue como texto (correcto — no es un monto). |
| Posición de Caja proyectado 0/30/90 y real 30/90 | Numéricas; Salidas negativas (`-160.5`, `-535.0`, `-352.43`, `-517.5`) siguen numéricas; "Neto proyectado" 12 416.9 = `neto` de la API. |
| Posición de Caja real (Entrada 0.0 / Salida / Saldo acumulado negativo) | Todas numéricas; ceros presentes. |
| Posición de Caja: `view` inválido, `window=45`, `real+window=0` | 422 con mensaje claro en los tres. |
| **PDF** de los 3 reportes (4 variantes: Estado de Cuenta, Facturas, Caja proyectado, Caja real) | 200, `application/pdf`, ~880–910 KB, ~1 s. El texto sigue con montos formateados (`3,293.65`, `$1,500.00`, `$-1,504.84`) — **sin regresión**. |
| Rol sin permiso sobre los 3 exports (`lider_bodega`) | 403 en los 3. Sin token: 401 en los 3. `lider_admin_contab`: 200 en los 3. |
| Otras columnas/datos cambiados | Ninguno (fechas, N° de factura/cotización, guía, estado siguen como texto; contenido idéntico al de la API). |

**Lo que sí funciona:** los 3 reportes en Excel y PDF, sus variantes de ventana/vista, los casos vacíos y el gating por rol.

**No verificado:** una **nota de crédito real como Salida negativa** en Posición de Caja — en dev no hay ninguna
NC pendiente hoy; solo se comprobó que las Salidas negativas de *comisiones* siguen numéricas. El caso NC lo
cubre `test_939_excel_proyectado_escribe_salida_de_nota_de_credito_como_celda_numerica_negativa` (suite backend
en verde según el Senior Review), no se ejercitó en dev.

### Observaciones fuera de alcance de SCRUM-939 (no bloquean este ticket)

1. **BAJO/MEDIO — Excel de Estado de Cuenta rotula como "Cobro" toda fila que no sea `factura`.**
   `AccountStatementExportService.php:90` → `tipo === 'factura' ? 'Factura' : 'Cobro'`. En el cliente 7040 la API
   devuelve tipos `anulacion` y `devolucion`, y el Excel los rotula "Cobro" (filas 15 y 18). Es de 2026-08-23
   (`d412ce08`), no lo introdujo SCRUM-939; el AC exige que "ninguna otra columna ni dato cambie". El mismo mapeo
   está duplicado en `AccountStatementPdfService.php:93`. Sugerido: ticket de seguimiento.
2. **Cosmético — Facturas: el filtro `desde=2099-01-01` devuelve 11 filas** (las "pendientes por facturar", sin
   fecha). Comportamiento previo del listado.
3. **Convención de signo distinta entre Excel y PDF de Posición de Caja:** el Excel escribe las salidas en negativo
   (`-160.5`) y el PDF las muestra positivas (`$160.50`). Es el diseño previo (el PDF no cambió); solo se anota.

## SCRUM-945/944 — auditoría de valores paramétricos + vista consolidada

### Lo que sí funciona (verificado en runtime)

| Criterio / escenario | Resultado |
|---|---|
| **Gating API** — `lider_admin_contab` y `lider_bodega` sobre `GET/PUT /crm/settings`, `GET/POST/DELETE /ventas-diseno/sales-commission-tiers` | **403 en las 10 combinaciones**; anónimo 401. CRM intacto tras los intentos (no hubo escritura). |
| **Gating UI** — Admin&Cont en `/configuracion/global` | No ve el título ni el ítem del Sidebar (Playwright, sesión inyectada). |
| **Fiscal sigue exclusiva de Mark** — `GET /admin-contab/fiscal-settings` | `lider_admin_contab` 403, `lider_bodega` 403, superadmin 200 (bypass documentado en `EnsureIsPrimaryApprover`). UI: Admin&Cont no ve el formulario en `/admin-contab/fiscal`. |
| **Vista consolidada** | 11 tabs visibles; cada una monta sin pantalla en blanco ni errores JS (recorrido de las 11). |
| **Solo la tab activa monta su panel** | `/api/crm/settings` no se pide hasta abrir la tab CRM (conteo de requests). |
| **Validación de servidor CRM** | `0`, `-1`, `366`, `'abc'`, `2.5`, `null`, `''` → **422** en los 3 campos (21 casos) y `1000` → 422; el valor guardado nunca cambió. |
| **Validación de servidor de tramos** | `max<=min`, `max==min`, `min` negativo, `%>100`, `%` negativo, `%` ausente, `%` no numérico, `orden=0` → **422** (8 casos), sin residuos. `PUT` parcial con `monto_maximo=10` sobre un tramo con mínimo 20 000 → 422. Tramo inexistente → 404 (PUT y DELETE). |
| **Efecto real: `entrega_proxima_dias` → filtro `urgency=proximo`** | 3 d → 2 proyectos (25-sep ×2) · 30 d → 4 (25-sep ×2, 21-oct, 23-oct) · 1 d → 0 · vuelta a 3 d → 2. El cambio se refleja de inmediato. Repetido desde la UI (spec e2e). |
| **Efecto real: CxC de Gerencia neto vs. bruto** | `kpis.cxc_al_dia.total` = **13 982.33** = `total_entradas` de Posición de Caja a 90 días. El bruto de esas 3 facturas sería 14 032.33 (la F-0006 tiene 532.33 bruto y 482.33 de saldo) → **usa saldo neto**, como se acordó. |
| **Auditoría (`audit_logs`)** | Cada edición desde la vista dejó fila: `c_r_m.crm_setting.updated` (con `old_values`/`new_values`, p. ej. `{'value':'1'}`→`{'value':'3'}`) y `ventas_diseno.sales_commission_tier.created/updated`, atribuidas al usuario (Superadmin Test 3). |
| Migraciones/datos sembrados en dev | (pasada 1, SSH) aplicadas; 3 filas CRM y 6 tramos correctos. |

### Hallazgos

```
MEDIO (A) — CONFIRMADO en runtime. Tramos de comisión sin validación de cobertura.
  El backend acepta (a) un tramo que SOLAPA a otro y (b) un HUECO entre tramos. Reproducido:
    · POST 25 000–30 000 al 9 % → 201 (solapa el tramo 20 000–34 999.99 al 2 %); queda con orden 3 y desplaza al resto.
    · PUT del último tramo con monto_minimo 120 000 → 200 000 → 200 (deja sin tramo 120 000–200 000).
  Consecuencia por código (SalesTotalsService::percentFor): un total sin tramo que lo cubra devuelve 0.0 %, es decir
  comisión 0 para los vendedores, en silencio; ante solapamiento gana el de menor `orden`. La única defensa hoy es un
  window.confirm al borrar. Es un parámetro con impacto monetario directo (regla del proyecto: configurable, pero no
  debería poder quedar incoherente). La spec no lo lista como AC, pero la vista lo hace editable por primera vez.
  Cómo reproducir: POST/PUT anteriores con token de superadmin (ver evidencia en el spec e2e / batería).
  → Requiere decisión de PM/Luis: validar cobertura contigua en backend, o aceptar el riesgo explícitamente.

MEDIO (B) — CONFIRMADO en runtime. El 422 no se muestra al usuario (mismo patrón que SCRUM-63, ya documentado).
  CrmSettingsPanel y SalesCommissionTiersPanel no tienen onError en las mutaciones. Reproducido con Playwright:
    · CRM: escribir 0 en "Entrega próxima" y "Guardar" → el backend responde 422; en pantalla no cambia nada: el campo
      sigue en 0, "Guardar" sigue visible, ningún mensaje/toast (captura e2e/__screenshots__/scrum945-crm-422-sin-feedback.png).
    · Tramos: crear un tramo con máximo 400 000 < mínimo 500 000 → 422 sin mensaje (captura ...tramo-422-sin-feedback.png).
  El superadmin cree que guardó. → Fix chico (mostrar el error de la mutación / usar el toastStore existente).

BAJO (C) — CONFIRMADO. Sin validación cruzada entre freshness_recent_dias y freshness_stale_dias.
  PUT {recent: 60, stale: 10} → 200. Deja el filtro de frescura incoherente ("reciente" más ancho que "frío").

A CONFIRMAR CON PM/LUIS (E) — NO autoclasificado. La vista no incluye ningún valor de `GlobalSetting`.
  La spec (edge case) pide que "un valor hoy solo accesible vía GlobalSetting debe aparecer igual en la vista consolidada".
  La vista tiene 11 tabs y ninguna es de GlobalSetting (claves reales: share_links_expiration_days, documents.max_size_mb,
  anthropic_api_key). La spec deja como pregunta abierta al Arquitecto si se incluyen las claves NO-negocio, y
  docs/architecture/auditoria-valores-parametricos-scrum944.md no lo resuelve. Si share_links_expiration_days cuenta como
  plazo de negocio, hay un hueco entre spec y entrega. Requiere confirmación de PM/Luis antes de darlo por alcance diferido.

COSMÉTICO (D) — voseo. El confirm de borrado dice "¿Confirmás eliminar este tramo? …". En es hay ~20 cadenas en voseo
  (Confirmás ×7, Revisá ×9, Verificá ×2, Elegí ×2) frente a ~69 en tuteo (Selecciona ×54, Ingresa ×15): es una
  inconsistencia previa y general, no específica de este ticket. Solo para revisión de redacción de QA.
COSMÉTICO (F) — el review/descripción dicen "10 tabs"; la vista tiene 11 (se agregó "Comisión de vendedores").
COSMÉTICO (G) — la acción de auditoría se llama `c_r_m.crm_setting.updated` (el módulo "CRM" queda letra por letra).
```

### Observaciones (sin severidad, fuera de alcance)
- `ip_address` es `null` en las 50 filas más recientes de `audit_logs`, de todos los módulos (`auth`, `security`,
  `admin_cont`, `ventas_diseno`, `c_r_m`) — no es propio de estos cambios; conviene contrastarlo con REQ-633 ("IP real").
- **No verificable en runtime:** el efecto de un tramo sobre `percentFor` de la comisión de vendedores. `GET
  /ventas-diseno/reports/commissions` devuelve vacío en dev (no hay tarjetas aprobadas en el mes). Lo cubre el test de
  12 puntos de corte del Senior Review, no se ejercitó en dev; sembrar tarjetas aprobadas requeriría escribir datos
  de negocio y no se hizo.
- **No verificado con la cuenta de Mark:** que Mark sí vea Fiscal en su pantalla original (no se logueó con esa cuenta
  por el throttling). Sí se verificó lo contrario (Admin&Cont/Bodega 403) y que superadmin entra por bypass.

## Restauración en dev

Línea base leída al empezar y **verificada por lectura al terminar** (dos veces: al cierre de la batería y tras el
spec de navegador):
- `crm_settings`: `entrega_proxima_dias=3`, `freshness_recent_dias=7`, `freshness_stale_dias=21` ✅
- `sales_commission_tiers`: 6 tramos `(id 1..6) 0–19 999.99 1.5 % · 20 000–34 999.99 2 % · 35 000–59 999.99 3 % ·
  60 000–79 999.99 4 % · 80 000–119 999.99 4.5 % · 120 000–sin tope 5 %`, órdenes 1..6 ✅
- El tramo temporal (id 7, solapado) se borró (DELETE 204). Los `orden`/ids originales se conservaron (ningún tramo
  original se borró; solo se editó y se restauró el id 6).
- Quedan en `audit_logs` las filas de las ediciones y restauraciones de esta pasada (no se borran; es inmutable).

## Qué falta para mover cada ticket a QA

- **SCRUM-939:** nada bloqueante. Pasada limpia y concluyente; Visual Review N/A (sin mockup). Decidir si las
  observaciones fuera de alcance (rótulo "Cobro" del Excel/PDF; convención de signo) se abren como tickets.
  Marcadores locales del gate Jira (`~/.claude-preqa-markers/SCRUM-939`, `~/.claude-visual-review-markers/SCRUM-939`):
  **no creados** — la orden fue no mover tickets; los crea quien haga la transición.
- **SCRUM-945:** el ticket vuelve a `Dev Testing`/`In Progress` según el tamaño del fix. **B** (mensaje de error)
  es un fix chico en 2 componentes. **A** (validación de cobertura de tramos) toca una regla de negocio nueva → PM
  reasigna y Luis/Arquitecto decide el criterio (validar contigua y sin solapes vs. aceptar). **E** necesita
  confirmación de PM/Luis. **C** BAJO a criterio. Tras el fix, **repetir el checklist completo** (regla dura de
  `pre-qa.md`); el spec preparado (`atlanticerp-frontend/e2e/preqa-scrum944-945-configuracion-global-20260923.spec.ts`,
  sin commitear) ya lo automatiza y es candidato a promoverse a `e2e/` permanente (gating de permisos + 422 sin feedback,
  ambos ya rotos una vez). Notar que hoy sus dos tests de 422 quedan en rojo a propósito hasta que se corrija B.
- Además, aprobaciones que no vi en ningún documento: la spec `docs/specs/auditoria-valores-parametricos-superadmin.md`
  sigue en `Draft — pendiente de aprobación de Luis`, y las 2 migraciones nuevas (`crm_settings`,
  `sales_commission_tiers`) no muestran revisión del Arquitecto (regla del proyecto).
- Jira: SCRUM-939 y SCRUM-945 siguen en `In Progress` (no se movió ningún ticket ni se comentó en Jira).

## Cómo se ejecutó (reproducible)

- Batería API (throttled, 1 s entre llamadas, con restauración en `finally`): 83 llamadas — gating de los 2 endpoints,
  validación CRM (22 casos) y tramos (8+3 casos), solapamiento, hueco, efecto `urgency=proximo`, Gerencia/Caja, audit.
- Navegador: `atlanticerp-frontend/e2e/preqa-scrum944-945-configuracion-global-20260923.spec.ts` (sin commitear), corrido con
  `PREQA_LOGIN_DIR=<carpeta con su.json/conta.json/alm.json>` para reutilizar la sesión sin loguear; se ejecutó con una
  config temporal sin `webServer` (ya eliminada). Resultado: 5 verdes (gating API/UI, Fiscal, 11 tabs, montaje solo de la
  tab activa, efecto real con 30 días) y 2 rojos deliberados (B en CRM y B+D en tramos) que reproducen los hallazgos.


---

# 3ª pasada — verificación de los fixes A, B, C y E (backend `38ce7ea`, frontend `95dc37f`)

**Fecha:** 2026-09-23 ~13:45–14:00 · **Estado: INCOMPLETA — cortada otra vez por pérdida de acceso a dev.**
Throttling aplicado: 0 logins nuevos (tokens reutilizados), 1.5 s entre requests, `/up` entre bloques, 70 llamadas HTTP.

## Veredicto parcial

| Bloque | Resultado |
|---|---|
| **A — guardado atómico de tramos** | **Verificado en runtime y limpio.** Ver tabla. |
| **C — freshness recent < stale** | **Verificado en runtime y limpio.** |
| **E — tab Global (backend)** | **Parcial:** listado, lista blanca, 404 y gating verificados; **rangos, escrituras válidas, invalidación de cache y audit NO ejecutados.** |
| **B — 422 visible en la UI** | **No verificado** (el navegador no llegó a correr; el spec actualizado está listo). |
| Regresión (11→12 tabs, Fiscal solo Mark, urgency=proximo, CxC neta) | **No verificada** en esta pasada (sí pasó limpia en la 2ª). |
| Spec e2e | **No ejecutado.** |
| **SCRUM-945** | **No puede darse por limpio: falta ejecutar E-rangos/escrituras, UI (B, E, A por fila) y la regresión.** |

## Incidente de acceso a dev (2ª vez hoy)

- ~13:55 `dev.atlanticerp.ai` dejó de responder a mitad del bloque E: el `PUT /admin/global-settings/audit_logs.retention_months`
  (value=11, que debía dar 422) terminó en timeout. Desde entonces `/up` da `000`, y **TCP 443 y 22 están sin respuesta desde esta IP**
  (14:00, dos comprobaciones puntuales, sin bucle), mientras `test.atlanticerp.ai` y `atlanticerp.ai` responden 200.
- Que también el puerto 22 esté cerrado apunta a un **bloqueo de IP a nivel de firewall** (CrowdSec/fail2ban de Epic 11) y no a una caída de
  la app; **no confirmado** (no hay forma de verlo desde fuera y sin SSH). Hipótesis del disparador: el patrón de la pasada produce muchas
  respuestas 4xx en poco tiempo (~45 de 401/403/404/405/422 en ~2 min), típico de un escenario `http-probing`/`http-crawl` — en la pasada
  anterior (que tuvo 83 llamadas, mayormente 403/422/404) no ocurrió, así que tampoco es concluyente.
- **Recomendación:** antes de una 4ª pasada, que alguien con acceso a la instancia confirme el motivo (`cscli decisions list`) y agregue esta
  IP (181.55.21.154) a la allowlist si corresponde; una pasada adversarial es por definición ruidosa en 4xx. No se insistió ni se reintentó en bucle.

## A — guardado atómico `PUT /api/ventas-diseno/sales-commission-tiers` (verificado)

| Escenario | Resultado |
|---|---|
| `POST /tiers` (retirado) · `PUT /tiers/1` · `DELETE /tiers/1` | **405 · 404 · 404** |
| Sin token (GET y PUT) | **401** exacto |
| `lider_admin_contab` y `lider_bodega` (GET y PUT) | **403** en los 4 |
| Solape 25 000–30 000 al 9 % | **422** `tiers`: "El tramo desde 25000.00 se solapa con el tramo desde 20000.00." (+ hueco) |
| Hueco: mínimo del último tramo → 200 000 | **422** "Hay un hueco entre … 119999.99 … 200000.00: el siguiente mínimo debe ser 120000.00." |
| Primer tramo != 0 (min=1) | **422** "El primer tramo debe empezar en 0." |
| Sin tramo abierto final | **422** "El último tramo debe ser abierto (sin monto máximo)." |
| Tramo abierto en medio | **422** "Solo el último tramo puede ser abierto…" |
| Ids repetidos | **422** en `tiers.0.id` y `tiers.1.id` (duplicado) |
| Id inexistente (999999) | **422** "El tramo con id 999999 no existe." |
| 20000.005 en mínimo y en máximo | **422** por fila `tiers.N.monto_minimo|monto_maximo` "debe tener 0-2 decimales" |
| Max < min · %>100 · %<0 · % no numérico · mínimo negativo · 51 filas | **422** (por fila `tiers.0.porcentaje`, etc.; 51 filas en `tiers`) |
| `tiers=[]` · sin clave `tiers` · `tiers='abc'` · fila sin `monto_maximo` | **422** los 4 |
| Tras los 20 payloads inválidos | Tabla **sin cambios** (leída). |
| PUT idempotente (mismos ids/valores) | 200; ids 1..6 y valores idénticos. |
| PUT válido 2 % → 2.5 % | 200; GET refleja el cambio, **ids 1..6 conservados**. |
| PUT estructural: parte el último tramo (120 000–199 999.99 · 200 000+ al 5.5 %, sin id) | 200; 7 tramos, ids 1..6 conservados + **id 8** nuevo (el 7 lo consumió la pasada anterior: el autoincrement no retrocede). |
| PUT de vuelta a los originales | 200; el tramo nuevo se elimina; ids 1..6 exactos. |
| Filas en orden inverso y `orden` basura (99, 98…) | 200; `orden` se ignora y queda 1..6 por mínimo. |
| Forma del error | `{message, errors}` con claves **por fila** (`tiers.N.campo`) y **generales** (`tiers`) — es lo que consume la UI. |

**Lo que sí funciona:** todo el contrato A. Con esto el hallazgo A queda **corregido**: ya no se puede dejar la tabla con solapes, huecos,
primer tramo distinto de 0, tramo abierto en medio ni sin tramo abierto final.

**Observaciones de A (BAJO/cosmético, no bloquean):**
1. **Redondeo silencioso de `porcentaje`:** enviar `4.555` responde 200 y guarda `4.56` (sin regla `decimal` como en los montos).
2. **Mensaje en inglés + ruta cruda:** el `message` general concatena el sufijo de Laravel "(and 1 more error)" sin traducir, y los mensajes por
   fila usan el atributo crudo ("El campo **tiers.0.porcentaje** no debe ser mayor que 100."). `summaryMessage()` muestra `message`, así que el
   usuario ve ese texto. Redacción/UX para QA (marly), no funcional.
3. La convención de contigüidad obliga a `mínimo = máximo anterior + 0.01` (ej. 19999.99 → 20000.00); no admite un esquema `0–20000 / 20000–35000`.
   Es coherente con lo sembrado, pero conviene documentarlo para quien edite.

## C — `freshness_recent_dias < freshness_stale_dias` (verificado)

| Escenario (persistido 7/21) | Resultado |
|---|---|
| `recent=60, stale=10` · `21/21` | **422** (el mensaje sale en ambos campos). |
| Solo `recent=30` (contra stale persistido 21) · solo `recent=21` | **422** |
| Solo `stale=7` (= recent persistido) · solo `stale=6` | **422** (compara contra el valor persistido del otro campo). |
| `crm_settings` tras los 422 | intactos 3/7/21. |
| Solo `recent=20` (< stale 21) | 200 (válido). |
| Solo `stale=8` estando `recent=20` | 422 — **correcto** (20 ≥ 8); fue un error de mi caso de prueba, no de la app. |
| Regresión: `entrega_proxima_dias` 0, -1, 366, 'abc', 2.5 | 422 los 5; anónimo 401; Admin&Cont 403. |

Hallazgo C **corregido**.

## E — `GET/PUT /api/admin/global-settings` (parcial)

Verificado: listado con **3 claves** exactas (`documents.max_size_mb` 500 [1–500], `notifications.archive_read_after_days` 30 [1–365],
`audit_logs.retention_months` 12 [**12**–120]); `anthropic_api_key` **no** aparece; `label_key` con el formato
`globalSettings.globalTab.labels.<clave con "_">` y **existen** en `es/common.json` y `en/common.json` (los 3, por lectura del código);
`PUT anthropic_api_key` → **404**, clave inexistente → **404**, `share_links_expiration_days` (fuera de lista) → **404**;
anónimo **401** (GET y PUT); `lider_admin_contab` y `lider_bodega` **403** (GET y PUT).

**No ejecutado por el corte:** rangos (retention 11/0/-1/121/12.5/'abc'/''/null; max_size 0/501/1.5; archivo 0/366), escrituras válidas y
su lectura de vuelta, coherencia con `/settings/documents/max-size`, `audit_logs` con `updated_by`/usuario, y la UI (lazy-load, ES/EN sin
claves crudas, rango local). **Invalidación de cache:** no es verificable en runtime sin subir documentos a dev (el límite se lee de cache en
`DocumentController`); queda cubierta solo por `GlobalSettingsControllerTest::test_update_persiste_invalida_cache_y_deja_audit_log`.
`updated_by` no se expone por la API: solo se puede comprobar por el audit (usuario) o por BD.

## Restauración en dev — ESTADO REAL (no verificable por lectura)

- **`crm_settings`:** la última escritura que se completó fue `PUT 7/21` (200); las escrituras posteriores fueron todas 422/403 (no escriben).
  `entrega_proxima_dias` nunca se modificó en esta pasada. Debe estar en **3/7/21**, pero **no pude releerlo**.
- **Tramos:** la última escritura fue la vuelta a la tabla original tras la prueba de `porcentaje=4.555` (respuesta no comprobada; el `/up`
  posterior dio 200). **Riesgo residual:** si esa última llamada hubiera fallado, el tramo 5 quedaría en **4.56** en vez de 4.50.
  Todas las restauraciones anteriores del mismo tipo devolvieron 200 y verificaron ids/valores originales, así que lo más probable es que
  esté correcto, pero **no está verificado**.
- **Claves globales:** **no se escribió ninguna** (todas las llamadas de E hasta el corte fueron 401/403/404, y la del corte era un 422 esperado).
  Siguen en 500 / 30 / 12 por defecto (y por lectura al inicio).
- **Pendiente obligatorio al recuperar el acceso:** leer `crm/settings`, `sales-commission-tiers` y `admin/global-settings` y compararlos con
  `originals_pass3.json` (líneas base guardadas), antes de cualquier otra escritura. `run945c.py` lo hace al final.

## Qué falta

1. **Recuperar el acceso a dev** (ver recomendación arriba) y **verificar la restauración** por lectura.
2. Ejecutar `run945c.py` (E-rangos, escrituras válidas + restauración, regresión Fiscal/urgency/CxC 13 982.33, audit) — script reanudable.
3. Ejecutar el spec e2e (`preqa-scrum944-945-configuracion-global-20260923.spec.ts`, ya actualizado: `afterAll` migrado al PUT atómico y a las
   claves globales; +5 tests para A por fila/hueco, C en UI, tab Global lazy-load/ES-EN/rango local/guardado, gating). Los 2 tests de 422 de
   la pasada anterior deberían estar en verde ahora; **no se corrieron**.
4. Recién con eso: pasada limpia y concluyente → SCRUM-945 puede ir a QA. **SCRUM-939 no depende de nada de esto** y ya está limpio
   (2ª pasada): puede pasar a QA cuando quien haga la transición cree los marcadores (`~/.claude-preqa-markers/SCRUM-939` y
   `~/.claude-visual-review-markers/SCRUM-939`, Visual Review N/A por no tener mockup).


---

# 4ª pasada — continuación de SCRUM-945 (bloque E) con throttling de 3 s

**Fecha:** 2026-09-23 14:39–14:44 · **Estado: INCOMPLETA — 3er corte de acceso a dev.** 33 llamadas HTTP, 3 s entre llamadas,
casos inválidos en grupos de 3–5 con `/up` entre grupos, 0 logins nuevos.

## Paso 1 — línea base leída ANTES de escribir (14:40) ✅

| Dato | Leído | == original |
|---|---|---|
| `crm_settings` | 3 / 7 / 21 | ✅ |
| Tramos (ids 1..6) | 0–19 999.99 1.50 · 20 000–34 999.99 2.00 · 35 000–59 999.99 3.00 · 60 000–79 999.99 4.00 · 80 000–119 999.99 4.50 · 120 000+ abierto 5.00, órdenes 1..6 | ✅ |
| Claves globales | `documents.max_size_mb` 500 · `notifications.archive_read_after_days` 30 · `audit_logs.retention_months` 12 | ✅ |
| Contra `originals_pass3.json` | crm, tramos y claves idénticos | ✅ |

Con esto queda **descartado el riesgo residual** de la 3ª pasada (el tramo 5 estaba en **4.50**, no en 4.56): la restauración anterior sí había aplicado.

## Bloque E — backend (ahora verificado, salvo audit)

| Escenario | Resultado |
|---|---|
| `audit_logs.retention_months` = 11 · 0 | **422** "debe ser al menos 12" (mínimo 12 respetado) |
| `audit_logs.retention_months` = 121 | **422** "no debe ser mayor que 120" |
| `audit_logs.retention_months` = 12.5 | **422** "debe ser un número entero" |
| `audit_logs.retention_months` = 'abc' | **422** (dos mensajes: "número entero" y "al menos 12") |
| `documents.max_size_mb` = 0 · 501 · 1.5 · 'abc' · sin `value` | **422** los 5 |
| `notifications.archive_read_after_days` = 0 · 366 · 'x' | **422** los 3 |
| Valores globales tras los 13 rechazos | intactos (500/30/12) |
| Escritura válida `notifications.archive_read_after_days` 30→31→30 | 200; `GET` refleja 31; restaurado (200) |
| Escritura válida `documents.max_size_mb` 500→499→500 | 200; `GET /admin/global-settings` = 499; **`GET /settings/documents/max-size` (endpoint bespoke) = 499** → mismo storage; vuelve a 500 |
| Escritura válida `audit_logs.retention_months` 12→13→12 | 200; `GET` refleja 13; vuelve a 12 y `min=12` (borde válido) |
| Anónimo `GET /admin/global-settings` | **401** |
| Admin&Cont `GET` · Bodega `PUT` | **403** · **403** |
| `PUT anthropic_api_key` · clave inexistente | **404** · **404** (lista blanca) |

Formato de los 422: `{message, errors:{value:[…]}}` en español, clave `value` — consumible por la UI.

**Sigue sin ejecutarse** (el corte llegó justo aquí): (a) lectura de `audit_logs` para confirmar la fila de cada escritura con usuario
(`updated_by` no se expone por API; solo se puede confirmar por el audit o BD); (b) regresión: Fiscal solo Mark, `urgency=proximo`
3→30→3 y CxC neta 13 982.33 (sí pasaron limpias en la 2ª pasada, **no** se reconfirmaron tras los fixes); (c) **todo el bloque de UI/e2e**
(hallazgo B en CRM/Tramos, tab Global lazy-load/ES-EN, error por fila); (d) invalidación de cache (solo test unitario).

## Restauración

- Escrituras de esta pasada, todas con retorno a la línea base **confirmado**: `notifications` 31→30 (200), `documents` 499→500 (200 y
  releído **500** por el endpoint bespoke), `retention` 13→12 (200 y releído **12** con `min=12`).
- Después de esas tres restauraciones **no se hizo ninguna escritura exitosa** (solo 401/403/404).
- `crm_settings` y los tramos **no se escribieron** en esta pasada (solo lectura al inicio: idénticos a los originales).
- ⚠️ **Lectura final completa NO realizada** (dev cayó antes): el estado esperado es el de la línea base, con evidencia sólida (cada
  restauración devolvió 200 y las relecturas parciales coinciden) pero **no verificado por una lectura de cierre de las 3 fuentes**.
  Nota: las claves globales ahora tienen fila en `global_settings` con el mismo valor del default (antes podían no existir); no cambia el valor efectivo.

## Sobre los cortes de acceso a dev (3 en el día) — análisis

| Corte | Pérdida | Vuelta | Duración | Qué se había hecho justo antes |
|---|---|---|---|---|
| 1 | ~12:15 | ~12:52 | ~38 min | 3 logins, descargas Excel, 401/403/422 puntuales, 1er PDF |
| 2 | ~13:55 | ~14:39 | ~44 min | 70 llamadas a 1.5 s (≈45 respuestas 4xx); último: 3× 404 seguidos (`/tiers/1`, anthropic_api_key, no.existe, share_links…) |
| 3 | ~14:43 | (sin volver a las 14:44) | — | 33 llamadas a 3 s (16× 422, 2× 403, 1× 401); último: 2× 404 seguidos |

- **Patrón consistente:** cada corte ocurre justo después de una racha de **404** en rutas de `/api/admin/*` o de tramos, y todos duran ~40 min.
  La pasada 2 (83 llamadas, más 4xx en total, solo 2 de 404 espaciados) **no** cortó. Hipótesis: un escenario de CrowdSec de tipo
  `http-probing` (cuenta 404/403 por IP) o similar, con baneo temporal, y/o que el ban se dispare por 404 y no por 422.
  Con el ritmo de 3 s también se cortó, así que **bajar el ritmo no basta; lo que parece contar es el número de 404/403 consecutivos**.
- **No confirmado**: no hay SSH. Distinguir "IP bloqueada" de "instancia con problema" requiere mirar `cscli decisions list` o el
  estado de la EC2. Que test/prod (misma cuenta) respondan y que el puerto 22 también caiga apuntan a bloqueo por IP a nivel de firewall.
- **Acción recomendada (para quien tenga acceso):** (1) `cscli decisions list` / `nft list ruleset` en dev; (2) allowlist de la IP de Pre-QA
  (181.55.21.154) en CrowdSec/fail2ban durante la pasada; (3) mientras tanto, Pre-QA evitará provocar 404/403 en dev (los checks de
  "ruta retirada", "clave fuera de lista" y "rol sin permiso" se agrupan en UNA sola tanda corta al final o se cubren con el test unitario).

## Veredicto de SCRUM-945 (a las 14:44)

**Todavía NO limpio.** Verificado y limpio: **A** (tramos atómicos), **C** (freshness) y **E en backend** (rangos, escrituras, lista blanca,
gating, coherencia con el endpoint bespoke). Pendiente de ejecutar: **B en UI**, **E en UI** (lazy-load, ES/EN sin claves crudas), error por
fila en la UI, audit_logs y la regresión. Sin hallazgos **nuevos** con severidad en esta pasada. Hallazgos abiertos previos: solo los BAJO/cosméticos
de A (redondeo silencioso de `porcentaje` 4.555→4.56; "(and 1 more error)" en inglés y rutas crudas `tiers.0.porcentaje` en los mensajes).

## Qué falta

1. Que dev vuelva (y, idealmente, allowlist de la IP de Pre-QA). 2. **Lectura de cierre** de `crm/settings`, tramos y `admin/global-settings`
contra la línea base. 3. `run945d.py` (retoma en E5, regresión, audit — ya escrito, con 3 s y `/up` entre grupos) y el spec e2e
(`preqa-scrum944-945-…spec.ts`, con `afterAll` atómico y +5 tests). 4. Con pasada limpia y concluyente: SCRUM-945 a QA. **SCRUM-939** no depende
de esto y ya está limpio.


---

# 5ª pasada — cierre de SCRUM-945 (fixes A, B, C y E, backend `38ce7ea` / frontend `95dc37f`) — PASADA LIMPIA

**Fecha:** 2026-09-23 15:42–15:52 · **Sin cortes de acceso.** CrowdSec (`http-admin-interface-probing`) ya está en simulación en dev y la IP de
Pre-QA habilitada. Ritmo 2–3 s entre llamadas, **0 logins nuevos** (sesión reutilizada), `/up` entre bloques. 35 llamadas API + spec e2e (13 tests).

## Veredicto: SCRUM-945/944 → **pasada limpia y concluyente**. Sin hallazgos CRÍTICO ni MEDIO abiertos.

## Paso 1 — línea base leída antes de escribir (15:42) ✅
`crm_settings` 3/7/21 · 6 tramos con ids 1–6 (0–19 999.99 1.50 · 20 000–34 999.99 2.00 · 35 000–59 999.99 3.00 · 60 000–79 999.99 4.00 ·
80 000–119 999.99 4.50 · 120 000+ abierto 5.00) · claves 500/30/12 — **todo idéntico a los originales**, nada que restaurar.

## Resultados por hallazgo

| Hallazgo | Estado | Evidencia |
|---|---|---|
| **A** (tramos sin validar cobertura) | **Corregido y verificado** | Pasada 3: 20 payloads inválidos → 422 (solape, hueco, primer≠0, sin abierto final, abierto en medio, ids repetidos/inexistentes, 20000.005, lista vacía, etc.); ida y vuelta válidos conservan ids 1–6; `POST` 405, `PUT/DELETE /{id}` 404. Hoy: UI real — el hueco (mínimo del último tramo → 200 000) muestra "Hay un hueco entre el tramo que termina en 119999.99 y … el siguiente mínimo debe ser 120000.00" y **Cancelar** deja todo intacto; error por fila con el campo en rojo. |
| **B** (422 silencioso) | **Corregido y verificado en la UI real** | CRM con 0 → campo en rojo + "El campo entrega proxima dias debe ser al menos 1."; Tramos → mensaje visible tras el 422 (capturas en `e2e/__screenshots__/scrum945-*.png`). Los 2 tests que estaban en rojo a propósito **ahora pasan**. |
| **C** (recent < stale) | **Corregido y verificado** | 60/10, 21/21, y con un solo campo contra el persistido → 422 en API; en la UI recent=60/stale=10 muestra el rechazo. |
| **E** (`GlobalSetting` en la vista) | **Corregido y verificado** (backend + UI) | Ver tabla E. |
| D (voseo) | **Corregido en estos textos** | El confirm de guardar de tramos y los mensajes nuevos usan tuteo ("¿Confirmas guardar los tramos?"); el spec verifica que no aparezca "Confirmás". El voseo previo en otras ~20 cadenas del sistema no es de este ticket. |

### E — tab Global (12ª)

| Escenario | Resultado |
|---|---|
| `GET /admin/global-settings` | 3 claves exactas; sin `anthropic_api_key`; `label_key` correctos |
| Rangos (`retention_months` 11/0/121/12.5/'abc'; `max_size_mb` 0/501/1.5/'abc'/sin value; `archive_read_after_days` 0/366/'x') | **422** los 13; valores intactos |
| **`audit_logs.retention_months` mínimo 12** | 11 y 0 → 422 "al menos 12"; 12 aceptado (borde); 13 → 200 y vuelta a 12 |
| Escrituras válidas (30→31→30, 500→499→500, 12→13→12) | 200; GET refleja; **`/settings/documents/max-size` (bespoke) ve el mismo 499** → mismo storage |
| **audit_logs** | 6 filas `settings.updated` (una por escritura), `old_values → new_values`, **todas atribuidas a un usuario**; `crm_setting.updated` atribuidas; **`sales_commission_tiers.replaced`: UNA fila por reemplazo** (con el conjunto completo antes/después, "→ 6 tramos"/"7 tramos") |
| Gating | anónimo **401**; Admin&Cont y Bodega **403** (GET y PUT); `anthropic_api_key`, `no.existe`, `share_links_expiration_days` → **404** |
| UI: lazy-load | `/api/admin/global-settings` **no se pide** hasta abrir la tab (conteo de requests) |
| UI: i18n | labels en ES ("Tamaño máximo de documento (MB)"…) y EN ("Maximum document size (MB)"…), **sin claves crudas** (regex sobre el texto) |
| UI: rango local | retención 11 → "…entre 12 y 120" sin request de escritura; guardado válido → 200 |

### Regresión de lo que ya pasó limpio ✅
12 tabs (11→12), cada una monta sin pantalla en blanco ni errores JS · solo la tab activa monta su panel · **Fiscal solo Mark** (Admin&Cont y Bodega 403, superadmin 200 por bypass, y la UI no muestra el formulario a Admin&Cont) ·
**`urgency=proximo`** 3 d → 2 proyectos · 30 d → 4 · vuelta a 3 d → 2 (API y UI) · **CxC neta de Gerencia 13 982.33** (no el bruto 14 032.33) · las 10 pruebas de gating (403/401) de `/crm/settings` y tramos.

### Spec e2e `preqa-scrum944-945-configuracion-global-20260923.spec.ts`: **13/13 en verde**
Ajustes hechos al spec durante la pasada (no a la app): (1) el test de gating usaba `POST` a tramos esperando 403, pero esa ruta se retiró y
responde 405 → ahora usa `PUT`; (2) el `afterAll` restaura con el PUT atómico y las claves globales; (3) **se agregó guardar/restaurar la
preferencia de idioma** (ver más abajo); (4) pausa entre llamadas API subida a 3 s.

## Hallazgo nuevo de esta pasada (efecto lateral de MI test, corregido) — no es un defecto de la app
El toggle ES/EN del TopBar **persiste la preferencia del usuario en el backend** (`PATCH /api/settings/preferences`). Mi test de la tab Global pulsó EN y
luego ES y dejó la preferencia de tu usuario en **`en`** (antes `es`). Lo detecté por una inconsistencia en una captura (toggle en EN con texto en
español), lo restauré con `PATCH language=es` y lo verifiqué por lectura (`es`). El spec ahora captura el idioma original, espera el `PATCH` al volver
a ES y lo restaura en `afterAll`; se repitió ese test y el idioma quedó en `es`. **Nadie más resultó afectado** (solo el usuario superadmin de la prueba).

## Observaciones que siguen abiertas (BAJO / cosmético, no bloquean QA)
1. **Ruta cruda en el error por fila:** "El campo **tiers.0.porcentaje** no debe ser mayor que 100." (visible en la UI, `scrum945-tramo-error-por-fila.png`).
   Falta un `attributes()` en `ReplaceSalesCommissionTiersRequest` (o mapear la ruta en el front). Redacción/UX para QA.
2. **`porcentaje` con 3 decimales se redondea en silencio** (4.555 → 4.56); los montos sí rechazan >2 decimales.
3. **Sufijo "(and N more error)" en inglés** en el `message` general del 422 (no se ve en la UI actual porque muestra el mensaje del campo; sí lo verá cualquier otro consumidor de la API).
4. La convención de contigüidad exige `mínimo = máximo anterior + 0.01` (documentar para quien edite).
5. **No verificable en runtime:** el efecto de un tramo sobre la comisión real (`/ventas-diseno/reports/commissions` vacío en dev, no hay tarjetas aprobadas),
   la invalidación de cache de `documents.max_size_mb` sin subir documentos, y la vista de Fiscal con la cuenta de Mark (no se usó su login). Los cubre la suite de backend (`GlobalSettingsControllerTest`, tests de `percentFor`).
6. `ip_address` es `null` en `audit_logs` de todos los módulos (no propio de estos cambios; contrastar con REQ-633).
7. Las claves globales ahora tienen fila propia en BD con el valor default (no cambia el valor efectivo).

## Restauración final ✅ (lectura de cierre, 15:51)
- `crm_settings` **3/7/21** ✅ · tramos **ids 1–6, 0–19 999.99 1.50 / 20 000–34 999.99 2.00 / 35 000–59 999.99 3.00 / 60 000–79 999.99 4.00 / 80 000–119 999.99 4.50 / 120 000+ abierto 5.00, órdenes 1–6** ✅ ·
  claves globales **500 / 30 / 12** ✅ · preferencia de idioma del usuario de prueba **`es`** ✅.
- Quedan en `audit_logs` las filas de las escrituras y restauraciones (inmutable, esperado).

## Qué falta para pasar a QA
- **SCRUM-945:** nada bloqueante desde Pre-QA — pasada limpia y concluyente, Visual Review N/A (sin mockup). Pendientes **fuera de Pre-QA** que siguen abiertos: aprobación de Luis de la spec
  (`docs/specs/auditoria-valores-parametricos-superadmin.md`, sigue en `Draft`) y revisión del Arquitecto de las 2 migraciones (`crm_settings`,
  `sales_commission_tiers`); decidir si las observaciones 1–3 se corrigen antes de QA (redacción que marly probablemente marque) o se abren como tickets.
- **SCRUM-939:** ya limpio desde la 2ª pasada; sin cambios.
- **Marcadores locales del gate Jira** (`~/.claude-preqa-markers/{SCRUM-939,SCRUM-945}` y `~/.claude-visual-review-markers/{…}`): **no creados** (no se movió ningún ticket); los crea quien haga la transición.
- **Spec e2e:** listo para promover a `atlanticerp-frontend/e2e/` como test permanente (gating superadmin, 422 visible, tab Global). Ya está commiteado en `95dc37f`; los cambios de esta pasada (afterAll atómico, idioma, `PUT`) quedan **sin commitear**.


---

# 6ª pasada (re-check acotado) — las 3 correcciones BAJAS de SCRUM-945 (backend `03442f5`)

**Fecha:** 2026-09-23 16:13–16:20 · **Alcance:** solo las 3 observaciones BAJAS de la 5ª pasada + regresión rápida (no se repitió el Pre-QA completo).
Ritmo 2.5 s entre llamadas, 1 sola sesión (0 logins nuevos), `/up` entre bloques, 33 llamadas API + 2 tests de UI. **Sin cortes de acceso.**

## Veredicto: **las 3 correcciones BAJAS quedan verificadas. SCRUM-945 sigue en pasada limpia.** Sin hallazgos nuevos.

Línea base leída antes de escribir (16:13): `crm_settings` 3/7/21 · 6 tramos ids 1–6 originales · claves 500/30/12 · idioma `es` — todo idéntico, nada que restaurar.

| # | Corrección | Resultado |
|---|---|---|
| 1 | **Mensaje legible por fila, sin ruta cruda** | `porcentaje` 101 y −1 en la fila 3 → **422**; `errors` conserva la clave **`tiers.2.porcentaje`** y su mensaje es "El campo **porcentaje (fila 3)** no debe ser mayor que 100." / "…debe ser al menos 0."; el `message` general dice lo mismo; **la ruta `tiers.2.porcentaje` ya no aparece dentro de ningún mensaje**. Hueco → "Hay un hueco entre el **tramo** que termina en 119999.99 y el **tramo** que empieza en 200000.00: el siguiente mínimo debe ser 120000.00." · solape → "El **tramo** desde 25000.00 se solapa con el **tramo** desde 20000.00." (mensajes de conjunto bajo la clave `tiers`). |
| 2 | **`porcentaje` con 3 decimales** | `4.555` → **422** en `tiers.4.porcentaje` ("El campo porcentaje (fila 5) debe tener 0-2 decimales."), tabla intacta; `4.55` → **200** y se guarda exactamente 4.55; restaurado a 4.50. (Antes se redondeaba en silencio a 4.56.) |
| 3 | **Sufijo en español** | CRM `recent=60, stale=10` (2 campos): `message` = "Los días de actividad "reciente" (60) deben ser menores que… "frío" (10). **(y 1 error más)**". Tramos con 2 filas inválidas: "El campo porcentaje (fila 1) no debe ser mayor que 100. **(y 1 error más)**". **Ya no aparece "(and N more error)"**. |
| 4 | **UI real: error por fila legible** | El test del panel de Tramos (ahora con la aserción `toContainText('porcentaje (fila 1)')` y `not.toContainText('tiers.0')`) **pasa**; captura `e2e/__screenshots__/scrum945-tramo-error-por-fila-legible.png`: campo en rojo y debajo "El campo porcentaje (fila 1) no debe ser mayor que 100." También pasa el test del hueco. |
| 5 | **Regresión rápida** | PUT atómico idempotente 200 (ids/valores idénticos); 2 % → 2.5 % → vuelta: ids 1–6 conservados; `POST /tiers` **405**, `PUT /tiers/1` **404**, `DELETE /tiers/1` **404**, `PUT` sin token **401**. |

**Nota sobre el único "FAIL" del script:** fue un error de mi aserción (exigía la palabra plural "errores más"; el mensaje real es "(y 1 error más)", correcto en singular). No es un defecto de la app; con 2+ errores adicionales saldría "(y N errores más)" (no probado con N>1 en runtime).

## Observaciones (sin cambio)
- Siguen sin verificarse en runtime (no eran de esta pasada): el efecto de un tramo sobre la comisión real, la invalidación de cache de `documents.max_size_mb`, y Fiscal con la cuenta de Mark.
- Contigüidad `mínimo = máximo anterior + 0.01` y `ip_address` nulo en `audit_logs`: sin cambios (ver 5ª pasada).

## Restauración ✅ (lectura de cierre, 16:19)
`crm_settings` **3/7/21** · tramos **ids 1–6: 0–19 999.99 1.50 / 20 000–34 999.99 2.00 / 35 000–59 999.99 3.00 / 60 000–79 999.99 4.00 / 80 000–119 999.99 4.50 / 120 000+ abierto 5.00** (órdenes 1–6) ·
claves globales **500 / 30 / 12** · idioma del usuario **`es`**. Queda en `audit_logs` el rastro de las escrituras y restauraciones (inmutable).

## Qué falta
Sin cambios respecto a la 5ª pasada: SCRUM-945 no tiene bloqueantes de Pre-QA; siguen pendientes **fuera de Pre-QA** la aprobación de la spec por Luis y la revisión del Arquitecto de las 2 migraciones. Marcadores del gate Jira: no creados (no se movió ningún ticket).
El spec e2e tiene cambios **sin commitear** (aserción de texto legible en el test de error por fila; `afterAll` atómico + idioma de la 5ª pasada).
