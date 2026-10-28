# Pre-QA + Visual Review fusionados — Batch 18 Admin&Cont, Arqueo/Flujo de Caja parte 1

**Fecha:** 2026-08-25 · **Tickets:** SCRUM-597→601 (REQ-520→524) · **Entorno:** `dev.atlanticerp.ai`
(deploy manual por SSH, GitHub Actions caído por billing de la org).

Diseño de referencia: `atlanticerp-backend/docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md`.
Mockup: `4G__Admin_Contabilidad_ArqueoCaja.html` (cubre este batch y Batch 19 — solo se evaluó lo de
REQ-520→524; cerrar arqueo, historial, aprobación de Gerencia y constancia de retención son
Batch 19, fuera de alcance).

Credenciales usadas: Contabilidad (`conta@atlantic.com.pa`), Asistente
(`asistente@atlantic.com.pa`), Aprobador (`gerencia3@test.com` /
`B1n4X_2026?`, real de Gerencia).

## Resumen ejecutivo

| Ticket | REQ | Veredicto | Bloqueante |
|---|---|---|---|
| SCRUM-597 | 520 — Encabezado, 4 tarjetas, exportar | **CRÍTICO** | Sí |
| SCRUM-598 | 521 — Vista proyectada | MEDIO (cobertura de datos incompleta) | No corregido, no transicionado |
| SCRUM-599 | 522 — Toggle + chips | Limpio en su propio alcance | Bloqueado por dependencia con SCRUM-597 |
| SCRUM-600 | 523 — Vista real (histórico) | MEDIO (cobertura de datos incompleta) | No corregido, no transicionado |
| SCRUM-601 | 524 — Arqueo del día | MEDIO (texto engañoso, un rol) | No corregido, no transicionado |

**Ningún ticket transicionado. Ningún marcador de gate creado.** Ver "Interrupción de la sesión"
más abajo — `dev.atlanticerp.ai` quedó inalcanzable a mitad de la corrida, antes de poder cerrar el loop
completo (fix → re-check) sobre ninguno de los hallazgos.

## Checklist funcional del mockup (alcance REQ-520→524)

- **REQ-520:** 4 tarjetas (Saldo disponible hoy c/desglose, Entradas proyectadas, Salidas
  proyectadas, Saldo proyectado); botón "Exportar reporte" con submenu PDF/Excel, oculto en
  Real+Hoy.
- **REQ-521:** panel de 2 columnas (Entradas esperadas / Salidas esperadas), cada fila con
  nombre/referencia/monto/tag de vencimiento; total por columna; flujo neto.
- **REQ-522:** toggle Proyectado/Real (histórico); chips Hoy/30 días/90 días.
- **REQ-523:** tabla Fecha/Concepto/Origen/Entrada/Salida/Saldo acumulado.
- **REQ-524:** tabla Concepto/Monto/Observaciones (input editable por fila); totales Total
  cobrado hoy / Notas crédito y devoluciones / Total neto del día; textarea Observación general
  del día; nota de conciliación bancaria.
- Explícitamente fuera de alcance (Batch 19, no evaluado): botón "Cerrar arqueo del día", panel
  "Historial de arqueos cerrados", sección "Ajustes/Retención", modal de constancia de retención,
  aprobación de Gerencia.

## SCRUM-597 (REQ-520) — CRÍTICO

