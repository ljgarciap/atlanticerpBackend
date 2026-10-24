# SCRUM-1116 — Anulación completa debe considerar el valor ya acreditado por notas previas

**Date**: 2026-10-06
**Ticket**: SCRUM-1116 (Story, épica SCRUM-331 Admin&Cont, prioridad Highest, reportado por Gerencia Test, asignado a Luis)
**Status**: spec del Analista + diseño del Arquitecto. Decisiones de negocio D1-D4 **resueltas por Luis el 2026-10-06** (sección 6); diseño **APROBADO por Luis el 2026-10-06**; desglose del PM al final. Sin código.
**Relacionada**: SCRUM-1060 (notas de crédito, mismo módulo), REQ-482→487 y REQ-484 RN4 (Batch 11), REQ-494 (aprobación de Mark)

## 1. Problema

Cuando una factura ya tiene una nota de crédito o devolución parcial y luego se registra una **Anulación completa**, el sistema vuelve a tomar el **valor total original** de la factura.
Con una factura de 1.000, una nota previa de 300 y una anulación completa, la nueva nota sale por **1.000** (no por 700): acumulado 1.300 sobre una factura de 1.000.

### Dónde está el defecto (verificado en el código, `dev` b491aba4)
| Archivo | Línea | Qué hace hoy |
|---|---|---|
| `Services/CreditNoteService.php` | `register()` ~247 | `$monto = tipo === ANULACION_COMPLETA ? $facturaOrigen->monto : (float) $data['monto']` — siempre el total de la factura (REQ-484 RN4) |
| `Http/Requests/RegisterCreditNoteRequest.php` | ~45 | `$montoEfectivo = anulación ? $facturaOrigen->monto : $montoInput` — mismo supuesto para decidir si se exige `resultado` |
| `components/admin-contab/RegistrarNotaCreditoModal.tsx` (frontend) | ~284 | `monto = esAnulacion ? facturaSeleccionada.monto : Number(montoInput)` |
| `RegistrarNotaCreditoModal.tsx` | ~292-300 | `esAnulacionNuncaCobrada` exige `saldo_pendiente === factura.monto`: con una nota previa deja de cumplirse y el formulario pide elegir "devuelto / saldo a favor" por un excedente que **no existe** |

**Efecto en cadena:** como el `monto` (1.000) supera el saldo por cobrar (700), el servicio calcula un "excedente" de 300 y lo trata como dinero a devolver o saldo a favor del cliente (`applyToBalance`), es decir, **acredita 300 que nunca se facturaron**.

### Evidencia medida (solo lectura, 2026-10-06)
| Ambiente | Notas | Facturas cuyo acumulado de notas supera el valor de la factura |
|---|---|---|
| **test** | 17 (4 anulaciones aplicadas, 1 pendiente) | **2**: F-0036 (NC-0024 devolución 128,76 + NC-0025 anulación 137,77 = 266,53) y F-0037 (NC-0026 + NC-0027 pendiente, mismo patrón). Exceso 128,76 en cada una |
| **prod** | 1 (devolución 128,76 aplicada) | 0, **pero** esa factura es de 137,77: queda un valor acreditable de **9,01** y hoy una anulación completa saldría por 137,77 — **el defecto reproduciría en producción** |
| dev | 0 | 0 |

## 2. Concepto clave — no confundir dos "saldos"

`saldo_aplicado` suma **cobros confirmados** y también las **notas ya aplicadas** (`applyToBalance`, línea ~444: `saldo_aplicado += monto_aplicado_saldo`). Por eso el `saldoPendiente = monto − saldo_aplicado` del código es el **saldo por cobrar**, y **no** es lo que el ticket llama "saldo pendiente".

El ticket define: *saldo pendiente = valor total de la factura − notas crédito/devoluciones previamente aplicadas*. Para evitar la ambigüedad esta spec lo llama **valor acreditable restante (VAR)**:

> **VAR = `factura.monto` − Σ `monto` de las notas de la factura (de todos los tipos) en estado `aplicada` o `pendiente_aprobacion`** (las `rechazada` no cuentan).

| | Saldo por cobrar (hoy) | VAR (lo que pide el ticket) |
|---|---|---|
| Fórmula | `monto − saldo_aplicado` | `monto − Σ notas` |
| Descuenta cobros | sí | **no** |
| Descuenta notas | sí (las aplicadas) | sí |

