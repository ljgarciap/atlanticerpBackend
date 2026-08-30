# Pre-QA + Visual Review (fusionado) — Batch 21 Admin&Cont (SCRUM-618→623, REQ-541→546) — Caja Chica: rechazo/reapertura

Corrido en LOCAL (`localhost:8090`, nginx sirviendo el build fresco de React + proxy `/api` →
Laravel, build regenerado 2026-08-27 00:41 — posterior al último commit de Senior Review) contra
código ya commiteado en `dev` (backend `d5bb28a`, frontend `6a98258`) pero **no pusheado
todavía**. Suite completa de tests automatizados en verde (2725 backend + 1598 frontend) y 2
rondas de Senior Review ya aplicadas antes de este pase. Test permanente:
`atlanticerp-frontend/e2e/preqa-batch21-scrum618-623-cajachica-20260827.spec.ts`.

Mockup de referencia: `CajaChica_mockup.html` (comparado contra la app real — variantes de
layout/textarea inline en vez de `window.prompt()` ya validadas como aceptables, no son
desviación a reportar).

## CRÍTICO — SCRUM-620 (REQ-543) — el panel "Rechazados" crashea la aplicación completa a pantalla en blanco

**Qué se rompe:** al hacer clic en la pestaña "Rechazados" (desde cualquier rol — Felix, Yaneth,
Mark o Gerencia), toda la SPA se cae a una pantalla en blanco total (ni sidebar, ni header, ni
ningún contenido — `document.body` queda vacío). No hay forma de recuperarse sin recargar la
página. Esto bloquea por completo REQ-543 (SCRUM-620) y, en cascada, también REQ-544 (SCRUM-621,
reabrir), porque el único punto de entrada al detalle de una línea rechazada permanentemente
(botón "Reabrir") vive dentro de ese mismo panel.

**Causa raíz identificada:**
`atlanticerp-frontend/src/api/adminContabApi.ts:685-686`:
```ts
rejected: (): Promise<PettyCashRejectedLine[]> =>
  api.get<PettyCashRejectedLine[]>('/admin-contab/petty-cash/rejected').then(r => r.data),
```
El tipo declara que el endpoint devuelve un array plano, pero el backend real
(`AdminContPettyCashService::rejected()`, `atlanticerp-backend/app/Modules/AdminCont/Services/AdminContPettyCashService.php:410-417`)
responde `{"lineas": [...]}`, un objeto con la clave `lineas` — confirmado en vivo:
```
GET /api/admin-contab/petty-cash/rejected
{"lineas":[{"id":1,"proveedor":"ProveedorA1", ...}]}
```
`RechazadosPanel` (`atlanticerp-frontend/src/pages/admin-contab/PettyCashPage.tsx:270-315`) recibe ese
objeto completo como `data` y hace `data.length === 0 ? ... : data.map(...)`. Como
`{lineas:[...]}.length` es `undefined` (no `0`), cae al `else` y ejecuta `data.map(...)` sobre un
objeto, no un array → `TypeError: a.map is not a function`, sin ningún Error Boundary que lo
contenga, tumbando el árbol de React completo.

Es la misma clase de bug que el CRÍTICO ya encontrado y corregido en Batch 20
(`useApprovePettyCashReport` con `setQueryData` de forma parcial) — un mismatch entre el shape
real que devuelve el backend y el tipo que el frontend asume, sin ningún test que lo agarre
(los tests backend solo verifican el JSON crudo con `assertJsonStructure`/similar sobre `lineas`,
correcto para su propio alcance; los tests frontend mockeados le dan al mock un array plano en vez
del shape real `{lineas: [...]}`).

**Cómo reproducir:** cualquier usuario (Felix/Yaneth/Mark/Daniela) → Admin&Contabilidad → Caja
Chica → clic en la pestaña "Rechazados" (con al menos 1 línea con 2 intentos de rechazo existente,
para que el array no esté vacío — con 0 líneas el bug no se dispara porque no llega a `.map()`).

**Evidencia:** `docs/visual-review/screenshots/SCRUM-620-1.png` (pantalla completamente en blanco
tras el clic) + consola: `TypeError: a.map is not a function` en el bundle minificado, reproducido
2 veces de forma consistente (corrida aislada y corrida completa del spec permanente).

**Sugerencia de fix (2 opciones, cualquiera resuelve):**
1. **Más simple, mismo patrón que el fix de Batch 20** — en `adminContabApi.ts`, desenvolver la
   respuesta: `api.get<{ lineas: PettyCashRejectedLine[] }>('/admin-contab/petty-cash/rejected').then(r => r.data.lineas)`.
