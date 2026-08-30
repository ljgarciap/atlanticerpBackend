# Pre-QA + Visual Review (fusionado) — Admin&Cont, Notas Crédito Batch 11 — submit real (SCRUM-559→564, REQ-482→487)

**Fecha:** 2026-08-24
**Resultado: PASADA LIMPIA. Los 6 tickets pasan a QA.**

Commits evaluados: backend `a402ead`, frontend `f51b01e` + fix cosmético de comentario sin
commitear (`src/types/adminContab.ts`, corrige el docblock de la URL del endpoint de facturas —
no toca código, se deja igual, no requiere Senior Review adicional). Ambos ya tenían Senior Review
de código hecho (revisión línea por línea, sin hallazgos funcionales) — este gate cubre la
verificación en navegador real y adversarial, no una relectura de código.

## 0. Ambiente de prueba

Local (`http://localhost:8090`, nginx sirviendo `atlanticerp-frontend/dist` — **rebuildeado** al arrancar
esta sesión para asegurar que reflejara el commit `f51b01e` real, el `dist/` existente tenía
timestamp anterior a los archivos fuente del batch). Contenedores `laravel`/`horizon` ya corrían el
commit `a402ead` (verificado con `git log` dentro del contenedor). Migraciones y seeders del batch
ya corridas (`credit_note_folio_counters`, `fiscal_settings.mark_approval_threshold=5000.00`).

Sesión real con **Felix** (`conta@atlantic.com.pa`, `lider_admin_contab`) para los flujos
principales, y **Neil** (`neil.quiel@atlantic.com.pa`, `vendedor_disenador`) para el chequeo
negativo de permisos — nunca cuentas `*@atlantic.test`.

**Fixtures sembradas** (el ambiente local no tenía ningún dato facturable — 0 filas en
`admin_contab.invoices`/`ventas_diseno.master_clients`, mismo gap de entorno ya documentado en
Pre-QA de batches anteriores de Facturación): 1 `MasterClient` ("PREQA Batch11 Cliente", id 690) +
6 facturas cubriendo cada escenario de negocio (`PREQA-B11-A` sin pagar $6,200; `PREQA-B11-B` y
`PREQA-B11-C` pagadas al 100% $1,000/$800; `PREQA-B11-D` nunca cobrada $6,200; `PREQA-B11-E` sin
pagar $9,000 para el umbral; `PREQA-B11-F` sin usar) + 1 factura adicional con `id=1` para que
coincidiera con el `factura_origen_id` hardcodeado del andamiaje temporal `?preview=devolucion`
(`MOCK_DEVOLUCION_PRECARGADA` en `NotasCreditoPage.tsx`, documentado en el propio código como
"QA-only, se borra en Batch 12") + 1 cuenta bancaria activa. Verificación hecha con Playwright vía
un script ad-hoc (`chromium.launch()` directo, no el test runner — evita que `webServer` levante
`npm run dev`/:5173 quen no hacía falta ya que el objetivo era el build real en :8090), 40
aserciones sobre los 6 tickets, y 4 aserciones adicionales en un script de cierre para el caso de
excedente en modo Devolución. Después de reconciliar 2 gaps del propio script de prueba (ver abajo)
las 44 aserciones pasan limpio.

**3 falsos negativos encontrados y corregidos en el PROPIO script de verificación (no en el
producto)** — documentados para que quede explícito que no son hallazgos del batch:
1. `formatCurrency` renderiza `"USD 0.00"` (locale `es-PA`), no `"$0.00"` — el script comparaba
   contra el símbolo `$` literal.
2. Un `fetch()` manual sin header `Accept: application/json` cae en la negociación de contenido
   "web" de Laravel y devuelve un 302 a `APP_URL` en vez de JSON — necesario agregar el header
   explícito para probar el endpoint directo (mismo patrón que cualquier cliente HTTP real, Axios
   ya lo manda por defecto, por eso el flujo real de la SPA nunca lo sufre).
3. Un comprobante de prueba con solo los 8 bytes mágicos de PNG (sin estructura real) no pasa la
   detección de contenido de Laravel (`mimes:png`, por sniffing de contenido real, no por
   extensión) — hacía falta un PNG válido de verdad (1×1 px) para probar el flujo positivo.

