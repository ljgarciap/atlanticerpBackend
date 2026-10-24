# Threat Model — REQ-635 (Alertas activas de patrones económicos, Bodega + Admin&Cont)

**Date:** 2026-09-01
**Owner:** Cybersecurity
**Status:** Gate obligatorio antes de que PM desglose REQ-635 en tareas — ver
`docs/architecture/bodega-telemetria-fraude-diseno.md` sección 5 y "Decisiones del CTO" punto 3.
**Ticket:** SCRUM-811. Épica: SCRUM-788.
**Inputs de origen:** `docs/architecture/bodega-telemetria-fraude-diseno.md` (REQ-635, secciones 2/3),
código real: `app/Modules/Auth/Jobs/SecurityAnalysisJob.php`, `app/Modules/Auth/Models/SecurityAnomaly.php`,
`app/Modules/Bodega/Services/BodegaSettingsService.php`, `app/Modules/Bodega/Models/{AdjustmentRequest,
AdjustmentRequestLine,RelocationRequest}.php`, `app/Modules/AdminCont/Models/Payment.php`, y **datos
históricos reales de producción** (`atlanticerp.ai`, consulta de solo lectura autorizada 2026-09-01, rol
`atlanticerp_app`, ver evidencia inline por hallazgo).

⚠️ **Nota importante sobre este documento:** SCRUM-811 en Jira lo daba por completado antes de que
existiera — no había ningún archivo en `docs/security/` con este nombre, ni en el working tree ni en
el historial de git. Corregido 2026-09-01 (ver también corrección de la referencia igualmente
inexistente a `THREAT-MODEL-atlanticerp.md` en `atlanticerp/CLAUDE.md`, sección "Equipo de agentes").

---

## Resumen del cambio

REQ-635 extiende `SecurityAnalysisJob` (Epic 11) — hoy enfocado en Auth/CRM (sesiones, uploads
públicos) — con reglas determinísticas (no requieren IA, mismo patrón que `flagPublicUploads()`)
sobre eventos de Bodega y Admin&Cont: ajustes de inventario grandes, ajustes fuera de horario,
correlación ajuste+reubicación del mismo producto por el mismo actor en ventana corta, IP nueva
para un usuario, montos de pago/comisión fuera de rango, aprobaciones repetidas. Reusa
`security_anomalies` (confirm/dismiss por superadmin) — nunca bloquea nada automáticamente.

## Dato que condiciona todo el resto de este documento: el volumen real de datos es mínimo

Verificado por consulta directa a producción (no una suposición):

- **Bodega — ajustes de inventario:** 11 filas en `adjustment_request_lines`, generadas en 4 días
  (2026-08-26 a 2026-08-30), **todas por un único actor** (`solicitado_por = 16`). `abs(cantidad)`:
  min 1, avg 3.1, p95 4.5, max 5.
- **Bodega — reubicaciones:** **1 sola fila** en `relocation_requests` (2026-08-26 16:31, mismo
  actor 16, producto `catalog_product_id=11664`, cantidad 10). Coincide con que ese mismo producto
  tuvo un ajuste 4 minutos antes (16:27) — es el único ejemplo real disponible del patrón que
  REQ-635 quiere detectar, y probablemente sea trabajo legítimo de setup/prueba, no fraude.
- **Bodega — stock por SKU:** `product_warehouse_stock` con `quantity > 0` tiene solo 6 filas
  (avg 13.2 unidades, p90 30.5, max 31) — universo de productos con stock real todavía chico.
- **Admin&Cont — pagos/comisiones:** **cero filas** en `payments`, `architect_commissions`,
  `commission_month_snapshots`, `payment_confirmations`. No hay ningún dato real para calibrar un
  monto de "pago fuera de rango".
- **IP por usuario:** 2 actores (`id 10`, `id 16`) con 2 IPs distintas cada uno en los eventos de
  Bodega — patrón normal de rotación de IP (casa/oficina o red móvil), no algo anómalo.