**Por qué importa:** una factura de 1.000 con un cobro de 500 y sin notas debe poder anularse por **1.000** (se devuelven los 500 cobrados: excedente = 1.000 − 500). Si la anulación usara el saldo por cobrar (500) se rompería esa devolución, que es el comportamiento correcto de REQ-484 y "debe mantenerse".

### Ejemplos (los del ticket y los de cobro, todos con factura 1.000)
| Notas previas | Cobros | VAR = monto anulación | Saldo por cobrar tras anular | Excedente (devolver o saldo a favor) |
|---|---|---|---|---|
| ninguna | 0 | **1.000** | 0 | 0 — *Caso 1 del ticket, no cambia* |
| NC 300 | 0 | **700** | 0 | 0 — *Caso 2 / ejemplo esperado* |
| NC 300 + Devolución 200 | 0 | **500** | 0 | 0 — *tercer caso del ticket* |
| NC 1.000 (ya anulada) | 0 | **0 → se rechaza** | — | — *"saldo 0: no se permite otra anulación"* |
| ninguna | 500 | **1.000** | 0 | 500 (se devuelve lo cobrado) — *comportamiento actual correcto* |
| NC 300 | 500 | **700** | 0 | **500** (se devuelve lo cobrado) |

Nota de la última fila: el saldo por cobrar previo es 1.000 − (500 cobrados + 300 de nota) = 200. La anulación de 700 aplica 200 al saldo y deja un excedente de 500, que es exactamente lo cobrado: `excedente = monto − saldo por cobrar = 700 − 200 = 500`. El total acreditado queda en 300 + 700 = 1.000, igual al valor de la factura.

## 3. Reglas de negocio (RN)

- **RN1 — VAR.** Se calcula como arriba, en una sola función (fuente única de verdad). Cuentan las notas `aplicada` y `pendiente_aprobacion` de **cualquier tipo** (descuento, devolución, anulación y las de "Corrección de datos"), no solo la última (criterio de aceptación explícito del ticket). *(D1 resuelta: las pendientes cuentan.)*
- **RN2 — Anulación completa.** El monto de la nota es **siempre el VAR**, nunca lo que mande el cliente (se sigue ignorando cualquier valor enviado, como hoy). Si no hay notas previas, VAR = total de la factura: comportamiento actual intacto.
- **RN3 — VAR = 0.** No se permite registrar una anulación completa: 422 con mensaje claro ("La factura ya está completamente acreditada por notas previas").
- **RN4 — Invariante global.** Para **cualquier tipo**, `Σ notas previas + monto nuevo ≤ factura.monto`; si no, 422 indicando el disponible. Es la regla "en ningún escenario el total acumulado supera el valor de la factura". *(D2 resuelta: aplica a los 3 tipos.)*
- **RN5 — Excedente sin cambios.** `excedente = monto − saldo por cobrar` solo existe cuando hay cobros por devolver; se mantienen `resultado` (devuelto / saldo a favor) y la cuenta de salida exactamente como hoy.
- **RN6 — Aprobación (`decide`).** Las anulaciones siempre pasan por Mark. Al aprobar se **revalida bajo bloqueo**: una anulación se aprueba solo si su monto sigue igual al VAR vigente (excluyendo la propia nota); cualquier otro tipo solo si `Σ aplicadas (sin ella) + monto ≤ factura.monto`. Si no, 422 pidiendo rechazar y registrar de nuevo. Evita que el rechazo de una nota previa deje una "anulación completa" a medias.
- **RN7 — Concurrencia.** El cálculo y la validación se hacen dentro de la transacción de `register()`, con la factura ya bloqueada (`lockForUpdate`), como ya se hace con el saldo.
- **RN8 — "Corrección de datos".** Anula la factura y emite una de reemplazo; su monto sale del desglose de la cotización, no del total de la factura (`registerCorreccion`, ~1051). Con notas previas aparece el mismo exceso. *(D3 resuelta: se bloquea con un mensaje claro.)*
- **RN9 — Sin migración de datos.** No se modifican notas ya emitidas (son documentos contables y posiblemente certificados). Las 2 facturas de test que ya exceden quedan como evidencia; el cambio es hacia adelante. *(D4 resuelta: no se tocan; Mark rechaza la NC-0027 pendiente.)*