2. Alternativa — cambiar el backend para que `rejected()` devuelva el array plano directamente
   (`response()->json($this->pettyCash->rejected()['lineas'])`), para que coincida con lo que el
   tipo TS ya promete. Cualquiera de las 2 alcanza; la opción 1 es menos invasiva porque no toca
   contrato de API ya usado en otros lugares (`RechazadosPanel` es el único consumidor hoy).

**Bloqueante:** SÍ, para SCRUM-620 y SCRUM-621. Backend confirmado correcto en ambos casos (ver
abajo) — el bug es puramente de desenvuelto de respuesta en el frontend. No pasa a QA hasta que se
corrija.

### Verificación de backend vía API directa (mientras el frontend está roto)

Para no dejar sin verificar la lógica de negocio real de SCRUM-620/621 mientras el frontend está
bloqueado, se probó directamente contra la API:

- `GET /petty-cash/rejected` devuelve **exactamente** la línea con `intentos_rechazo=2`
  (ProveedorA1) — las líneas con 1 solo intento (ProveedorA2/A3/B1) correctamente **no** aparecen
  ahí (siguen en `pending()`). REQ-543 RN1 se cumple del lado del backend.
- `PUT /petty-cash/expenses/{id}/reopen` como Felix: con motivo vacío → bloqueado (422, vía
  `PettyCashMotivoRequest`); con motivo → `200`, `intentos_rechazo` vuelve a `0`, `estado` pasa a
  `reabierto`, y el `historial` de la respuesta incluye los 2 rechazos anteriores **más** la
  reapertura nueva (3 entradas), confirmando REQ-544 RN2/RN3.
- `PUT /petty-cash/expenses/{id}/reopen` como Mark → **403** (el middleware `role:` de la ruta ya
  excluye a Mark del roster, ni siquiera llega al controller) — confirma la exclusividad
  Felix/Yaneth de REQ-544 RN1.

Backend de REQ-543/544 correcto; solo falta corregir el desenvuelto de la respuesta en el
frontend para que la UI sea consistente con eso.

## Lo que sí cumple (probado activamente en navegador real, camino de ruptura incluido)

- **SCRUM-618/REQ-541** — Mark, desde el detalle de un reporte de 3 líneas: rechazo de 1 sola
  línea con motivo obligatorio (confirmar sin motivo bloquea) → esa línea sale del reporte, las
  otras 2 quedan intactas y pendientes de aprobación (`docs/visual-review/screenshots/SCRUM-618-1.png`).
  Rechazo del resto del reporte completo con un solo motivo → el reporte deja de existir como
  activo, `onDisuelto()` cierra el modal y vuelve a Pendientes sin dejar nada roto abierto
  (`SCRUM-618-2-tras-rechazo-completo.png`). Reporte de 1 sola línea: rechazo completo disuelve el
  reporte automáticamente, mismo comportamiento limpio (`SCRUM-618-4-reporte-unilinea-disuelto.png`).
  Felix (no Mark) no ve ni "Rechazar" ni "Rechazar reporte" en un reporte pendiente
  (`SCRUM-618-5-felix-sin-rechazar.png`) — y forzado por API directa, el backend igual responde
  **403** (gate real, no solo UI escondida).
- **SCRUM-618/SCRUM-623 — reporte rechazado (disuelto)** — aparece en la pestaña Reportes con
  badge "Rechazado"; su detalle muestra un mensaje explicativo simple en vez de una tabla vacía
  confusa, sin botón "Descargar" ni "Rechazar reporte" (`SCRUM-623-3-reporte-rechazado-detalle.png`)
  — el ajuste post-Senior-Review (`GET .../pdf` devuelve 409 para este estado, frontend lo esconde
  del todo) funciona correctamente.
- **SCRUM-619/REQ-542 — regla de 2 intentos** — primer rechazo de una línea: vuelve a Pendientes
  con "Rechazado (1/2)" visible y el motivo consultable en su historial
  (`SCRUM-619-1-pendientes-1de2.png`, `SCRUM-619-1-historial-1intento.png`). Esa misma línea,
  incluida en un reporte nuevo y rechazada de nuevo (2do rechazo): pasa **permanentemente** fuera
  de Pendientes (confirmado que ya no aparece ahí — `SCRUM-619-2-linea-fuera-de-pendientes.png`) —
  reporte de 1 sola línea también se disuelve limpio en el mismo flujo.
