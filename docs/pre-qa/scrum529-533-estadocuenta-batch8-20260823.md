# Pre-QA + Visual Review (fusionado) — Admin&Cont, Estado de Cuenta Batch 8 (SCRUM-529→533, REQ-452→456)

**Fecha:** 2026-08-23
**Resultado: PASADA LIMPIA. Los 5 tickets pasan a QA.**

Gate real ya corrido y verificado — no se repite acá: `docs/reviews/scrum529-533-estadocuenta-batch8-20260823.md`
(2515/2515 backend + PHPStan Level 8 limpio, 1421/1421 frontend + tsc limpio).

## 0. Ambiente de prueba

Local (`localhost:5173` vía Vite dev, proxy a `localhost:8090`). Fixture sembrado vía tinker:
MasterClient "PreQA Batch8 Estado Cuenta" (id 77, `default_price_type=special`), SubClient +
SalesProject "Proyecto PreQA Batch8" (id 41), `client_billing_profile` real (`sin_itbms` /
`Contado`) para verificar que el encabezado usa el perfil real y no el default. 5 escenarios
corridos con **Playwright CLI** (regla dura del proyecto).

## 1. SCRUM-529 / REQ-452 — Encabezado y cierre de ventana

- **RN1 (sin menú de navegación principal):** verificado en vivo — `/admin-contab/facturacion/estado-cuenta`
  carga sin sidebar ni topbar de la app (0 elementos `<nav>` en el DOM), consistente con
  `FocusedViewShell`. Captura `01-vacio-sin-nav.png`.
- **Escenario 1 (cerrar ventana):** botón "Cerrar ventana" llama `window.close()` — verificado con
  spy en `EstadoCuentaPage.test.tsx` y visualmente en el navegador real (el botón está presente y
  clickeable, mismo patrón ya aprobado de Kardex).

**Sin hallazgos en REQ-452.**

## 2. SCRUM-530 / REQ-453 — Panel "Buscar estado de cuenta"

- **Escenario 1 (buscar sin cliente):** verificado en vivo con un listener de `window.on('dialog')`
  que lanza si se dispara cualquier alert nativo — nunca se disparó; el mensaje
  "Selecciona un cliente para generar el estado de cuenta." aparece inline (RN2).
- **Escenario 2 (proyecto dependiente del cliente):** verificado en vivo — al elegir "PreQA Batch8
  Estado Cuenta", el selector de Proyecto se habilita y solo muestra "Proyecto PreQA Batch8" (el
  único proyecto real de ese cliente). Test backend dedicado confirma que un proyecto de OTRO
  cliente nunca aparece en la lista (`test_projects_solo_devuelve_los_del_cliente_elegido`).
- **Escenario 3 (limpiar):** verificado en vivo — tras generar un resultado, "Limpiar" resetea
  cliente/proyecto/fechas y vuelve al estado vacío (RN3). Una corrida aislada con captura
  before/after confirmó el comportamiento correcto tras un timeout inicial flaky en la corrida en
  lote completa (ver nota de entorno en el Senior Review — no es un bug de la app, reproducido
  limpio en aislamiento).
- **RN4 (vista Agrupado por defecto):** el toggle "Agrupado por proyecto" está activo por defecto
  al cargar — verificado visualmente. El toggle en sí no afecta ningún dato todavía (no hay tabla
  de movimientos hasta Batch 9) — queda como estado de UI puro, a propósito.

**Sin hallazgos en REQ-453** (más allá del hallazgo de REQ-474-equivalente encontrado por mis
propios tests durante el desarrollo, ya corregido antes de este Pre-QA — ver Senior Review).

## 3. SCRUM-531 / REQ-454 — Estado vacío inicial

- **Escenario 1:** verificado en vivo y en `EstadoCuentaPage.test.tsx` — el mensaje "Selecciona un
  cliente (y opcionalmente un rango de fechas) para generar el estado de cuenta." se muestra al
  entrar, sin ninguna tabla vacía visible (RN1). Desaparece apenas hay un resultado válido (RN2) —
  verificado en la Escenario 3 de arriba (Limpiar hace el camino inverso).

**Sin hallazgos en REQ-454.**

## 4. SCRUM-532 / REQ-455 — Encabezado de resultado

- **Escenario 1 (sin proyecto):** verificado en vivo — encabezado muestra solo "PreQA Batch8 Estado
  Cuenta" + "Precio Especial · Sin ITBMS · Términos Contado" (RN2 — usa el `client_billing_profile`
  real sembrado, no el default "Con ITBMS/Neto 30", confirmando que RN2 lee lo vigente, no un
  histórico). Captura `02-resultado.png`.
- **Escenario 2 (filtrado por proyecto):** verificado en vivo y en test — encabezado muestra
  "Cliente · Proyecto" cuando se filtra (`test_show_con_proyecto_incluye_cliente_y_proyecto_en_el_encabezado`).
- Defensa en profundidad verificada: un `sales_project_id` de OTRO cliente en la URL/API devuelve
  422, nunca un encabezado incorrecto (`test_show_con_proyecto_de_otro_cliente_falla_422`).

**Sin hallazgos en REQ-455.**

## 5. SCRUM-533 / REQ-456 — Descargar / Imprimir

- **Escenario 1 (PDF vía diálogo de impresión):** verificado en vivo — "Descargar como PDF" llama
  `window.print()` (spy confirmado en test, mismo criterio literal del ticket, no un PDF
  descargado). Panel de búsqueda, botón de cerrar y el propio menú de exportar quedan ocultos al
  imprimir (`print:hidden`, `display:none` — no `visibility:hidden`, evitando el bug real ya
  encontrado en SCRUM-395/`GuiaEntregaModal`).
- **RN2 (Excel, mismas columnas visibles):** verificado en vivo — "Descargar como Excel" descarga
  `estado-cuenta-77.xlsx` real (`download.path()` no vacío). Contenido hoy limitado al encabezado
  (cliente/proyecto/tarifa/régimen/términos) porque la tabla de movimientos es Batch 9 — documentado
  explícitamente como alcance esperado en el Senior Review, no un hallazgo.

**Sin hallazgos en REQ-456.**

## Visual Review — fidelidad contra el mockup

Mockup: `4A1__Admin_Contabilidad_CXC_EstadoCuenta.html`. El header (logo+marca, título, subtítulo,
"Cerrar ventana"), el panel de búsqueda (Subcliente/Proyecto/Desde/Hasta/Vista/Buscar/Limpiar) y el
encabezado de resultado (cliente · proyecto, tarifa · régimen · términos, menú "Descargar /
Imprimir" con las 2 opciones) coinciden 1:1 con el mockup — mismo layout, mismo orden de campos,
mismos textos. La tarjeta de saldo, el bloque de datos de pago y la tabla de movimientos que
también aparecen en el mockup son intencionalmente de Batch 9 (REQ-457→461) — su ausencia acá no es
un hallazgo de Visual Review (el ticket de este batch no las incluye), coherente con el criterio ya
aplicado en Batch 8 de Cobros (Batch 6/7 split).

**Sin hallazgos críticos de Visual Review.**