## 1. SCRUM-559 / REQ-482 — Banners informativos (Bodega + umbral de aprobación)

- **RN1 (confirmación física de Bodega):** banner presente y visible en modo Devolución
  (`?preview=devolucion`) — ausente en modo manual (Descuento comercial / Anulación completa),
  verificado explícitamente en ambos sentidos.
- **RN2/RN3 (umbral de aprobación):** umbral leído de la tarjeta real de "Pendientes de aprobación"
  del resumen del mes (`mark_approval_threshold`, Configuración Fiscal) — **no asumido en $5,000**,
  aunque en este ambiente coincide con el default. Con monto $3,000 (bajo el umbral real) el banner
  NO aparece; subiendo a $6,000 en el mismo formulario, sin recargar, el banner aparece en tiempo
  real. Umbral compartido correctamente con Facturación/Comisiones Externas vía la misma columna de
  `fiscal_settings`.

## 2. SCRUM-560 / REQ-483 — Selección de factura de origen

Con el cliente de prueba (2 facturas pagadas al 100%, 1 sin pagar, 1 nunca cobrada) el selector
lista **las 6 facturas**, incluidas las 2 con `saldo pendiente: USD 0.00` — confirmado que Notas
Crédito, a diferencia de Cobros, no filtra facturas ya pagadas (`CreditNoteService::
invoicesForClient()`). Obligatorio en Descuento comercial/Anulación completa (el botón de registrar
queda deshabilitado sin factura elegida, verificado indirectamente vía `canSubmit`). En modo
Devolución la factura llega precargada y el selector no aparece — verificado.

## 3. SCRUM-561 / REQ-484 — Auto-aplicación al saldo y resolución del excedente

- **Escenario 1 (cabe en el saldo):** factura con $6,200 de saldo pendiente + nota de $1,000 → se
  aplica sin preguntar nada, banner informativo muestra el nuevo saldo **$5,200** exacto. POST real
  201, backend actualiza `saldo_aplicado` de la factura.
- **Escenario 2 (excedente):** probado en sus 2 resoluciones por separado —
  - Factura ya pagada (saldo $0) + nota $300 → excedente completo detectado, ofrece "Devolver el
    dinero al cliente" (exige cuenta bancaria — sin elegirla el botón queda deshabilitado; con una
    cuenta activa elegida, POST real 201) y "Dejar como saldo a favor" (NO exige cuenta bancaria,
    POST real 201).
- **Escenario 3 (Anulación completa, factura nunca cobrada):** factura con saldo pendiente == monto
  total ($6,200) + Anulación completa/Pedido cancelado → el sistema indica que se cancela la deuda
  sin excedente ni pregunta de devolver/saldo a favor (RN3), verificado que NO aparecen los radios
  de resolución de excedente en este caso puntual, a diferencia del Escenario 2. Con comprobante
  adjunto, POST real 201 — el backend calculó `estado=pendiente_aprobacion` porque $6,200 supera el
  umbral, correcto y consistente con REQ-482 RN2 (el gate de aprobación aplica parejo sin importar
  el tipo de nota).
- **RN4 (monto bloqueado en Anulación completa):** confirmado — el campo de monto se reemplaza por
  un valor de solo lectura igual al total exacto de la factura elegida, sin ningún `<input>`
  numérico en el DOM en ese modo.

**Nota de fidelidad visual, NO bloqueante:** el mockup (`4D__Admin_Contabilidad_Notas_Credito.html`)
antecede los 2 radios de resolución de excedente con un label explícito "¿Qué hacer con el
excedente?". La implementación real va directo del aviso "el excedente: $XXX" a los 2 radios, sin
ese label intermedio — ambas opciones están presentes y funcionan, el aviso ya nombra "el
excedente", así que no es un campo/botón/vista ausente (criterio de "CRÍTICO" de este gate). Se
documenta para que quede visible, no bloquea el paso a QA.

## 4. SCRUM-562 / REQ-485 — Monto, ITBMS, motivo y fecha

