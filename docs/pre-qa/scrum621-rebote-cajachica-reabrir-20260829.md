# Pre-QA — Re-verificación de rebote SCRUM-621 (REQ-544) — Caja Chica: reabrir línea rechazada

Corrido en LOCAL (`localhost:8090`, nginx sirviendo el build fresco de React + proxy `/api` →
Laravel, build regenerado 2026-08-29 tras el fix) contra código ya pusheado y desplegado a
`dev.atlanticerp.ai` (backend `b87f1bf`, frontend `f33525d`). Suite completa de tests automatizados en
verde (2852 backend, 1645 frontend) antes de esta pasada. Test permanente reutilizado:
`atlanticerp-frontend/e2e/preqa-batch21-scrum618-623-cajachica-20260827.spec.ts` (sin cambios — sigue
cubriendo el escenario, no hizo falta agregar uno nuevo).

## Contexto del rebote

QA (marly.rangel, probado con Mark Bekhar + una cuenta "QA Admin") reportó CRÍTICO el 2026-08-29:
"En el detalle de una línea en Rechazados solo aparecen botones Cerrar y Editar. No existe un
botón Reabrir dedicado" — probado con Mark (Gerencia, correcto que no lo vea) y QA Admin (rol
`lider_admin_contab`, mismo rol que Felix — debía verlo y no lo vio).

## Investigación de causa raíz (antes de esta pasada)

Descartado en vivo contra `dev.atlanticerp.ai`:
- **Permisos de la cuenta de prueba** — `qa@atlantic.com.pa` (id 57) tiene `role_key =
  lider_admin_contab`, idéntico a Felix. `isFelixOrYaneth()` la reconoce correctamente.
- **Build de frontend desactualizado** — el bundle servido en dev ya incluía el código de
  `puede_reabrir`/Reabrir, construido antes de que QA probara.
- **Cálculo del backend** — simulado en vivo (Tinker) contra la línea y usuario exactos que usó
  QA: `ubicacion: rechazados`, `editable: true`, y el claim `role` del JWT resuelve a
  `lider_admin_contab` — el cálculo de `puede_reabrir` ya daba `true`.

Encontrados y corregidos 2 gaps reales de consistencia (no confirmados como LA causa exacta del
síntoma reportado — el cache de React Query es por navegador/sesión, así que la staleness de la
sesión de Mark no explica automáticamente lo que vio la sesión separada de QA Admin — pero ambos
son defectos reales que corresponde tener corregidos de todas formas):
1. `useRejectPettyCashReport()` no invalidaba el cache de `expenseDetail()` de las líneas del
   reporte rechazado (a diferencia de `useRejectPettyCashExpense()`, que sí lo hace).
2. `AdminContPettyCashService::rejected()` filtraba solo por `intentos_rechazo`, sin exigir
   `report_id IS NULL` como sí hace `locationOf()`.

## Esta pasada — adversarial, batch completo re-corrido

No alcanza con confirmar el camino feliz de SCRUM-621 aislado — se corrió el spec permanente
completo del batch (21 tests, SCRUM-618→623 + Extra) para descartar que el fix haya introducido
una regresión en el resto del flujo de Caja Chica.

- **SCRUM-618** (rechazo línea/reporte, exclusivo Mark) — OK, sin cambios respecto a la
  verificación anterior.
- **SCRUM-619/REQ-542** (regla de 2 intentos, incluye rechazo vía "reporte completo" — el
  mecanismo real que dispara el gap corregido) — OK: 2do rechazo pasa PERMANENTEMENTE a
  Rechazados.
- **SCRUM-620** (panel Rechazados, 4 roles) — OK.
- **SCRUM-621/REQ-544 (el ticket del rebote)** — OK en los 2 escenarios adversariales clave:
  - Mark NO ve el botón Reabrir (ni por UI ni por API — 403 real).
  - Felix, en una sesión fresca separada (login propio, sin reload a mitad de flujo), abre el
    detalle de la línea que llegó a Rechazados vía "rechazar reporte completo" (el mismo camino
    que usó Mark en el escenario reportado) y el botón Reabrir aparece, exige motivo, resetea el
    contador a 0, conserva el historial completo (2 rechazos + reapertura).
- **SCRUM-622/SCRUM-623** (modal unificado, ver/descargar soporte) — OK, sin regresión.
- **Extra — Configuración Fiscal** — OK.

Corrido 1 vez completa, 21/21 verde, sin intervención manual.

## Lo que sí funciona (explícito, no "todo bien")

- Gate de permiso de Reabrir por rol real (backend, no solo botón escondido en UI).
- Motivo obligatorio al reabrir, con validación visible.
- Contador de intentos vuelve a 0 tras reabrir.
- Historial conserva TODOS los rechazos anteriores + la reapertura, nunca los pisa.
- Línea reabierta vuelve a Pendientes y desaparece de Rechazados de inmediato.
- `rejected()` sigue sin listar líneas que técnicamente no deberían estar ahí (test de regresión
  nuevo, `test_rejected_no_incluye_lineas_que_siguen_asociadas_a_un_reporte`).

## Visual Review

No re-comparado contra el mockup (`4I__Admin_Contabilidad_CajaChica.html`) — este fix no tocó
ningún código de renderizado/layout (`DetalleLineaCajaChicaModal.tsx` sin cambios), solo lógica de
invalidación de cache en `useAdminContab.ts` y un filtro de query en el backend. El Visual Review
del botón Reabrir (posición, label, comportamiento del textarea inline) ya se hizo en el Batch 21
original (`docs/pre-qa/batch21-scrum618-623-caja-chica-20260827.md`) y sigue vigente — no hay
superficie visual nueva que auditar.

## Veredicto

**PASA LIMPIO.** Sin hallazgos nuevos. Los 2 gaps de consistencia encontrados durante la
investigación de causa raíz quedaron corregidos, testeados (suite completa 2852+1645 en verde,
más 1 test de regresión nuevo) y desplegados a `dev.atlanticerp.ai`. Ticket queda listo para volver a
`QA` — comentario dejado en Jira con el resumen para marly/QA, pidiendo reintentar el flujo
puntual que rebotó.

**Caveat honesto:** no se pudo reproducir con certeza absoluta la secuencia exacta de clics que
llevó a QA Admin a ver el botón ausente (el cache de React Query no se comparte entre sesiones de
navegador distintas, así que la causa exacta pudo ser un detalle de la sesión de prueba que no se
pudo reconstruir). Si el rebote se repite tras esta pasada, la próxima acción no es seguir
adivinando por lectura de código — es una sesión en vivo con QA para capturar la secuencia real de
clics.