**Consecuencia directa:** el ticket (y el diseño que lo origina) pide "umbrales basados en datos
reales de ajustes históricos" — pero con n=11 en Bodega y n=0 en Admin&Cont, **no existe suficiente
historial para derivar un umbral estadísticamente significativo**. Lo que sigue son umbrales
iniciales conservadores, justificados con el poco dato real disponible más juicio de dominio —
explícitamente marcados para revisión una vez que haya más volumen, no una calibración definitiva.
Esto no es una razón para no implementar el mecanismo (la infraestructura de reglas + tabla
paramétrica + panel de alertas tiene valor por sí sola, y cada umbral es ajustable sin deploy) — es
una razón para no prometer precisión que los datos no sustentan todavía.

---

## Superficie de ataque nueva

Ninguna — REQ-635 es puramente de **detección** (lectura de tablas ya existentes, escritura solo en
`security_anomalies`, ya cubierta por Epic 11). No expone ningún endpoint nuevo de escritura, no
cambia ningún flujo de negocio, y no bloquea ninguna acción. El único riesgo real no es de
seguridad ofensiva sino operacional: **ruido**. Umbrales mal calibrados generan alert fatigue
(demasiadas alertas → el superadmin deja de revisarlas) o falsos negativos (umbral tan laxo que
nunca dispara). Los hallazgos de abajo están orientados a ese riesgo.

---

## Hallazgos

### 1. Un umbral de "ajuste grande" ingenuo (ej. hardcodear 100 unidades porque "suena razonable") sería inútil dado el stock real
**Severidad: MEDIA (calidad de la detección, no de seguridad)**

Con stock típico por SKU de ~13 unidades (p90=30.5, max observado=31) y ajustes reales de 1-5
unidades, un umbral absoluto alto (lo que alguien sin ver los datos reales propondría "a ojo") jamás
dispararía — la regla quedaría muerta desde el día 1.

**Mitigación / umbral propuesto:** `bodega_settings.fraude_ajuste_grande_unidades`, **default 10**
(2x el máximo histórico observado, ~75% del stock p90 de un SKU típico) — mismo patrón
`KEY_`/`DEFAULT_` + `intSetting()` que los 6 umbrales ya existentes en `BodegaSettingsService`
(ver `capacidadConcentracionPct()` como precedente directo). Se dispara por **línea de ajuste**
(`abs(cantidad) >= umbral`), no por request completo (un `AdjustmentRequest` puede tener varias
líneas). Explícitamente conservador — revisar en 60-90 días con volumen real de producción.

### 2. "Fuera de horario laboral" con una ventana ingenua (ej. 8am-6pm) generaría alert fatigue inmediato — los datos reales lo demuestran
**Severidad: MEDIA**

Los 11 ajustes reales ocurrieron en horarios de **16:04 a 22:27**, en miércoles, jueves, viernes Y
**domingo** — trabajo legítimo de setup/validación fuera de un horario de oficina estricto. Una
regla "fuera de 8am-6pm" habría marcado **9 de los 11 eventos reales** como sospechosos — puro
ruido, y el superadmin aprende rápido a ignorar el panel de alertas si la señal:ruido es tan mala
desde el arranque.

**Mitigación / umbral propuesto:** en vez de una ventana de "horario de oficina", limitar la regla
a **madrugada** — el patrón que sí es consistente con fraude real (nadie hace trabajo legítimo de
inventario a las 3am, pero sí es plausible que alguien evite ser visto en ese horario).
`bodega_settings.fraude_horario_inicio_hora` (default **0**) y
`fraude_horario_fin_hora` (default **5**) — ventana `[00:00, 05:00)` local del tenant
(`America/Panama`, mismo TZ que ya usa `TechnicianStatusService`). No habría disparado con ninguno
de los 11 eventos reales — ruido cero hoy, cobertura real para el patrón que sí importa.

### 3. Correlación "mismo actor, mismo producto, ajuste + reubicación en ventana corta" — el único ejemplo real disponible valida un umbral de 60 minutos
**Severidad: BAJA (el mecanismo es sólido, el umbral es la única incógnita)**

El único caso real (`producto 11664`, ajuste 16:27 → reubicación 16:31, mismo actor) tiene un gap
de **4 minutos**. Una ventana de 60 minutos lo habría capturado con margen amplio, sin ser tan floja
como para correlacionar eventos del mismo día sin relación real entre sí.