## 4. Criterios de aceptación

Los 8 del ticket, mapeados, más los que añade este diseño:

| # | Criterio | Cómo se prueba |
|---|---|---|
| T1 | Factura sin notas previas se anula por su valor total | factura 1.000 → nota 1.000, saldo por cobrar 0 |
| T2 | Con una nota parcial, la anulación es solo por el saldo | 1.000 con NC 300 → nota **700** |
| T3 | 1.000 con 300 aplicados → 700 | idem T2 |
| T4 | 1.000 con 300 + 200 → 500 | NC 300 + devolución 200 → nota **500** |
| T5 | El acumulado nunca supera el valor de la factura | RN4 en los 3 tipos, 422 si excede |
| T6 | Nunca saldo negativo por una anulación | tras anular, saldo por cobrar ≥ 0 |
| T7 | Con VAR 0 no se permite otra anulación | 422 |
| T8 | Sin movimientos previos el comportamiento actual no cambia | regresión del caso T1 y de las suites existentes |
| T9 | Considera **todas** las notas previas, no la última | 3 notas previas de distinto tipo |
| A1 | Cobrada parcialmente + anulación: se devuelve lo cobrado | 1.000, cobro 500 → nota 1.000, excedente 500 |
| A2 | Nota pendiente de aprobación reserva valor | pendiente 300 → anulación 700 |
| A3 | Rechazar la nota previa antes de aprobar la anulación → no se puede aprobar la desactualizada | RN6 |
| A4 | Dos registros concurrentes sobre la misma factura | el segundo ve el VAR ya reducido |
| A5 | El formulario no pide "devuelto / saldo a favor" cuando no hay excedente | frontend |
| A6 | Caso real de prod: factura 137,77 con devolución 128,76 → anulación **9,01** | prueba con esos números |

## 5. Diseño técnico (Arquitecto)

**Sin cambios de esquema.** `credit_notes` ya tiene `factura_origen_id`, `monto`, `estado`, `tipo`. Recomendación menor: índice sobre `credit_notes(factura_origen_id, estado)` para el agregado (tabla pequeña hoy; barato y seguro).

### Backend
1. **Fuente única** — `CreditNoteService::valorAcreditableRestante(AdminContInvoice $f, ?int $excluirNotaId = null): float` y su variante en lote `valoresAcreditables(list<int> $facturaIds): array<int, array{notas_previas: float, acreditable: float}>` (un solo `SUM(monto) GROUP BY factura_origen_id` para la lista de facturas: **sin N+1** en `invoicesForClient`). Redondeo a 2 decimales como el resto del módulo.
2. `register()`: tras bloquear la factura, `acreditable = valorAcreditableRestante(...)`; anulación → `$monto = $acreditable` (RN2); `$acreditable <= 0` en anulación → `ValidationException` 422 (RN3); cualquier tipo con `monto > acreditable` → 422 (RN4). Se conserva intacto el cálculo de `excedente` contra el saldo por cobrar (RN5).
3. `RegisterCreditNoteRequest`: `montoEfectivo` usa el VAR (para decidir `resultado`); validación previa RN3/RN4 con mensajes en español, para que el 422 salga antes de la transacción.
4. `decide()`: revalidación RN6 bajo el bloqueo que ya existe.
5. `invoicesForClient()`: agrega por fila `monto_acreditable` y `notas_previas_total` (campos nuevos, **aditivos**: el frontend viejo no se rompe).
6. `devolucionDetail()` (`monto_sugerido`, SCRUM-1060): acotar la sugerencia al VAR para no precargar un monto que luego se rechace.
7. Corrección de datos: según D3.

### Frontend (`RegistrarNotaCreditoModal.tsx`)
- Anulación: `monto = facturaSeleccionada.monto_acreditable` (no `monto`); banner "Notas previas: X — se anulará Y"; `esAnulacionNuncaCobrada` pasa a basarse en el VAR (sin excedente cuando monto = saldo por cobrar); si el VAR es 0 el envío queda bloqueado con el motivo visible.
- Descuento y devolución: tope visible `máx. = monto_acreditable` y validación en el cliente (el servidor sigue siendo la autoridad).
- Mostrar el error 422 del servidor (el modal ya trae el patrón de mostrar `message`).

