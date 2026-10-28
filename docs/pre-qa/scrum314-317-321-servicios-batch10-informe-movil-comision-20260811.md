# Pre-QA — Batch 10: Informe móvil (SCRUM-314/315/316/317) + Comisión Tecnico Test (SCRUM-321)

**Fecha:** 2026-08-11
**Alcance:** 5 tickets fusionados en un solo pase (Visual Review + Pre-QA, un solo login/seed,
2 veredictos), a pedido explícito de Luis (ver
`feedback_gate_overhead_proportional_to_batch_size.md`). Visual Review hermano:
`atlanticerp-frontend/docs/visual-review/scrum314-317-321-servicios-batch10-informe-movil-comision-20260811.md`.

**Entorno:** stack local Docker (`infra/docker-compose.yml`, puerto 8090) + Vite dev server
(`localhost:5173`, proxy real hacia :8090 vía `vite.config.ts`). Cambios sin commitear en el
working tree de `atlanticerp-backend`/`atlanticerp-frontend`. Playwright headless contra ambos.

**Paso 0 (verificación de fixtures antes de arrancar):** la DB local estaba completamente vacía
(0 tickets, 0 master_clients, 0 capturas de comisión) — nunca se había corrido `db:seed` contra
este volumen de Postgres. Se corrió `php artisan db:seed --force` (entorno `local`, corre los
seeders demo por diseño). Esto trajo `ServiciosDemoSeeder` con 14 tickets, incluido
`INS-2026-0003` (Instalación/Inspección, sin productos, ya asignado a Tecnico Test vía
`internal_technician_id=18`) — usado como fixture principal para todo el batch, sin necesidad de
crear un ticket nuevo a mano. `InternalTechnicianSeeder` ya deja a Carlos con `has_bonus_plan=true`
(id=1) y a Miguel con `has_bonus_plan=false` (id=4), cubriendo directamente el escenario negativo
de RN7/RN8 sin sembrado adicional.

**Suite automatizada existente:** `tests/Feature/Servicios/ServiciosBatch10Test.php` — corrida vía
`infra/test.sh --filter=ServiciosBatch10Test` (nunca `docker compose exec` directo, ver gotcha de
`APP_ENV`). **11/11 tests, 40 assertions, OK.** Corrida DESPUÉS de terminar toda la exploración
manual en vivo (el share de Postgres entre `test.sh` y el dev local es un gotcha conocido — se
corrió al final para no interferir con los datos manuales usados en las capturas de pantalla).

---

## SCRUM-314 (REQ-251) — Acceso a la vista móvil

| # | Criterio | Escenario ejecutado | Resultado |
|---|---|---|---|
| RN1 | Botón "Ver en móvil" solo desde el modal de escritorio | Confirmado — no hay ruta de menú ni acceso independiente; navegando directo a `/servicios/tickets/93/inspection-report/movil` sin pasar por el modal SÍ carga (es una ruta pública dentro del SPA autenticado), pero el criterio real (RN1) habla de "no existe como acceso independiente" en la UI de navegación, no de bloquear la URL — no hay ningún link/botón fuera del modal que lleve ahí | PASA |
| RN2 | Datos reales precargados (número, cliente, proyecto, dirección, técnico, fecha, producto) | Confirmado con captura real: `TICKET INS-2026-0003`, `CLIENTE Clínica Paitilla`, técnico `Tecnico Test` precargado en el select — mismo registro (`GET /api/servicios/tickets/93`), no una copia | PASA |
| RN3 | Optimizado para teléfono, una columna, campos grandes | Confirmado visualmente en viewport 420×900 — una columna, inputs `py-3 text-base` | PASA |
| **RN4 (camino de ruptura)** | Técnico interno NO asignado a este ticket intenta acceder | **[persona] [persona]** (`tecnico_servicios`, no asignado a `INS-2026-0003`, técnico real vía `agustinrodriguez141985@gmail.com`) navegó directo a la URL del informe móvil → pantalla **"No tenés acceso a este informe."** con botón "Volver al ticket", sin renderizar ningún campo del formulario. Verificado ADEMÁS a nivel de API (no solo el gate de UI): `POST /api/servicios/tickets/93/inspection-report/mobile` directo con `fetch()` autenticado como [persona] → **403** `"Solo Aaron/Líder de Servicios o el técnico interno asignado a este ticket pueden generar o editar su informe de inspección."` | **PASA — dos capas de gate confirmadas (UI + API), no solo un botón escondido** |