**Mitigación / umbral propuesto:** `bodega_settings.fraude_ventana_correlacion_minutos`, **default
60**. Regla: mismo `solicitado_por` + mismo `catalog_product_id` en un `AdjustmentRequest` (o su
línea) y un `RelocationRequest` cuyos `created_at` estén a ≤60 min uno del otro, en cualquier orden
(ajuste→reubicación o reubicación→ajuste, ambos igual de relevantes). Nota para AI Architect/Backend
Dev: esto es una consulta de correlación entre dos tablas por actor+producto+ventana de tiempo, no
un solo evento — no encaja en el patrón "un evento dispara una regla" de `flagPublicUploads()`, va a
necesitar su propio método `buildBodegaCorrelationFindings()` con un JOIN/subquery explícito.

### 4. "IP no vista antes" tiene un problema de arranque en frío (bootstrap) — con 0 historial previo, TODO usuario nuevo dispararía en su primera acción
**Severidad: MEDIA (falso positivo garantizado si no se corrige en diseño)**

Los datos reales confirman el patrón esperado (2 IPs por usuario, rotación normal), pero también
confirman el riesgo: si la regla es literalmente "esta IP nunca apareció antes para este usuario",
**la primera acción de cualquier usuario en la vida del sistema siempre "nunca apareció antes"** —
dispararía al 100% en el primer uso de cada persona, sin excepción, para siempre.

**Mitigación (mecanismo, no solo umbral):** la regla solo se evalúa si el usuario ya tiene **al
menos 1 evento previo** en `audit_logs` con una IP distinta a la del evento actual dentro de una
ventana de lookback — `auth_system_settings` (o el mismo `bodega_settings`, a decidir con AI
Architect/Backend Dev al implementar) `fraude_ip_lookback_dias`, **default 90**. Si el usuario no
tiene historial previo dentro de esa ventana (usuario nuevo, o usuario inactivo hace tiempo que
recién vuelve), la regla **no se evalúa** — evita el falso positivo de arranque en frío sin
necesitar una lista blanca manual.

### 5. Admin&Cont — "monto de pago/comisión fuera de rango" no tiene NINGÚN dato real para calibrar. No propongo una cifra en dólares — decisión real pendiente
**Severidad: sin calificar — requiere decisión de Luis, no la resuelvo yo**