### Orden de despliegue
Backend primero (el campo nuevo es aditivo y la regla es más estricta), luego el frontend. Sin migración de datos ni cambio de contrato destructivo.

### Pruebas
- Unitarias de `valorAcreditableRestante` (estados, tipos, exclusión de la propia nota, redondeo).
- Feature de `register`/`decide` con los casos T1-T9 y A1-A6, incluida concurrencia y el caso 137,77 / 128,76 / 9,01.
- Regresión de las suites actuales de notas de crédito.
- Vitest del modal (monto, banner, bloqueo con VAR 0, sin pedir `resultado` sin excedente).
- Pre-QA con los 2 casos reales de test (F-0036/F-0037) y el de prod.

### Riesgos
- **Regresión de REQ-484** (cobros + anulación): cubierta por A1 y la suite existente.
- **Notas pendientes** que reservan valor: una nota olvidada sin aprobar bloquea valor; mitigado porque Mark puede rechazarla.
- **Urgencia en prod:** la factura de 137,77 con devolución de 128,76 está expuesta hasta que se despliegue.

## 6. Decisiones de negocio (resueltas por Luis, 2026-10-06 — todas con la opción recomendada)

- **D1 — Las notas pendientes de aprobación SÍ cuentan como valor reservado.** Evita que dos notas pendientes se aprueben y sumen más que la factura; si Mark rechaza una, el valor se libera solo (RN1, RN6).
- **D2 — "El acumulado nunca supera el valor de la factura" aplica a los 3 tipos** (descuento, devolución y anulación), no solo a la anulación completa (RN4).
- **D3 — "Corrección de datos" con notas previas se BLOQUEA con un mensaje claro** (no se permite corregir datos de una factura que ya tiene notas; se indica anular por el saldo y refacturar a mano). No se inventa una regla fiscal nueva (RN8). Implica además: `previewCorreccion` y `registerCorreccion` validan lo mismo, y el botón del frontend muestra el motivo.
- **D4 — Datos de test que ya exceden (F-0036, F-0037): no se tocan.** Son evidencia del defecto y datos de prueba de Daniela. Mark debe **rechazar la NC-0027 pendiente** (acreditaría 128,76 de más); con el arreglo desplegado, aprobarla quedaría bloqueado por la revalidación de RN6 (RN9).

### Criterios de aceptación añadidos por D3
| # | Criterio | Cómo se prueba |
|---|---|---|
| C1 | Corregir datos de una factura SIN notas previas funciona como hoy | regresión de `registerCorreccion` |
| C2 | Corregir datos de una factura CON notas previas (aplicadas o pendientes) responde 422 con el motivo | caso 1.000 con NC 300 |
| C3 | La vista previa de la corrección avisa del bloqueo antes de confirmar | `previewCorreccion` |

## 7. Desglose del PM (2026-10-06)

Roles: **BE** Backend Dev, **FE** Frontend Dev, **QA** Senior Review + Pre-QA, **DO** DevOps, **TW** Tech Writer. Se implementa en el mismo hilo (roles narrados, sin subagentes de implementación) y las revisiones independientes (Senior Review, Pre-QA) como subagentes reales, igual que en SCRUM-1114. Cada tarea cierra con tests en verde y PHPStan/tsc limpios; el gate completo corre antes de cada push.

