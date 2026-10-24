# Pre-QA (fusionado con Visual Review) — SCRUM-948

**Fecha:** 2026-09-23/24. **Entorno:** `dev.atlanticerp.ai` (deploy manual, backend commit `f7dcad6` /
frontend commit `532202a`). **Senior Review:** aprobado,
`atlanticerp-backend/docs/reviews/scrum948-recheck-20260923.md`. **Visual Review:** sin mockup adjunto
en Jira — el propio ticket exige comparación directa contra `/admin-contab/cobros` ("Validar en
Test comparando directamente Cobros vs. Registrar abono"), hecha como parte de este mismo Pre-QA.

## Veredicto: PASADA LIMPIA — transicionado a QA

## Contexto — por qué esto no estaba ya cubierto por el Pre-QA del 22/09

Una pasada anterior (2026-09-22) había dado por resuelto un ticket con este mismo número, pero con
un alcance distinto y más angosto: agregar un botón "limpiar" a la cascada del modal de creación
(SCRUM-933). El texto vigente del ticket (creado 2026-09-21, "Replicar buscadores de Cobros en
Registrar Abono") pide algo que nunca se había construido: que la tabla "Abonos registrados" tenga
los mismos buscadores/filtros que "Historial de cobros". Confirmado con capturas reales de Luis
(pantalla sin ningún buscador) y con el propio código: `AbonosPage.tsx` no tenía ningún input de
búsqueda ni filtro antes de este batch. Lección de proceso (ya anotada por Senior Review): el texto
de un ticket puede ampliarse de alcance entre una pasada y la siguiente sin que el historial de
comentarios lo anuncie — un futuro recheck no debe asumir "ya se hizo" solo por el número de
ticket, hay que releer el texto vigente cada vez.

## Datos de prueba

Dev solo tenía 3 abonos preexistentes, los 3 con el mismo `metodo_pago` (transferencia) y el mismo
`estado` (confirmado) — insuficiente para ejercitar combinaciones AND reales (Paso 0 del
protocolo: el fixture no cubría todos los escenarios que el criterio necesita). Se fabricaron 2
abonos nuevos vía API (`POST /api/admin-contab/abonos`, sin campo `comprobante`, que es opcional):
- `[PREQA-SCRUM948-CHEQUE]` (id 4, master_client_id 24, metodo `cheque`, nace `por_confirmar`)
- `[PREQA-SCRUM948-EFECTIVO]` (id 5, master_client_id 7566, metodo `efectivo`, nace `por_confirmar`)

Quedan en dev.atlanticerp.ai, identificables por el prefijo, mismo patrón que fixtures anteriores del
batch.

## Verificación backend (API real, curl autenticado contra dev.atlanticerp.ai)

| Escenario | Resultado |
|---|---|
| `search=PREQA-SCRUM948-CHEQUE` | 1 fila (la correcta) |
| `master_client_id=24` solo | 3 filas |
| `metodo_pago=cheque` solo | 1 fila |
| `estado=por_confirmar` solo | 2 filas |
| `master_client_id=24 & metodo_pago=cheque` (AND) | 1 fila — la intersección real |
| `master_client_id=7566 & metodo_pago=cheque` (AND, sin match posible) | 0 filas — no cae a OR |
| `desde=hoy & hasta=hoy` (borde exacto) | 2 filas — las 2 fixtures de hoy, inclusive |
| `desde=mañana` | 0 filas |
| `hasta=ayer` | 3 filas — las 3 preexistentes, excluye las de hoy |

Los 3 sub-fixes del backend (`metodo_pago` exacto, `desde`/`hasta` inclusive sobre
`fecha_registro`) funcionan exactamente como se pidió, incluida la combinación AND (no OR) y el
borde inclusive de fechas.

## Verificación frontend (Playwright real contra dev.atlanticerp.ai)

- **Presencia y estructura:** buscador de texto libre + 3 selects (Cliente/Método/Estado) + 2
  inputs de fecha, visibles arriba de "Abonos registrados" — mismo patrón visual que
  `HistorialCobrosPanel.tsx` (buscador → selects → fechas → Limpiar condicional → contador de
  resultados).
- **Buscador de texto:** filtra la tabla en vivo (`PREQA-SCRUM948-CHEQUE` → 1 fila).
- **Botón Limpiar:** oculto sin filtros activos, aparece con cualquier filtro activo, y al hacer
  clic resetea todos los campos y vuelve a mostrar las 5 filas totales.
- **Combinación AND real (no OR):** `estado=por_confirmar + metodo=efectivo` → 1 fila (la
  intersección correcta). Combinación imposible `estado=confirmado + metodo=efectivo` (el fixture
  efectivo nace `por_confirmar`, nunca `confirmado`) → **0 filas** — confirma que la combinación es
  AND, no OR (si fuera OR, esa combinación habría devuelto resultados).
- **Select de Cliente dinámico:** sus opciones se derivan de las filas ya cargadas/filtradas (con
  `metodo=cheque` activo, el dropdown de Cliente se reduce de 3 a 2 opciones) — mismo patrón ya
  aceptado en `HistorialCobrosPanel.tsx` (nota del Senior Review), no es una inconsistencia nueva.
- **Rango de fechas inclusive:** `desde=hasta=hoy` incluye ambos fixtures creados hoy.
  ⚠️ Trampa real encontrada armando este test: calcular "hoy" con `new Date().toISOString()` en
  Node da la fecha en UTC, que con Panamá en UTC-5 puede adelantarse un día entrado el atardecer —
  el primer intento con esa fecha dio 0 resultados y por un momento pareció un bug del producto.
  Era un error del test, no de la app (confirmado comparando contra `date +%Y-%m-%d` del shell y
  contra `fecha_registro` real de los fixtures). El spec permanente calcula la fecha local
  correctamente (`d.getFullYear()`/`getMonth()`/`getDate()`, no `toISOString()`) para no repetir
  la trampa.
- **Cobros (Historial de cobros) sin regresión:** buscador, 4 selects, 2 fechas, sin errores —
  confirmado que no se tocó ese componente (el frontend solo replica el patrón visual/de
  interacción sobre `AbonosPage.tsx`, `HistorialCobrosPanel.tsx` sigue siendo el mismo componente
  intacto).

## Comparación visual Cobros vs. Registrar abono (criterio explícito del ticket)

Mismo layout: buscador de ancho completo con ícono de lupa → fila de selects (Cliente/Método/
Estado) + 2 fechas → botón Limpiar a la derecha quality solo si hay filtro activo → texto
"Mostrando X de Y" debajo. Diferencias explicadas y aceptables:
- Placeholder del buscador distinto ("...cliente, sub-cliente, proyecto o factura..." en Abonos vs
  "...cliente, factura, recibo o referencia..." en Cobros) — refleja los campos reales de cada
  entidad, el ticket pide "misma lógica y comportamiento", no el mismo string literal.
- Filtro de Cliente en Abonos usa `master_client_id` (select por id) en vez de nombre de texto
  libre como en Cobros — decisión ya revisada y aceptada por Senior Review (evita colisión de
  nombres duplicados, el backend de Abonos ya filtraba por id).

## Lo que sí funciona (checklist completo)
Los 6 criterios de aceptación literales del ticket, más los 6 puntos de la verificación
adversarial pedida (texto, 3 selects individuales + combinados AND, rango de fechas inclusive,
Limpiar condicional + reset, Cobros sin regresión, comparación visual).

## Evidencia / artefactos
- Spec Playwright permanente: `atlanticerp-frontend/e2e/preqa-scrum948-abonos-buscadores-20260923.spec.ts`
  — 6/6 en verde.
- Fixtures `[PREQA-SCRUM948-CHEQUE]` (id 4) / `[PREQA-SCRUM948-EFECTIVO]` (id 5) quedan en dev.