- **SCRUM-622/REQ-545 — modal unificado de detalle de línea, 3 contextos**:
  - Línea standalone en Pendientes: botón "Editar" visible; al editar, el campo Solicitante se
    muestra como texto de solo lectura con la etiqueta "(no editable)", el resto de los campos
    (fecha/proveedor/descripción/montos) son inputs editables
    (`SCRUM-622-1-editar-solicitante-no-editable.png`).
  - Línea dentro de un reporte **pendiente** de aprobación, abierta sin cerrar el modal padre
    (clic en una fila desde el detalle del reporte): el modal de línea se apila correctamente
    encima del modal de reporte (un solo overlay oscuro visible por encima del otro, sin
    superposición rara — confirmado visualmente y por DOM, 2 contenedores `fixed inset-0`
    coexistiendo con z-index `60`/`70` correctos), **sin** botón "Editar" pero **con** "Agregar
    soporte" (`SCRUM-622-2-modal-anidado-apilado.png`) — el fix de stacking del Senior Review
    funciona.
  - Línea dentro de un reporte **finalizado**: de solo lectura total, sin "Editar" ni "Agregar
    soporte", con el header mostrando "Reporte finalizado — ya no editable"
    (`SCRUM-622-3-linea-finalizada-solo-lectura.png`).
- **SCRUM-623/REQ-546**:
  - "Ver" sobre un soporte adjunto abre el visor con la imagen real decodificada (`naturalWidth >
    0` confirmado programáticamente, no un ícono roto — `SCRUM-623-1-ver-soporte-imagen-real.png`).
  - "Descargar" funciona tanto en un reporte pendiente de aprobación (`SCRUM-623-2-descargar-reporte-pendiente.png`)
    como en uno finalizado, y este último muestra "Aprobado por Mark Bekhar el ..." en el detalle
    (`SCRUM-623-3-descargar-reporte-finalizado.png`).
- **Extra (Senior Review) — Configuración Fiscal**: el campo "Caja chica — máximo de intentos de
  rechazo" existe, muestra el valor real (2, `AdminContFiscalSettings::current()`), es editable
  tras entrar a modo edición, y guardar un valor nuevo (3) no rompe la pantalla — flujo completo
  con el banner de confirmación explícita ya existente en el resto de Configuración Fiscal
  (`EXTRA-1-config-fiscal-campo-visible.png`, `EXTRA-1-config-fiscal-guardado-3.png`). Revertido a
  2 al final del test para no alterar el resto del ambiente local.
- **Sin emojis** — grep sobre todos los componentes/i18n nuevos de este batch
  (`DetalleLineaCajaChicaModal.tsx`, `DetalleReportePettyCashModal.tsx`,
  `VerSoporteCajaChicaModal.tsx`, `PettyCashPage.tsx`, `FiscalConfigPage.tsx`, ambos locales de
  `adminContab.json`) no encontró ningún glyph de emoji — el historial usa `IcoAlertTriangle`/
  `IcoCheck` (SVG), confirmado también visualmente en las capturas de arriba.

## No-bloqueante — observaciones menores

- **`estados.rechazado_temporal` es un literal fijo "Rechazado (1/2)"**, no interpolado con el
  contador real ni con el máximo configurable
  (`atlanticerp-frontend/src/i18n/locales/es/adminContab.json`). Bajo el default actual
  (`petty_cash_max_intentos_rechazo=2`) esto siempre es correcto porque el estado "temporal" solo
  existe en `intentos_rechazo=1` (con max=2, cualquier valor ≥2 ya es permanente) — pero si algún
  día Mark sube el máximo a 3+ vía el campo de Configuración Fiscal recién verificado arriba, una
  línea con 1 o 2 intentos (de un máximo de 3) seguiría mostrando literalmente "(1/2)", texto
  incorrecto. No bloquea nada hoy (el default nunca cambia solo, y el batch no pide verificar
  comportamiento bajo un máximo distinto de 2) pero vale la pena registrarlo — la corrección real
  es interpolar `{{intentos}}/{{max}}` en el string en vez de un literal.
- **`puede_rechazar` calculado en el backend (`AdminContPettyCashController::expenseDetail()`) pero
  nunca consumido por `DetalleLineaCajaChicaModal.tsx`** — no es un bug funcional (rechazar sigue
  disponible correctamente desde el detalle de reporte, que es donde la spec lo pide), es un campo
  muerto en el contrato de API. Cosmético, no bloqueante.
- **Seed local de Configuración Fiscal** — `razon_social`/`nombre_comercial`/`ruc`/`dv`/
  `direccion_fiscal` estaban vacíos en el Docker local antes de este pase (nunca se habían editado
  desde que existe la pantalla), lo que bloqueaba el guardado del formulario completo (son
  `required` a nivel de todo el form). No es un bug de este batch — es un gap de seed local. Se
  completaron con datos de placeholder (`Atlantic, S.A.`, etc.) durante la verificación del
  campo de Caja Chica para poder ejercitar el guardado real; quedan así en el ambiente local salvo
  que alguien los pise después.