| # | Tarea | Rol | Depende de | Criterios que cubre |
|---|---|---|---|---|
| P0 | **Base limpia.** Backend desde `dev` actual (b491aba4). En el frontend hay un commit local ajeno sin publicar (`9f3c2af`, specs Pre-QA de la nota por devolución): trabajar desde `origin/dev` (worktree o rama de trabajo) y no publicarlo por arrastre | PM | — | — |
| P1 | **Fuente única del VAR.** `CreditNoteService::valorAcreditableRestante()` (con `excluirNotaId`) y `valoresAcreditables()` en lote (un solo `SUM … GROUP BY factura_origen_id`). Migración del índice `credit_notes(factura_origen_id, estado)` (revisión del Arquitecto). Tests unitarios: estados, tipos, exclusión de la propia nota, redondeo | BE | P0 | T9, A2 |
| P2 | **`register()`**: anulación → `monto = VAR`; VAR 0 → 422; invariante RN4 en los 3 tipos → 422 con el disponible; el excedente (RN5) y la transacción con `lockForUpdate` se conservan; verificación bajo bloqueo | BE | P1 | T1-T8, A1, A4, A6 |
| P3 | **`RegisterCreditNoteRequest`**: `montoEfectivo` con el VAR (para exigir `resultado` solo si hay excedente real) y validación previa RN3/RN4 con mensajes en español | BE | P1 | T5, T7, A5 |
| P4 | **`decide()`**: revalidación RN6 bajo bloqueo (anulación: monto == VAR vigente sin ella; otros tipos: Σ aplicadas + monto ≤ factura). Tests de rechazo previo y de concurrencia entre pendientes | BE | P2 | A2, A3 |
| P5 | **Contrato para el frontend**: `invoicesForClient()` devuelve `monto_acreditable` y `notas_previas_total` por factura (campos aditivos, sin N+1); `devolucionDetail()` acota `monto_sugerido` al VAR | BE | P1 | A5, A6 |
| P6 | **"Corrección de datos" bloqueada con notas previas (D3)** en `previewCorreccion` y `registerCorreccion` (y revalidación al aprobar), con mensaje claro y tests de regresión sin notas | BE | P1 | C1, C2, C3 |
| P7 | **Frontend**: tipos (`adminContab.ts`), `RegistrarNotaCreditoModal` (monto de anulación = `monto_acreditable`; banner "notas previas / se anulará"; `esAnulacionNuncaCobrada` por VAR; bloqueo con VAR 0; tope visible y validación en descuento/devolución; mostrar el 422 del servidor; bloqueo de la corrección con el motivo) y textos es/en | FE | contrato de P5 (nombres fijados en la spec: ya se puede empezar) | T2-T4, T7, A5, C3 |
| P8 | **Tests del frontend** (vitest del modal): monto, banner, bloqueo, sin pedir `resultado` sin excedente, mensaje del servidor | FE | P7 | A5 |
| P9 | **Gate local** (PHPStan + suite completa backend; vitest + build frontend) y **Senior Review independiente** sobre el diff completo, con los hallazgos corregidos y el gate repetido | QA | P2-P8 | — |
| P10 | **Pre-QA en dev** con los casos de la spec (T1-T9, A1-A6, C1-C3) sobre los endpoints reales. **Riesgo a verificar antes de crear notas:** al quedar `aplicada` cada nota llama a `certificarNotaCredito`; confirmar que en dev la facturación electrónica no envía nada real (`fe_estado = no_aplica`/sandbox) y usar solo datos de prueba | QA | P9 | todos |
| P11 | **Despliegue**: dev → test → prod, cada uno con gate y aprobación de Luis; backend primero y frontend después. En prod, **verificación en solo lectura** del caso real: el VAR de la factura de 137,77 con devolución de 128,76 debe dar 9,01 (sin crear notas) | DO | P10 | A6 |
| P12 | **Documentación**: spec final, runbook breve de la regla, línea en el `CLAUDE.md` del módulo y entrada en `docs/changelog.md` | TW | en paralelo | — |
| P13 | **Seguimiento fuera del código**: Mark rechaza la NC-0027 pendiente en test (D4); comentario en SCRUM-1116 con el avance | Luis / PM | — | D4 |

**Camino crítico:** P0 → P1 → P2/P3 → P4 → P6 → P5 → P9 → P10 → P11. El frontend (P7-P8) corre en paralelo desde que el contrato de P5 está fijado, y converge en P9.

**Riesgos del PM**
- **Coordinación:** otra sesión trabajó hoy en el mismo archivo (`CreditNoteService`, commit `9096974d`, monto sugerido de la devolución). Hacer `git pull` antes de cada tarea y no tocar sus partes salvo P5.
- **Urgencia en prod:** la factura de 137,77 con devolución de 128,76 sigue expuesta hasta P11; se prioriza P11 en cuanto el gate y el Pre-QA estén verdes.
- **Regresión de REQ-484** (anular una factura ya cobrada devuelve lo cobrado): cubierta por A1 y por la suite existente, que se corre completa en P9.