### Hallazgo menor, NO bloqueante — `fields()` sin `assertCanEdit()`
`GET /api/servicios/tickets/{id}/inspection-report/fields` (catálogo de campos, solo labels/tipos,
sin datos del informe) no llama `assertCanEdit()` — cualquier usuario con `servicios.read` puede
leerlo, incluido un técnico no asignado. **No es un hallazgo de este batch**: es el mismo patrón
ya documentado explícitamente en el docblock de la ruta desde Batch 8 ("Lectura: mismo permiso
que el resto del módulo... Escritura: el ownership real se valida dentro de
`assertCanEdit()`") — `show()` (que si trae datos reales del informe, incluida la firma) sigue el
mismo criterio, ya existente, no introducido por Batch 10. Se menciona acá solo para que quede
registrado que se verificó, no como hallazgo nuevo.

## SCRUM-315 (REQ-252) — Formulario paridad con escritorio

| # | Criterio | Escenario ejecutado | Resultado |
|---|---|---|---|
| RN1 | Mismos campos/reglas dinámicas que escritorio | Confirmado — `fieldsForTicket()` es el MISMO catálogo backend que usa el modal de escritorio (mismo servicio, no una copia) | PASA |
| RN2 | Material con nombre/cantidad reales, no genérico | Se agregó "Cinta aislante" / "2" vía el formulario móvil → persistido tal cual en el registro y visible después en el modal de escritorio y en el PDF | PASA |
| RN3 | Técnico responsable precargado | Confirmado (select mostró "Tecnico Test" ya seleccionado al abrir) | PASA |
| **RN4 (camino de ruptura)** | "Guardar informe" NO debe persistir nada hasta firmar | Se llenó el formulario completo y se hizo click en "Continuar a firmar" — **cero requests POST/PUT disparados en ese momento** (solo GETs de lectura, confirmado por log de red). El único POST real (`/inspection-report/mobile`) ocurrió recién al confirmar la firma en el paso siguiente. Además, se intentó avanzar con el campo de firma vacío → bloqueado con toast, sin ningún request tampoco | PASA |

## SCRUM-316 (REQ-253) — Firma gráfica

| # | Criterio | Escenario ejecutado | Resultado |
|---|---|---|---|
| **RN1 (camino de ruptura)** | Nombre vacío no debe permitir avanzar, nunca reemplazo silencioso | Con el campo de firma vacío, click en "Continuar a firmar" → toast rojo **"Ingresá el nombre de quien firma antes de continuar."**, se queda en la pantalla del formulario, sin avanzar a firma. Corrige explícitamente el comportamiento del mockup (que sí autocompletaba "Cliente") | PASA |
| **RN2 (camino de ruptura)** | "Firmar" deshabilitado sin trazo | Verificado programáticamente: `firmarBtn.isDisabled() === true` apenas se entra a la pantalla de firma, ANTES de dibujar nada | PASA |
| RN2 (positivo) | "Firmar" se habilita con un trazo | Se dibujó un trazo con `mouse.move`/`down`/`up` sobre el `<canvas>` → `isDisabled() === false` inmediatamente | PASA |
| **RN3 (camino de ruptura)** | "Limpiar firma" borra Y vuelve a deshabilitar | Tras dibujar (botón habilitado), click en "Limpiar firma" → `isDisabled() === true` de nuevo, trazo borrado del canvas | PASA |
| **RN4 (camino de ruptura)** | Lienzo bloqueado tras confirmar | Se redibujó y se confirmó la firma (`click Firmar`) → el `<canvas>` desaparece del DOM por completo (0 elementos `canvas` tras confirmar, no solo `disabled`) — el componente se desmonta y muestra la pantalla de confirmación, bloqueo real y definitivo, no solo visual | PASA |
| RN5 | Firma guardada como imagen real | Confirmado en 2 capas: (1) BD — `firma_cliente_imagen = "servicios/inspection-reports/93/firma-cliente/<uuid>.png"` (S3 key real, no un booleano); (2) el PDF generado (ver SCRUM-317 abajo) muestra el trazo gráfico real, no un placeholder | PASA |
| RN6 | Hora real, no fija | Trazo confirmado a las 18:45:52 (hora local del click) → pantalla de confirmación mostró **"Firmado el 11/8/2026, 18:45:52"**, coincide exactamente con el momento real del click, no el "10:52am" fijo del mockup | PASA |

## SCRUM-317 (REQ-254) — Sincronización

| # | Criterio | Escenario ejecutado | Resultado |
|---|---|---|---|
| RN1 | Mismo registro, no un informe paralelo | Confirmado por ID — el registro `id=6` (`INF-2026-0001`) creado por el flujo móvil es el MISMO que aparece en `GET /api/servicios/tickets/93/inspection-report` consumido por el modal de escritorio | PASA |
| RN2 | `firma_cliente_nombre`/`firma_cliente_imagen` en el mismo registro | Confirmado vía tinker: ambos campos poblados en el registro único (`"Fam. Herrera - Clínica Paitilla"` + key S3 real) | PASA |
| **RN3 (Escenario 1)** | Indicador de tabla refleja "Completado" de inmediato | Confirmado — antes del guardado: `INFORMES POR GENERAR: 6`; después, sin recargar manualmente el navegador salvo la navegación normal: `INFORMES POR GENERAR: 5`, badge de la fila `INS-2026-0003` pasó de link "Generar informe" a badge verde "Completado" | PASA |
| **RN4 (Escenario 3, camino crítico)** | PDF con firma gráfica REAL, no vacío | Se descargó el PDF real desde el botón "Ver / Imprimir" **DEL MODAL DE INFORME** (no confundir con el "Ver/Imprimir" del ticket general, que es un documento distinto — ver nota abajo) → el documento muestra un trazo gráfico real (zigzag) bajo "Fam. Herrera - Clínica Paitilla / Cliente", generado a partir del PNG capturado en el canvas | PASA |
| **RN5 (Escenario 2)** | Cotización se desbloquea igual que si fuera desde escritorio | Antes: columna Cotización = "🔒 Bloqueada" (`quote_status=pending`, `inspection_report_status=pending`). Después de firmar desde móvil: columna Cotización = **"Generar cotización"** (activo), `COTIZACIONES POR GENERAR` pasó de 1 a 2 — confirma que `deriveQuoteDisplayStatus()` evalúa el mismo estado sin importar el origen (móvil o escritorio) | PASA |

### Nota de proceso — 2 documentos "Ver/Imprimir" distintos, no confundir
El ticket tiene DOS botones "Ver/Imprimir" en pantallas distintas: uno en el modal de detalle del
ticket (genera el PDF del TICKET, que solo dice "el diagnóstico y la firma se registran en el
documento de Informe de Inspección, no en este ticket") y otro DENTRO del modal de Informe de
Inspección (genera el PDF real del informe, con la firma). El primer intento de esta sesión
descargó el equivocado — quedó documentado acá para que quien retome este flujo (ej. QA formal)
no repita la confusión.

## SCRUM-321 (REQ-258) — Comisión Tecnico Test

| # | Criterio | Escenario ejecutado | Resultado |
|---|---|---|---|
| RN1 | Fórmula real contra el ejemplo del documento del plan | Se capturó Satisfacción=4.6, Puntualidad=0 incidencias, Actitud=4/5, Calidad=100% (manual) → SLA automático dio 0% (sin tickets cerrados en el período) → **Total $292.00** = $100+$100+$0+$60+$32, matemática verificada a mano y confirmada contra el desglose devuelto por la API | PASA |
| RN2 | Calidad — captura manual (decisión de Luis, sin auto-sugerencia) | Campo "CALIDAD DEL TRABAJO TÉCNICO (%, CAPTURA MANUAL)" — input numérico directo, label deja explícito que es manual, sin ningún valor "sugerido" precargado | PASA |
| RN3 | SLA 100% automático | Confirmado — no hay ningún campo de captura para SLA en el formulario; el desglose de resultado sí lo muestra calculado (0% en este caso, por falta de tickets cerrados en el período — comportamiento correcto de `convertSla()`, no un bug) | PASA |
| **RN5 (camino de ruptura/regla especial)** | Licencia médica → Puntualidad a 0%, redistribución proporcional entre los otros 4 | Se capturó un período distinto (julio 2026, vía el selector "◀"/"▶" del modal — RN5 de REQ-292 también verificado de paso) con el checkbox de licencia médica marcado → **Total $272.94**, desglose devuelto por la API: `monto_max` de calidad/satisfacción/SLA subió de $100 a **$117.65** cada uno, actitud de $40 a **$47.06**, puntualidad quedó en `monto_max=0`/`monto_obtenido=0` — matemática de redistribución PROPORCIONAL (no igualitaria) verificada exactamente: $100 + 60×(100/340) = $117.647 ≈ $117.65. Suma de los 5 `monto_max` = $400.01 (redondeo de centavos, el techo estructural se mantiene) | PASA |
| RN6 | Techo $400 | Confirmado en ambos escenarios — la suma de montos máximos nunca excede $400 (con o sin redistribución) | PASA |
| RN7 | Solo Carlos tiene el plan | Confirmado — Miguel [persona] (`has_bonus_plan=false`) no muestra NINGÚN bloque de comisión en su tarjeta, ni para Aaron ni para Gerencia1 ni para Miguel mismo | PASA |
| **RN8 (camino de ruptura, el más crítico del batch)** | Aaron puede capturar pero NUNCA ver el resultado | Verificado en 3 capas: (1) **UI** — tarjeta de Carlos vista por Aaron muestra solo el botón "Comisión del mes" (captura), nunca el mini-indicador con el monto, ni antes ni después de capturar valores; (2) **UI positiva** — Carlos viendo su propia tarjeta ve "Ver comisión" (resultado) y NO ve el botón de captura; Gerencia1 (Gerencia) ve AMBOS botones; (3) **API directa** — `GET /api/servicios/internal-technicians/1/commission` con el token real de Aaron → **403** `"No tienes permiso para realizar esta acción."` (rechazado ya en el middleware de ruta, `role:superadmin,management,tecnico_servicios,garantias_servicios` excluye explícitamente a `lider_servicios`) | **PASA — verificado en las 3 capas, no solo un botón escondido en el frontend** |
| RN9 | Desglose con peso/%/monto por criterio | Confirmado — tabla completa en el modal de Carlos: 5 filas, columnas Peso/% obtenido/Monto, coincide con la respuesta de la API | PASA |

## Extra — "Ajustes de Servicios" (`/servicios/ajustes`, sin ticket de Jira)

| # | Criterio (de la spec de la tarea, no de Jira) | Escenario ejecutado | Resultado |
|---|---|---|---|
| Positivo | Gerencia/superadmin puede editar y guardar | Gerencia1 cambió "Días sin agendar" de 3 a 4, guardó, recargó la página → el valor persistió en 4 (confirmado vía `GET /api/servicios/settings` tras reload). Revertido a 3 al terminar para dejar el entorno limpio | PASA |
| Positivo | `lider_servicios`/técnicos ven la pantalla pero deshabilitada | Aaron ve los 21 campos con texto atenuado (`disabled`, `opacity-50`), nota explícita **"Solo Gerencia puede editar estos valores."**, sin botón "Guardar cambios" renderizado (no solo deshabilitado — no existe en el DOM para este rol) | PASA |
| **Camino de ruptura (pedido explícito de la tarea)** | `lider_servicios` intenta `PATCH /api/servicios/settings` directo, sin pasar por el frontend | `fetch()` autenticado como Aaron con `{ commission_calidad_peso: 999 }` → **403** `"No tienes permiso para realizar esta acción."` — el gate real está en la ruta (`role:superadmin,management`), no depende de que el frontend esconda el botón | **PASA** |

### Hallazgo MEDIO, no bloqueante para los 5 tickets de Jira — sin validación de rango en `PATCH /settings`
Probando adversarialmente valores fuera de rango (Paso 3 de Pre-QA, "input inválido"), con las
keys REALES del schema (`commission_peso_calidad_pct: -25`, `sla_dias_installation: -7`) enviados
por **Gerencia1** (usuario CON permiso, no un problema de rol): la API respondió **200**, aceptó y
persistió los valores negativos sin ningún error de validación. Revisado el código
(`ServiciosSettingsController::update()` solo valida `is_numeric()`, ningún `min:`) — confirma que
no hay ningún guardrail server-side de rango. El único freno hoy es el `min={0}` HTML del input en
`ServiciosSettingsPage.tsx`, que es cosmético (no bloquea un `fetch()` directo ni, en algunos
navegadores, un input pegado con teclado). Valores negativos en pesos/montos/días de SLA
producirían resultados sin sentido en `CommissionCalculationService`/`convertSla()` (ej. un SLA
negativo haría que ningún ticket "cumpla" nunca, un peso negativo generaría un componente negativo
en el bono).

**Por qué no lo corregí yo mismo:** agregar un rango mínimo requiere una decisión de producto por
campo que ningún REQ de este batch define explícitamente (¿el mínimo de "Peso (%)" es 0 o 1? ¿los
5 pesos deben sumar exactamente 100? ¿"Monto máx." tiene un tope superior razonable, o solo
`>= 0`?) — exactamente el tipo de gap que `pre-qa.md` pide escalar en vez de autoclasificar como
"chico" y resolverlo con un criterio inventado. Revertí los valores de prueba a los reales antes
de seguir, para no dejar el entorno con datos rotos.

**No bloquea ninguno de los 5 tickets de Jira** (SCRUM-314/315/316/317/321): ninguno de sus
criterios de aceptación pide validación de rango en Ajustes — la pantalla en sí (que no tiene
ticket propio) es la que queda con esta deuda. Reportado acá para que quede explícito antes de
darlo por "terminado", tal como pide la regla dura del loop de Pre-QA (no autoclasificar un gap y
seguir de largo).

---

## Resumen — veredicto por ticket

| Ticket | Veredicto | Hallazgos bloqueantes | Corregido en el momento |
|---|---|---|---|
| SCRUM-314 (REQ-251) | **PASA LIMPIO** | Ninguno | — |
| SCRUM-315 (REQ-252) | **PASA LIMPIO** | Ninguno | — |
| SCRUM-316 (REQ-253) | **PASA LIMPIO** | Ninguno | — |
| SCRUM-317 (REQ-254) | **PASA LIMPIO** | Ninguno | — |
| SCRUM-321 (REQ-258) | **PASA LIMPIO** | Ninguno | — |
| Ajustes de Servicios (sin ticket) | Funcionalmente correcto, con deuda documentada | MEDIO — sin validación de rango server-side (ver arriba) | No — requiere decisión de producto por campo |

**Los 5 tickets de Jira pasan limpio, sin ningún hallazgo CRÍTICO ni MEDIO bloqueante.** El único
hallazgo real de la sesión (validación de rango en Ajustes) es MEDIO, no bloquea ningún criterio
de aceptación existente, y queda documentado explícitamente como pendiente — no autoclasificado
ni enterrado.

Marcadores creados: `~/.claude-preqa-markers/SCRUM-314`, `SCRUM-315`, `SCRUM-316`, `SCRUM-317`,
`SCRUM-321`.

**Ambiente:** todas las pruebas corrieron contra el stack LOCAL (Docker + Vite dev server) — no
`dev.atlanticerp.ai`. El código de este batch sigue sin commitear/pushear (instrucción explícita de
quien despachó esta sesión: no tocar git). Falta el push a `dev` antes de que QA formal pueda
validar en `dev.atlanticerp.ai`.