## Veredicto

**NO pasa a QA todavía.** SCRUM-620 (REQ-543) tiene un hallazgo CRÍTICO que tumba toda la
aplicación a pantalla en blanco al abrir el panel "Rechazados" — bloquea la verificación de
SCRUM-620 y, en cascada, de SCRUM-621 (el único punto de entrada a "Reabrir" vive en ese panel).
Backend de ambos tickets confirmado correcto por API directa; el fix es acotado a una línea en
`atlanticerp-frontend/src/api/adminContabApi.ts` (desenvolver `{lineas: [...]}` en vez de asumir un
array plano — mismo patrón exacto que el CRÍTICO ya resuelto en Batch 20).

SCRUM-618, SCRUM-619, SCRUM-622, SCRUM-623 y el campo extra de Configuración Fiscal pasan limpio,
probados activamente contra el navegador real incluyendo los caminos de ruptura (motivo vacío,
permisos cruzados, reporte disuelto, modal anidado, reporte rechazado).

## Pendiente tras el fix

Re-correr el checklist COMPLETO de SCRUM-620/621 (no solo el punto que falló) una vez que
Frontend Dev corrija `adminContabApi.ts` — el spec permanente
(`e2e/preqa-batch21-scrum618-623-cajachica-20260827.spec.ts`) ya cubre ambos tickets end-to-end
(incluyendo el caso Mark-no-puede-reabrir vía 403 real y Felix-sí-puede-con-historial-completo);
solo falta que el panel deje de crashear para que esas aserciones de UI puedan correr. El resto
del checklist (618/619/622/623/Extra) no hace falta repetirlo salvo que el fix de esta línea toque
algo más — el spec queda como test permanente para la próxima corrida completa.

---

## Re-verificación 2026-08-27 (post-fix) — PASA LIMPIO

Corrido en LOCAL (`localhost:8090`) contra `dev` con el fix ya aplicado —
`atlanticerp-frontend/src/api/adminContabApi.ts` commit `1119aad` (`rejected: () => api.get<{ lineas:
PettyCashRejectedLine[] }>(...).then(r => r.data.lineas)`), build de nginx regenerado con ese
commit. Checklist COMPLETO re-corrido, no solo SCRUM-620/621.

### Verificación del fix en sí

- Código confirmado en disco: la línea de `adminContabApi.ts` desenvuelve `{ lineas: [...] }`
  correctamente, con un comentario que documenta el hallazgo CRÍTICO original.
- Panel "Rechazados" verificado en los 4 roles (Mark, Felix, Yaneth, Daniela) — carga sin
  crashear, sin pantalla en blanco, sin `pageerror` en consola.
- SCRUM-620 (REQ-543) y SCRUM-621 (REQ-544) — antes bloqueados por el crash — ahora verificados
  end-to-end vía navegador real, camino de ruptura incluido (Mark sin botón "Reabrir" + 403 real
  al forzar por API; Felix con motivo obligatorio, reapertura exitosa, historial conservado).

### Ajuste al spec permanente (self-seeding)

El spec dejado en la corrida anterior asumía datos (Reportes 0001-2026..0004-2026 + línea
standalone) sembrados FUERA del archivo, vía API "antes de la corrida" — viola la regla dura del
proyecto (`feedback_e2e_permanent_tests_must_self_seed.md`: un test permanente con folios/IDs
hardcodeados se rompe en la siguiente corrida). Confirmado en vivo: correrlo una 2da vez con el
estado real post-1ra-corrida hacía fallar el test 1 porque el Reporte 0001-2026 ya no tenía las 3
líneas pendientes esperadas — no por ningún bug, sino porque el fixture ya no estaba en su estado
inicial asumido.

**Corregido** — `beforeAll` ahora crea sus propios datos frescos vía llamadas reales a la API
(login, `POST .../petty-cash/expenses` multipart con foto real, `POST .../petty-cash/reports`,
`PUT .../reports/{numero}/approve`, `POST .../expenses/{id}/attachments`) en vez de asumir
folios/IDs fijos:
- Reporte A (3 líneas) / Reporte B (1 línea) / Reporte C (1 línea, se mantiene pendiente toda la
  corrida) / Reporte D (1 línea, pre-aprobado por Mark) + 1 línea standalone con 2 soportes (un
  stub y un PNG real decodificable, generado a propósito para este spec — 4×4 rojo, para el test
  de "Ver soporte muestra la imagen real").
