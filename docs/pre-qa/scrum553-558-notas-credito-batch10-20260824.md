# Pre-QA + Visual Review (fusionado) — Admin&Cont, Notas Crédito Batch 10 (SCRUM-553→558, REQ-476→481)

**Fecha:** 2026-08-24
**Resultado: PASADA LIMPIA tras corregir 2 mismatches de contrato (ver Senior Review). Los 6 tickets pasan a QA.**

Commits evaluados: backend `837b72a` + `98842b1` (fix de contrato), frontend `32a16c7` + `258660a`
(fix de contrato), rama `dev` local en ambos repos — **no pusheado todavía**, pendiente de
aprobación explícita de Luis. Gate real ya corrido y verificado en
`docs/reviews/scrum553-558-notas-credito-batch10-20260824.md` (2536/2536 backend + PHPStan limpio,
1443/1443 frontend + tsc limpio) — no se repite acá.

## 0. Ambiente de prueba

Local (`localhost:5173` vía Vite dev, proxy a `localhost:8090`), sesión real con Felix
(`conta@atlantic.com.pa`, `lider_admin_contab`) y con `designer@atlantic.test` para el
chequeo negativo de permisos. Sin sembrar fixtures nuevos — el roster real de 40 usuarios y el
catálogo de 3 tasas de ITBMS (Configuración Fiscal) ya estaban en la BD local. `credit_notes` queda
vacía a propósito (nada escribe todavía, es Batch 11) — verificado que el estado vacío se ve
correcto, no como error.

No se verificó contra dev.atlanticerp.ai porque este batch **no está desplegado** — el commit es local,
sigue pendiente de la aprobación de push de Luis.

## 1. SCRUM-553 / REQ-476 — Encabezado y botón "+ Nueva nota"

Entry point del sidebar verificado explícitamente (lección de Luis 2026-08-22 sobre "Admin. &
Contab." apuntando a un placeholder muerto 5 batches seguidos) — el botón del sidebar navega a una
pantalla real, no un placeholder. El botón "+ Nueva nota" abre el formulario en modo manual. El
selector de tipo de nota en ese modo **solo ofrece 2 opciones** (Descuento comercial / Anulación
completa) — verificado leyendo las `<option>` reales del DOM, "Devolución de mercancía" nunca
aparece ahí (RN1 cumplido).

## 2. SCRUM-554 / REQ-477 — 5 tarjetas de indicadores del mes

Las 5 tarjetas (Total acreditado, N° de notas, Pendientes de aprobación, Devoluciones por generar,
Nota promedio) cargan del endpoint real y muestran `USD 0.00` / `0` — estado esperado sin notas
registradas, no un bug (`credit_notes` vacía hasta Batch 11). Screenshot: `01-pantalla.png`.

## 3. SCRUM-555 / REQ-478 — Selección de cliente y tipo de nota

Cliente editable en modo manual (buscador con debounce). Tipo de nota default "Descuento
comercial" verificado. Cambiar a "Anulación completa" recalcula los campos siguientes en el mismo
render (sin reload).

## 4. SCRUM-556 / REQ-479 — Anulación completa: subtipo y regreso de mercancía

Subtipo default "Pedido cancelado" verificado (Escenario 1 del ticket: aparece la pregunta de
regreso de mercancía). Default de esa pregunta "No" verificado. Cambiar a "Corrección de datos"
hace desaparecer la pregunta de mercancía (Escenario 3) — verificado en el DOM, no solo visualmente.
Screenshot: `02-anulacion-cancelado.png`.

## 5. SCRUM-557 / REQ-480 — Corrección de datos: motivo, ITBMS, fecha

Aviso fijo de "no se tocan cotización/pedido/guía" visible. Motivo "Tratamiento de ITBMS
incorrecto" muestra tratamiento actual (**"—"**, correcto para este batch — no hay factura
seleccionada todavía, esa pieza es REQ-483/Batch 11, documentado en el componente) + selector de
tratamiento correcto. **Verificado con espera real de red** (el primer intento sin esperar la
respuesta del endpoint mostró solo el placeholder "Selecciona..." — falso negativo de mi propio
script de prueba, no del código: con la espera correcta, el selector carga las 3 tasas reales de
Configuración Fiscal — 7%/3.5%/0%). Motivo "Fecha de facturación incorrecta" → campo bloqueado con
la fecha de hoy, no editable (Escenario 2 del ticket). Screenshot: `03-correccion-itbms.png`.

## 6. SCRUM-558 / REQ-481 — Devolución de mercancía (solo lectura)

Componente construido y revisado por props con datos mock (no hay entry point real, la cola de
Bodega es REQ-491/Batch 12) — cliente bloqueado, Referencia/Productos/Persona/Proyecto/Conformidad
en solo lectura, sin ningún campo editable. Confirmado por lectura de código que "Devolución de
mercancía" nunca es alcanzable desde el flujo manual (punto 1).

## 7. Permisos (Matriz de Permisos por Rol del cliente)

`designer@atlantic.test` (Vendedor/Diseñador) **no ve la pantalla** y los 2 endpoints de
lectura devuelven `403` — verificado por HTTP directo, no solo por ausencia de link en el sidebar.
Screenshot: `04-designer-sin-acceso.png`.

## Hallazgos y su resolución

- **2 mismatches reales de contrato Backend↔Frontend** (path y shape del endpoint de resumen) —
  encontrados en este mismo Pre-QA al verificar en navegador real (no solo leyendo el código de
  cada lado por separado), corregidos y re-verificados. Ver Senior Review para el detalle completo.
  Sin este paso, el batch hubiera llegado a QA con las 5 tarjetas rotas (404 silencioso) pese a que
  ambos repos tenían tests unitarios en verde por separado — cada lado testeaba su propio mock, no
  la integración real.
- Ningún hallazgo bloqueante adicional sobre los criterios negativos revisados arriba.

## Pendiente para Luis (no bloqueante para esta pasada de Pre-QA)

Push de `dev` (ambos repos) requiere su aprobación explícita — gate de `git push`, sin excepción,
incluso con modo autónomo activo para el resto de este batch.