Las 3 tarjetas "Entradas proyectadas" / "Salidas proyectadas" / "Saldo proyectado" quedan en "—"
cada vez que el usuario está en la pestaña "Real (histórico)" con una ventana (windowDays) que
nunca se pidió mientras estaba en la pestaña "Proyectado" en esa sesión — viola RN2 ("Entradas/
Salidas/Saldo proyectados sí dependen de la ventana Hoy/30/90", no del toggle Proyectado/Real). El
propio mockup recalcula esas 3 tarjetas siempre, sin importar `currentView`.

**Reproducción (2 casos confirmados):**
1. Contabilidad → default Proyectado+30 (tarjetas OK) → "Real (histórico)" → chip "Hoy" → tarjetas en
   "—", aunque el mismo dato (Entradas $0.00 / Salidas $86.06 / Saldo $11,851.25) se confirma
   correcto si "Hoy" se clickea estando en Proyectado. Ver
   `atlanticerp-frontend/docs/visual-review/screenshots/scrum597-real-hoy-dash.png` vs.
   `scrum597-proyectado-hoy-proof.png`.
2. Sesión nueva → Real (histórico) → chip "90 días" (nunca visitado en Proyectado) → mismas 3
   tarjetas en "—". Ver `scrum597-real-90-dash-fresh-session.png`.

**Causa raíz** — `atlanticerp-frontend/src/pages/admin-contab/ArqueoCajaPage.tsx:49`:
```ts
const { data: projected, isFetching: isFetchingProjected } = useCashPositionProjected(
  windowDays, canProjectedReal && view === 'proyectado',
)
```
El `enabled` del query está gateado por `view === 'proyectado'`, pero `projected` también alimenta
las tarjetas del encabezado (líneas 71-82), que según RN2 no dependen del toggle. **Sugerencia de
fix:** cambiar el `enabled` a `canProjectedReal` a secas (sin `&& view === 'proyectado'`), para que
las tarjetas del encabezado se mantengan pobladas sin importar la pestaña activa.

**Hallazgo aparte, no autoclasificado — pendiente de decisión de Arquitecto/PM:** el criterio de
Jira de este ticket lista "Permisos: Contabilidad, Asistente, Aprobador, Gerencia", y el ADR (reconciliación #8)
dice que REQ-520/522 "sí incluyen a Asistente", a diferencia de REQ-521/523. Pero el endpoint que
alimenta las 3 tarjetas proyectadas (`GET /cash-position/projected`) está gateado en el propio ADR
contra Asistente. Confirmado en vivo (403 real): las 3 tarjetas de Asistente SIEMPRE muestran "—",
incluso sin el bug de arriba. No está claro si es la intención (solo "Saldo disponible hoy" con
dato real para ella) — no lo autoclasifico, queda para que Arquitecto/PM decidan.

Documentado también en el comentario de Jira de SCRUM-597, con 3 capturas adjuntas.

## SCRUM-598 (REQ-521) — MEDIO, cobertura incompleta

Panel de 2 columnas, tags de vencimiento, neto proyectado (matemática verificada: 3,293.65 -
86.06 = +3,207.59) y filtro del chip "Hoy" — todo correcto con el fixture disponible. Acceso
correcto para Contabilidad/Aprobador, 403 real confirmado para Asistente.

No se pudo ejercitar en vivo RN1 (exclusión de facturas "incobrable") ni RN2 (exclusión de nota de
crédito ya `aplicada`) — el fixture de dev.atlanticerp.ai solo tiene 1 entrada y 1 salida en total, sin
ningún caso negativo sembrado. Recomendado sembrar ambos escenarios antes de un pase concluyente.

## SCRUM-599 (REQ-522) — limpio en su propio alcance, bloqueado por dependencia

RN1 (default Proyectado+30 para Contabilidad/Aprobador, Real+Hoy para Asistente sin ofrecer las otras opciones),
RN2 (Real+Hoy → Arqueo del día completo, no tabla filtrada), RN3 (Real+30/90 → tabla cronológica) y
RN4 (chip Hoy en Proyectado filtra sin comportamiento especial) — todos confirmados correctos.
Intento de bypass por URL directa (`?view=proyectado&window=30` como Asistente) sin efecto, ignorado
por el frontend. Recarga a mitad de flujo pierde el tab/ventana elegidos (vuelve al default) —
documentado como nota, no como hallazgo (ni el ticket ni el mockup definen persistencia de UI
state entre reloads).

El toggle en sí funciona bien — el CRÍTICO de SCRUM-597 vive en el fetch de datos de las tarjetas
del encabezado, no en la mecánica de conmutación de pestañas. Se deja sin transicionar para
cerrarlo junto con SCRUM-597 en el mismo batch de re-verificación.

## SCRUM-600 (REQ-523) — MEDIO, cobertura incompleta

Tabla con las 6 columnas del mockup, orden cronológico ascendente y saldo acumulado matemáticamente
correcto (23 ago $6,200.00 → saldo $6,200.00; 25 ago +$5,737.31 → saldo $11,937.31, coincide con
"Saldo disponible hoy"). Origen visible por tag ("Cobros"). Acceso correcto para Contabilidad/Aprobador, 403
real confirmado para Asistente.

El fixture solo tiene movimientos de origen "Cobros" — ningún caso real de origen "comisión"
(comisión externa pagada) ni "devolución" (nota de crédito aplicada) para confirmar tag/resta de
saldo en esos orígenes. Contrato y nombres de campo lucen correctos por inspección del ADR, pero no
ejercitados en vivo.

## SCRUM-601 (REQ-524) — MEDIO, mensaje de rol incorrecto

Tabla Concepto/Monto/Observaciones auto-armada (RN1); observación por fila editable — probado
guardar, recargar a mitad de flujo, confirmar persistencia, y vaciar el campo correctamente (RN2);
matemática de totales correcta en el único caso disponible, sin NC ese día (RN3); textarea
"Observación general del día" presente (RN4); nota de conciliación bancaria presente tal cual el
mockup (RN5). Acceso correcto para Contabilidad; 403 real confirmado (GET y PUT) para Aprobador en
`/cash-position/daily-count` — el bloqueo de seguridad funciona.

**MEDIO:** el mensaje que ve Gerencia al intentar Real+Hoy dice **"El Arqueo del día está
disponible para Contabilidad, Asistente y Aprobador."**, contradiciendo RN524 (excluye Aprobador/Gerencia
explícitamente) — la seguridad real funciona, solo el texto está mal. String en
`atlanticerp-frontend/src/i18n/locales/es/adminContab.json` (clave `arqueoCaja.restricted.dailyCount`,
línea ~969) y su espejo en `en/adminContab.json`. Fix: "Contabilidad y Asistente" / "Contabilidad and Asistente".
Captura: `atlanticerp-frontend/docs/visual-review/screenshots/scrum601-mark-wrong-message.png`.

No se pudo ejercitar RN3 con Notas crédito > 0 (mismo motivo de cobertura de datos).

## Camino de ruptura — genérico (Pre-QA Paso 3)

- Rol sin permiso: Asistente → 403 real en `/projected` y `/real` (no solo botón oculto). Aprobador → 403
  real en `/daily-count` GET y PUT (no solo botón oculto). Confirmado con requests directos vía
  token Bearer, sin pasar por la UI.
- Bypass por URL: intento de forzar `?view=proyectado&window=30` como Asistente — ignorado por el
  frontend, sigue en Real+Hoy.
- Recarga a mitad de flujo: probado en observación de arqueo del día (persiste correctamente) y en
  el tab/ventana elegidos (no persiste, vuelve al default — no es un criterio del ticket).
  Regresión CRÍTICA encontrada precisamente en esta categoría (SCRUM-597).
  El mismo botón en un estado distinto al que probablemente probó el dev (Real+90 en sesión nueva)
  también reprodujo el bug — no es exclusivo de "Hoy".
- Input vacío: observación de arqueo del día, vaciada correctamente sin error.
- Doble clic / envío duplicado: N/A para la observación (campo con guardado por blur, sin botón de
  submit explícito, mismo patrón que el mockup).

## Interrupción de la sesión — dev.atlanticerp.ai inalcanzable

Alrededor de la mitad de la corrida, `dev.atlanticerp.ai` (puerto 443) dejó de responder por completo —
`curl -v` muestra DNS resuelto pero "Connection timed out" (paquetes descartados en silencio, sin
RST), consistente con la firma de un ban del bouncer nftables de CrowdSec (ver
`../../CLAUDE.md` — sección Seguridad de infraestructura) más que con un simple 429/503. La
hipótesis más probable es que el volumen de logins consecutivos con roles distintos + llamadas
directas a la API vía `request` context de Playwright (bypaseando el flujo normal del navegador)
en esta sesión disparó una detección de `http-dos`/bruteforce sobre la IP de origen.

Esto bloqueó 2 verificaciones planeadas de menor prioridad (confirmación del 422 real del backend
en `export?view=real&window=0`, y el estilo visual del tag "Vencido Nd" — este último tampoco tenía
dato "atrasado" disponible en el fixture, independiente del corte de red) y, más importante, impidió
cerrar el loop completo de Pre-QA (fix → re-check) sobre cualquiera de los hallazgos de arriba
dentro de esta misma sesión — por eso ningún ticket se transicionó ni se corrigió en vivo, aunque
el fix de SCRUM-601 era trivial (una línea por locale).

**Recomendado como gotcha de proceso** (candidato a memoria, mismo espíritu que
`feedback_preqa_crowdsec_no_paralelo`): evitar ráfagas de logins con roles distintos + llamadas
`request.get/put` directas en la misma sesión de Pre-QA contra `dev.atlanticerp.ai` — espaciar los
cambios de rol o reutilizar un solo contexto de navegador donde sea posible.

**Addendum — `dev.atlanticerp.ai` volvió a responder más tarde en la misma sesión.** Se aprovechó la
ventana para cerrar 2 de los pendientes:
- **Re-confirmado que el CRÍTICO de SCRUM-597 sigue reproduciendo** en vivo (Real+90 en sesión
  nueva → tarjetas en "—"), descartando que haya sido un efecto transitorio del corte de red.
- **Export en Real+Hoy** (`GET /cash-position/export?view=real&window=0`) devuelve **422** —
  confirmado, pero con un matiz: el primer intento (sin `format`) devolvió un mensaje genérico de
  validación de parámetros ("view debe ser... format debe ser..."), no un mensaje específico de la
  regla RN3. El intento de aislar la causa exacta (repetir con `format=pdf` explícito, para
  confirmar que el 422 es por la combinación view=real+window=0 y no solo por `format` faltante)
  no se pudo completar — la conectividad volvió a caerse (esta vez "Connection refused" en vez de
  timeout, un patrón distinto al del corte anterior) a los pocos minutos de reconectar. **No dar
  por completamente cerrado el 422 de RN3** hasta repetir ese último request aislado con
  `format=pdf` una vez el entorno esté estable.

## Cierre — 2026-08-25, mismo día, loop cerrado

Los 4 puntos de "Próxima acción" (abajo, dejados como quedaron en el momento) ya están resueltos:

1. **SCRUM-597 + SCRUM-601 corregidos** (backend commit `6a624ab`, frontend `3185509`/`ddd9192`) y
   **desplegados** a `dev.atlanticerp.ai`.
2. **Asistente y las 3 tarjetas proyectadas: resuelto con Luis** — REQ-520 le da el encabezado
   completo (incluidos los 3 totales), REQ-521 solo le veda el panel de detalle línea por línea.
   `/projected` ahora acepta `asistente_administrativa` y devuelve `entradas`/`salidas` vacíos
   para ese rol (backend commit `6a624ab`), desplegado y verificado en vivo.
3. Casos de datos faltantes: **no bloqueante** — cubiertos por tests automatizados que sí corrieron
   en la suite completa (`test_projected_excluye_factura_incobrable_y_ya_saldada`,
   `test_real_calcula_saldo_acumulado_cronologico_y_respeta_la_ventana`,
   `test_daily_count_arma_cobros_confirmados_hoy_por_metodo_inmediato_y_diferido`,
   `test_daily_count_incluye_nota_credito_devuelta_hoy_con_el_monto_del_bank_movement`). No se
   sembraron en `dev.atlanticerp.ai` para no contaminar fixtures compartidos.
4. **Checklist completo re-corrido en vivo contra `dev.atlanticerp.ai` post-deploy** (8/8 verde, incluida
   la reproducción exacta del CRÍTICO de SCRUM-597 — ya NO reproduce) y el matiz de RN3 aislado:
   `GET /cash-position/export?view=real&window=0&format=pdf` (formato explícito) devuelve 422 con
   el mensaje específico de la regla ("El Arqueo del día (Real + Hoy) no se exporta desde acá."),
   no el genérico de validación.

Los 5 tickets pasan a `QA` con marcadores de Pre-QA y Visual Review creados.

## Próxima acción (histórico — ya resuelto, ver "Cierre" arriba)

1. PM reasigna SCRUM-597 (fix de `enabled` en `ArqueoCajaPage.tsx`) y, junto con el mismo fix,
   aplicar el de SCRUM-601 (texto de locale) — ambos triviales, un solo commit/deploy.
2. Resolver la nota de Arquitecto/PM sobre Asistente y las 3 tarjetas proyectadas (SCRUM-597).
3. Sembrar los casos de datos faltantes (incobrable, NC aplicada, comisión/devolución en Vista
   real, NC>0 en Arqueo del día) antes del próximo pase.
4. Re-correr el checklist COMPLETO de los 5 tickets (no solo lo que falló) una vez `dev.atlanticerp.ai`
   esté alcanzable y el deploy del fix esté confirmado.