- Nombres de proveedor únicos por corrida (`Proveedor{A1,A2,A3,B1,Standalone}-${RUN_ID}`,
  `RUN_ID = Date.now()`) — seguro correr el spec repetidas veces sin colisionar con datos de
  corridas previas (ni con los huérfanos que la propia sesión de depuración dejó mientras se
  afinaba el seeding — quedaron como ruido inofensivo en el Docker local, mismo criterio que
  otros gaps de seed ya documentados en este proyecto).
- Guarda defensiva en `beforeAll`: si `petty_cash_max_intentos_rechazo` no está en 2 al arrancar
  (ej. una corrida previa quedó a mitad del test "Extra" sin revertir), el spec falla rápido con
  un mensaje explícito en vez de dejar que revienten aserciones de UI downstream sin contexto.
- **Verificado el fix de re-run**: corrida 2 veces consecutivas contra el mismo ambiente, 21/21 en
  verde ambas veces, sin intervención manual entre corridas.

Durante este ajuste aparecieron 2 problemas del TEST en sí (no del código de producto — ambos
corregidos en el spec, documentados acá porque casi se reportan como hallazgos falsos):
1. La descripción generada por el seed incluía el nombre del proveedor
   (`"... — ${proveedor}"`), lo que producía colisión de "strict mode" en Playwright
   (`getByText` matcheaba tanto la celda de proveedor como la de descripción) — corregido,
   la descripción ya no repite el nombre del proveedor.
2. El test de reapertura (SCRUM-621.2) asumía que el modal de detalle se quedaba abierto
   mostrando el historial actualizado tras confirmar — en realidad
   `DetalleLineaCajaChicaModal.onSuccess` cierra el modal automáticamente
   (`DetalleLineaCajaChicaModal.tsx:97`, `onClose()` tras la mutación) y vuelve a la pestaña de
   fondo (Rechazados, de donde se abrió), donde la línea reabierta correctamente YA NO aparece
   (`invalidateQueries` de `PETTY_CASH_REJECTED_KEY`, `useReopenPettyCashExpense` en
   `useAdminContab.ts`). El test ahora navega explícitamente a Pendientes tras el cierre del
   modal, y reabre el detalle desde ahí para verificar que el historial sí conserva los 2
   rechazos + la reapertura — el comportamiento de la app es correcto, la asunción original del
   test estaba equivocada.

### Checklist completo — todos los ítems

- **SCRUM-618/REQ-541** (rechazo de línea individual / reporte completo, exclusivo Mark) — OK,
  igual que la 1ra corrida (motivo obligatorio, líneas restantes intactas, reporte se disuelve
  correctamente, Felix sin acceso ni por UI ni por API).
- **SCRUM-619/REQ-542** (regla de 2 intentos) — OK: 1er rechazo vuelve a Pendientes con "1/2"
  visible; 2do rechazo de la misma línea pasa PERMANENTEMENTE a Rechazados.
- **SCRUM-620/REQ-543** (panel "Rechazados") — **antes CRÍTICO, ahora OK** en los 4 roles: solo
  líneas con exactamente 2 intentos, líneas con 1 intento correctamente ausentes.
- **SCRUM-621/REQ-544** (reabrir) — **antes bloqueado por el crash, ahora OK**: Mark sin botón y
  403 real si se fuerza por API; Felix reabre con motivo obligatorio, línea vuelve a Pendientes
  con intentos=0, historial conserva los 2 rechazos + la reapertura.
- **SCRUM-622/REQ-545** (modal unificado, 3 contextos) — OK: standalone con Editar (solicitante no
  editable), anidado dentro de reporte pendiente (overlays apilados, sin Editar, con Agregar
  soporte), dentro de reporte finalizado (solo lectura total).
- **SCRUM-623/REQ-546** (ver soporte + descargar) — OK: imagen real decodifica (`naturalWidth >
  0`), descargar funciona en reporte pendiente y finalizado (con "aprobado por Mark" visible).
- **Extra — Configuración Fiscal** — OK: campo de máximo de intentos de rechazo editable, guardar
  no rompe la pantalla, revertido a 2 al final.
- **Sin emojis** — sin cambios de código de producto en esta re-verificación, no re-auditado
  (nada nuevo que auditar).

## Veredicto — Re-verificación 2026-08-27

**PASA LIMPIO.** Los 21 tests del spec permanente en verde, corridos 2 veces consecutivas sin
intervención manual para confirmar que el spec ahora se auto-siembra de forma segura. El
hallazgo CRÍTICO original (RechazadosPanel crasheando la SPA) está corregido y verificado en los
4 roles. Sin hallazgos nuevos. Batch 21 (SCRUM-618→623) queda listo para pasar a QA.