- Desglose de ITBMS recalculado en tiempo real: con la tasa 7% de la factura y monto $1,000 →
  subtotal/ITBMS mostrados coinciden con el cálculo esperado (**ITBMS ≈ $65.42**), usando la tasa
  YA aplicada a la factura de origen (no una tasa nueva, RN1).
- Motivo obligatorio: botón de registrar deshabilitado sin motivo, habilitado al llenarlo — probado
  explícitamente en ambos estados.
- Fecha: siempre hoy, campo deshabilitado — nunca editable (RN3).

## 5. SCRUM-563 / REQ-486 — Avisos automáticos

- Aviso de notificación al cliente (RN1) visible siempre que hay factura elegida — texto exacto
  verificado contra el i18n (`avisoNotificacionCliente`: deja explícito que la factura original NO
  se reenvía ni cambia, solo la Nota de Crédito + estado de cuenta).
- Aviso de reducción de comisión (RN2) visible cuando hay factura de origen (Descuento
  comercial/Anulación completa/Devolución) — **ausente** en el subtipo Corrección de datos,
  verificado explícitamente en el DOM (no solo por lectura de código).

## 6. SCRUM-564 / REQ-487 — Comprobante de soporte

Verificado en ambos sentidos (bloquea cuando debe, NO bloquea cuando no debe):
- **Anulación completa, monto bajo ($6,200 < umbral):** comprobante marcado obligatorio (`*`),
  registrar SIN comprobante queda bloqueado (Escenario 1 del ticket), adjuntando un comprobante
  válido se desbloquea y el POST real completa con 201.
- **Monto sobre el umbral, fuera de Anulación (Descuento comercial $6,000):** comprobante también
  marcado obligatorio — RN1 cubre ambos disparadores, no solo Anulación.
- **Modo Devolución, monto alto ($9,999, por encima del umbral):** comprobante **NUNCA** marcado
  obligatorio (RN2 pisa a RN1) — botón de registrar queda habilitado sin comprobante (una vez
  resuelto el excedente, que aplica igual en Devolución — REQ-484 no distingue por tipo), POST real
  201 sin comprobante adjunto. Confirma que la devolución nunca lo exige, sin importar el monto.

## 7. Alcance deliberadamente excluido — Corrección de datos (Batch 12)

Verificado en frontend y backend por separado:
- **Frontend:** en el subtipo "Corrección de datos" no aparece selector de factura de origen, ni
  input de monto, ni comprobante — sigue siendo el formulario de solo-vista de Batch 10. El botón
  de registrar permanece deshabilitado (`esCorreccionDeDatos` bloquea `canSubmit`), no llega a
  intentar ningún POST real.
- **Backend, bypass directo del frontend:** POST manual al endpoint con `subtipo_anulacion=
  correccion` (saltando la UI) → **422**, con el mensaje explícito "El registro de notas de
  'Corrección de datos' ... todavía no está disponible — llega en el próximo batch", más las demás
  validaciones normales del payload (no un genérico 500). Confirma que el rechazo no depende de que
  el frontend coopere.

## 8. Permisos (gate de rol de la ruta)

**Neil** (`vendedor_disenador`) — no ve la pantalla real (el body no muestra las tarjetas ni el
formulario reales, cae al layout de Ventas & Diseño), no ve el link "Notas de Crédito" en el
sidebar, y pegándole directo al endpoint `GET /notas-credito/summary` con su propio token recibe
**403**. Mismo gate de rol que Facturación/Cobros (`superadmin,lider_admin_contab,
asistente_administrativa,management`) — consistente con "Disponible para Felix, Yaneth, Mark y
Gerencia" de los 6 tickets.

## Hallazgos y su resolución

- **Ningún hallazgo bloqueante.** Los 3 falsos negativos de la sección 0 fueron gaps del propio
  script de verificación (no del producto) y se corrigieron ahí, re-verificando después con éxito.
- **1 nota de fidelidad visual no bloqueante** (sección 3, radios de excedente sin label
  intermedio) — documentada, no bloquea el paso a QA per los criterios de este gate.

## Pendiente para Luis (no bloqueante para esta pasada)

Push de `dev` (ambos repos) requiere su aprobación explícita — gate de `git push`, sin excepción.