A diferencia de Bodega (datos reales, aunque escasos), Admin&Cont tiene **cero** pagos, comisiones
o confirmaciones registradas en producción. Cualquier cifra en dólares que yo proponga ("alertar
pagos sobre $50,000", por ejemplo) sería una adivinanza sin ningún dato que la respalde — y el
tamaño típico de un proyecto de Atlantic (diseño/suministro de iluminación para
arquitectos/developers) no es algo que pueda inferir del código.

**Recomendación de diseño (evita el problema en vez de adivinar un número):** en lugar de un monto
fijo en dólares, usar un umbral **auto-calibrado por desviación estándar** sobre el propio historial
del tenant: alertar un pago/comisión que esté a más de **N desviaciones estándar** (parámetro
configurable, `admin_cont_settings.fraude_monto_desviaciones`, **default 3**) por encima de la media
móvil de los últimos `fraude_monto_ventana_dias` (default 90) días de esa misma tabla. Con menos de
~10-20 registros en la ventana, la regla simplemente no se evalúa (desviación estándar no
significativa con tan pocos puntos) — se activa sola a medida que haya suficiente historial, sin
necesitar un segundo deploy ni una decisión de Luis hoy sobre un número que nadie tiene forma de
justificar todavía. **Punto para Luis:** si preferís un techo fijo en dólares en vez de (o además
de) este mecanismo auto-calibrado — porque ya tenés una cifra de referencia del negocio que yo no
tengo — decímelo y lo agrego como umbral adicional configurable, no se pierde nada implementando el
mecanismo auto-calibrado primero.

No existe la tabla `admin_cont_settings` hoy (`AdminContFiscalSettings` es config fiscal singleton,
no key/value genérico) — hace falta crearla replicando el patrón exacto de `BodegaSetting`/
`BodegaSettingsService` (mismo tipo de tabla, mismo namespace por módulo). Confirmar con Backend Dev
al implementar si conviene esa tabla nueva o reusar `GlobalSetting` (landlord, ya usado para
`audit_logs.retention_months`) — inclinación de este threat model hacia la tabla nueva por
consistencia con el precedente de Bodega, pero no es una decisión de seguridad, es de arquitectura.

### 6. "Aprobaciones repetidas" (Admin&Cont) — mecanismo simple, sin necesidad de dato histórico
**Severidad: BAJA**

A diferencia del Hallazgo 5, esta regla no necesita calibrar un monto — es un conteo de eventos por
actor en una ventana corta, mismo tipo de mecanismo que el Hallazgo 3.

**Mitigación / umbral propuesto:** `admin_cont_settings.fraude_aprobaciones_repetidas_n` (default
**5**) y `fraude_aprobaciones_repetidas_minutos` (default **10**) — alertar si el mismo actor
confirma/aprueba ≥N pagos o comisiones en una ventana de M minutos. Sin datos reales de aprobación
disponibles tampoco, pero el riesgo de falso positivo es menor que un monto en dólares (un actor
legítimo aprobando un lote grande de pagos de fin de mes es un patrón conocido y explicable, no
necesita calibración fina — la alerta es barata de revisar y descartar).

### 7. Dedupe de `SecurityAnomaly::existsForRange()` — confirmar que no bloquea las reglas nuevas si corren en el mismo job
**Severidad: BAJA (ya identificado en la investigación previa, confirmado acá)**

`existsForRange($start, $end)` matchea cualquier fila en ese rango de fecha, sin discriminar por
fuente/regla — si REQ-635 se implementa como métodos nuevos dentro del mismo `SecurityAnalysisJob`
(recomendado, ver diseño) en vez de un job separado, esto no es un problema (el job ya corre una
vez al día y agrupa todos sus hallazgos). **Confirmar en implementación** que el dedupe se evalúa
por el conjunto completo de hallazgos del día, no que la primera regla que persiste bloquee a las
que le siguen dentro del mismo job run.

---

## Recomendaciones concretas para AI Architect / Backend Dev antes de implementar

1. Extender `SecurityAnalysisJob` con métodos nuevos (`buildBodegaAdjustmentFindings()`,
   `buildBodegaCorrelationFindings()`, `buildAdminContAmountFindings()`,
   `buildAdminContRepeatApprovalFindings()`) — mismo patrón que `flagPublicUploads()`, sin IA
   (reglas determinísticas, no hay necesidad de mandarle esto a Claude).
2. Los 3 umbrales de Bodega (Hallazgos 1/2/3) van en `BodegaSettingsService`, mismo patrón
   `KEY_`/`DEFAULT_` + `intSetting()` ya usado 6 veces.
3. Los umbrales de Admin&Cont (Hallazgos 5/6) necesitan una tabla/servicio nuevo —
   `AdminContSetting`/`AdminContSettingsService`, réplica exacta de `BodegaSetting`/
   `BodegaSettingsService` (mismo shape de tabla, mismo estilo de clase).
4. Hallazgo 4 (bootstrap de IP) — implementar el "solo evaluar si hay ≥1 evento previo" como parte
   del diseño de la regla, no como un caso especial ad-hoc.
5. Hallazgo 5 — mecanismo auto-calibrado por desviación estándar, no un monto fijo, salvo que Luis
   provea una cifra de referencia real del negocio.

## Puntos que requieren decisión de Luis

1. **Hallazgo 5** — ¿hay una cifra de referencia real (ej. "un pago típico de Atlantic ronda
   $X, alertar sobre $Y") que deba usarse como techo fijo además del mecanismo auto-calibrado? Sin
   esa cifra, avanzo solo con el mecanismo auto-calibrado (no bloquea el resto de la implementación).
2. Todos los demás umbrales (Hallazgos 1-4, 6) son ajustables sin deploy una vez implementados —
   no necesitan aprobación previa de un número exacto, el criterio de aceptación del ticket ya pide
   que sean configurables por superadmin.

---

## Veredicto

**No hay ningún bloqueante de seguridad que impida avanzar.** El único punto pendiente de Luis
(Hallazgo 5) no bloquea el resto — el mecanismo auto-calibrado cubre el caso mientras tanto. Umbrales
iniciales para Bodega (Hallazgos 1-4) están respaldados por el poco dato real disponible + juicio de
dominio, documentados como conservadores y sujetos a revisión con más volumen — no son una
calibración definitiva, y no debería tratarse como tal en ningún reporte a cliente. Aprobado para que
PM desglose REQ-635 en tareas.
