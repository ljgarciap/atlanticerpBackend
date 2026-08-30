# Servicios (Fase 4) — Desglose de batches (checklist vivo)

Separado de `servicios-fase4-diseno.md` el 2026-08-15 (mismo criterio que la separación
`CLAUDE.md`/`docs/changelog.md` — ver "Rendimiento y uso de contexto" en el `CLAUDE.md` raíz del
workspace): esta es la parte que se consulta y actualiza en cada sesión ("¿qué batch sigue?"), a
diferencia del resto del diseño (contexto, decisiones de arquitectura, schema, eventos, permisos),
que quedó estable desde 2026-08-02 y ya no hace falta re-leer para esto. Para el rationale de
diseño, ver `servicios-fase4-diseno.md`.

---

## 8. Desglose de PM en batches (≤6 tickets, regla de cadencia workspace-wide)

19 batches, 87 tickets exactos. Orden = fase de entrega (no todos son secuenciales estrictos —
dentro de la misma fase pueden ir en paralelo entre Backend/Frontend Dev).

**Fase 1 — Fundación (Tickets)**
| Batch | Tickets (SCRUM) | REQ | Contenido |
|---|---|---|---|
| 1 | 279-284 | 216-221 | Tabla, filtros, cambio de estado + gate REQ-218, indicadores Cotización/Informe, Tablero kanban |
| 2 | 285-289 | 222-226 | Stats cards, navegación Tabla/Tablero, modal detalle, edición global, Agendar/Reagendar |
| 3 | 290,291,308-310 | 227,228,245-247 | Cancelar, Ver/Imprimir PDF, formulario Nuevo ticket (datos generales + búsqueda cliente + productos afectados) |
| 4 | 311-313 | 248-250 | Adjuntos/Observaciones, numeración automática (REQ-249, con el gotcha del año hardcodeado a corregir), historial de cotizaciones |

**Fase 2 — Equipo (necesario antes de Cotización por el ítem Subcontratado)**
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 5 | 333-337 | 263-267 | Técnicos externos completo |
| 6 | 318-323 | 255-260 | Técnicos internos — vista equipo, estado automático (REQ-256), modal visitas, comisión, alta técnico |
| 7 | 324,326,362 | 261,262,292 | Agenda equipo, estadísticas equipo, captura mensual de comisión |
| 19 | 357-360 | 287-290 | Navegación/menú — sin dependencias, puede ir en paralelo desde el día 1 (Frontend) |

**Fase 3 — Informe de Inspección + Hoja de Reclamo**
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 8 | 301-306 | 238-243 | Informe: datos generales, hallazgos dinámicos (REQ-239, el más complejo del módulo), materiales (dispara consumo de inventario), fotos/conclusión, firma desktop, modo alternativo subir |
| 9 | 307,348,349 | 244,278,279 | Ver informe completado, Hoja de Reclamo completa |
| 10 | 314-317 | 251-254 | Informe móvil (depende de Batch 8 en backend) |

**Fase 4 — Cotización de servicio**
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 11 | 292-297 | 229-234 | Formulario, items Producto/Mano de obra/Subcontratado (necesita Batch 5), Totales (ITBMS consistente, REQ-233), ciclo de vida/estados |
| 12 | 298-300 | 235-237 | Documento formal, historial por ticket, condiciones configurables (Gerencia) |

**Fase 5 — Herramientas e Insumos** (Batch 14 necesita coordinación con Bodega — punto abierto #2)
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 13 | 338-343 | 268-273 | Herramientas (listado agrupado, cambio estado, reasignación, alta, reposición), Insumos (listado ligado a catálogo Compras) |
| 14 | 344-347,361 | 274-277,291 | Solicitud de compra insumos, agregar insumo, Kardex herramientas, sincronización con Bodega (REQ-277+291 — la 8va bodega "Reserva Servicios" + integración `KardexService`) |

**Fase 6 — Home/Dashboard** (agrega datos de todo lo anterior, va después)
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 15 | 269-274 | 206-211 | Saludo, acciones rápidas, rutas del día, mi calendario, pendientes, indicadores del mes |
| 16 | 275-278 | 212-215 | Servicios sin responder, insumos/herramientas pendientes, modal ver ticket, estado de tickets |

**Fase 7 — Reportes** (agrega de todo, va al final)
| Batch | Tickets | REQ | Contenido |
|---|---|---|---|
| 17 | 350-355 | 280-285 | Panorama del mes, cotizadas vs realizadas, donut por tipo, por técnico, completados por tipo, comisión ampliada |
| 18 | 356 | 286 | Biblioteca de reportes (alcance a confirmar con PM — punto abierto #4) |

**Estado (2026-08-05, corregido — ver nota abajo): Batch 1 (REQ-216→221 completos, REQ-249 solo el
endpoint mínimo) + Batch 19 + Batch 2 completos.** Batch 1/19 CERRADOS, en QA y en dev.atlanticerp.ai (4
hallazgos reales corregidos, ver `memory/project_sesion_20260802_fase4_servicios_arranque.md`).
**Batch 2 (REQ-222/224/225/226, SCRUM-285/287/288/289) implementado y desplegado el 2026-08-03
(commits `4454af3`/`dd5ccfb` + fixes post Visual Review `0b121cd`/`e2558a2`), Pre-QA + Visual
Review PASA LIMPIO (12/12), QA de Marly aprobado — los 4 tickets están en `PM Review` en Jira.**

> ⚠️ Esta sección quedó sin actualizar tras cerrarse Batch 2 el 2026-08-03 — la sesión que lo hizo
> nunca tocó este archivo ni dejó memoria, así que la sesión siguiente (2026-08-05) volvió a
> planear "Batch 2" como si estuviera pendiente. Corregido tras verificar en vivo (git log +
> Jira). Ver `docs/pre-qa/scrum285-287-288-289-servicios-batch2-20260803.md` y
> `docs/visual-review/scrum285-287-288-289-servicios-batch2-20260803.md` para el detalle completo.
> **Regla a seguir de acá en adelante:** cada batch que cierre Pre-QA/Visual Review actualiza esta
> sección de Estado/Siguiente paso en el mismo momento, no como tarea aparte.

**Batch 3 — dividido en 2 partes a pedido de Luis (2026-08-05):**
- **Parte 1 (REQ-227 Cancelar ticket, REQ-228 Ver/Imprimir PDF, SCRUM-290/291) — CERRADA, en QA
  y en dev.atlanticerp.ai.** Pre-QA + Visual Review PASA LIMPIO, 2 hallazgos propios corregidos en el
  camino (motivo de cancelación de solo espacios, subtipo sin traducir en el PDF). Detalle
  completo en `atlanticerp-frontend/docs/pre-qa/scrum290-291-servicios-batch3parte1-20260805.md` y
  `atlanticerp-frontend/docs/visual-review/scrum290-291-servicios-batch3parte1-20260805.md`.
- **Parte 2 (REQ-245→247, formulario completo de Nuevo ticket: datos generales, búsqueda Cliente
  Master→Subcliente→Proyecto de solo lectura, checklist de 18 requerimientos especiales +
  productos reclamados/afectados desde el catálogo de Compras, SCRUM-308/309/310) — CERRADA
  2026-08-05, en QA y en dev.atlanticerp.ai.** `sales_project_id` pasa a obligatorio en la creación
  (RN6, cliente derivado server-side del Subcliente elegido — nunca texto libre, RN1);
  `requerimientos_especiales` pasa de texto libre a JSONB estructurado (compartido con la edición
  global de REQ-225, que hasta acá usaba un textarea libre); nueva tabla `ticket_products`; 4
  endpoints de lookup nuevos (`/servicios/lookup/*`); `POST /servicios/tickets` y los lookups
  pasan de `permission:servicios.write` a `role:superadmin,lider_servicios,management,
  vendedor_disenador` (cierra el gap dejado pendiente a propósito en Batch 1 — Vendedor/Diseñador
  ya podía ver, ahora también puede crear). Pre-QA + Visual Review PASA LIMPIO, sin hallazgos
  bloqueantes. Detalle completo en
  `atlanticerp-frontend/docs/pre-qa/scrum308-309-310-servicios-batch3parte2-20260805.md` y
  `atlanticerp-frontend/docs/visual-review/scrum308-309-310-servicios-batch3parte2-20260805.md`.

**Resto del Batch 4 (REQ-248, SCRUM-311) — CERRADO 2026-08-08, en QA y en dev.atlanticerp.ai.** Senior
Review + Visual Review + Pre-QA PASA LIMPIO (1 hallazgo real corregido: condición de carrera
TOCTOU en el límite de adjuntos). Detalle en
`atlanticerp-backend/docs/pre-qa/scrum311-servicios-batch4resto-20260808.md`.

**REQ-250 (historial de cotizaciones, SCRUM-313) — BLOQUEADO, no se puede implementar todavía.**
La descripción del ticket depende explícitamente de COTIZACION_SERVICIO (Fase 4, Batch 11-12),
que no existe como módulo — solo hay un campo `quote_status` denormalizado en `tickets`. Sigue en
`In Progress` en Jira a propósito; Batch 4 se da por cerrado sin este ticket, que se retoma recién
cuando exista Cotización real.

**Batch 5 (REQ-263→267: listado/filtros, alta, activar/inactivar, tarifa+historial, proyectos
asignados de Técnicos Externos, SCRUM-333/334/335/336/337) — CERRADO 2026-08-09, en QA y en
dev.atlanticerp.ai (commits `a6194c6` backend / `9b241d4` frontend).** Nuevas tablas
`external_technicians`/`external_technician_rate_history`. Pre-QA (Playwright real, 3 usuarios
reales) PASA LIMPIO tras 2 hallazgos corregidos en el momento: `show()` no aplicaba el default
"Sin especificar" de especialidad (inconsistente con index()/store()), y `vendedor_disenador`
tenía lectura real (incl. tarifa) vía el permiso de módulo compartido pese a que la matriz del
Excel lo marca N/A para esta feature — rutas de lectura angostadas a `role:` explícito. REQ-267
(proyectos/tickets asignados) queda con tabla y conteo de "proyectos activos" en 0 a propósito
—`ExternalTechnicianService::activeProjectsCount()`/`assignedProjects()` son stubs documentados
hasta que el ítem Subcontratado de Cotización (Batch 11-12) exista y escriba ahí. Detalle completo
en `atlanticerp-backend/docs/pre-qa/scrum333-337-servicios-batch5-tecnicos-externos-20260809.md`.

**Batch 6 (REQ-255→260, técnicos internos, SCRUM-318/319/320/322/323) — CERRADO 2026-08-09, en QA
y en dev.atlanticerp.ai (commits `b9228ef` backend / `222b7e1` frontend).** Nueva tabla
`internal_technicians` — `user_id` NULLABLE (diverge del snippet original de la sección 4: el
formulario de alta de REQ-259 no exige correo único ni pasa por el flujo/guardia jerárquica de
`POST /api/users`, así que un perfil de técnico interno no es necesariamente una cuenta de login;
los 4 técnicos reales existentes sí quedan linkeados vía `InternalTechnicianSeeder`, idempotente,
todos los entornos). Estado (REQ-256) 100% calculado en cada request desde
`tickets.scheduled_at`/`scheduled_ends_at` vs `working_hours` en hora de Panamá
(`TechnicianStatusService`, mismo patrón de zona horaria que `SecurityAnalysisJob`), nunca
persistido. Pre-QA (Playwright real, horario de reloj real) PASA LIMPIO, 0 hallazgos. Detalle
completo en `atlanticerp-backend/docs/pre-qa/scrum318-323-servicios-batch6-tecnicos-internos-20260809.md`.

**REQ-258 (Comisión del mes — Carlos Vergara, SCRUM-321) — BLOQUEADO, no se puede implementar
todavía.** RN3/RN4 dependen explícitamente de REQ-292 ("captura mensual de comisión" + definición
de SLA por tipo de ticket), que es Batch 7 (SCRUM-362) — no existe todavía. Sigue en `In Progress`
en Jira a propósito, con comentario explicando el bloqueo; Batch 6 se da por cerrado sin este
ticket, que se retoma en cuanto exista REQ-292.

**Batch 7 (REQ-261/262/292, SCRUM-324/326/362) — CERRADO 2026-08-09/10, en QA y en dev.atlanticerp.ai
(commits `a91926b` backend / `f4916da` frontend).** Cierra Fase 2 — Equipo completa. REQ-262 no
necesitó código nuevo (ya lo resolvía el toggle Equipo/Agenda equipo de Batch 6). Pre-QA corrigió
una condición de carrera TOCTOU en la captura concurrente de comisión y un gap de RN5 (selector de
período ausente). REQ-258 (SCRUM-321) queda desbloqueado pero DIFERIDO a pedido de Luis — la
sesión siguiente arranca directo con Batch 8, no con REQ-258. Detalle completo en
`memory/project_sesion_20260809_batches5_6_7_fase2_equipo_cerrada.md`.

**Batch 8 (REQ-238→243, SCRUM-301-306, Informe de Inspección) — CERRADO 2026-08-10, en `QA` y en
dev.atlanticerp.ai (commit `ee8993a`).** Modelo de datos reconciliado contra
`5__Requerimientos_Servicios.xlsx` (hoja "Modelo de Datos") y el mockup real — ver tablas
corregidas en la sección 4 arriba. Decisión de Luis: REQ-240 RN3/RN4 (consumo automático de
inventario real) queda DIFERIDO a Batch 14 — Batch 8 implementa solo el registro informativo de
materiales (RN1/RN2). Visual Review APROBADO, 0 críticos. Pre-QA PASA LIMPIO tras 2 rondas — 2
CRÍTICOS reales encontrados y corregidos en el momento: doble submit concurrente producía un 500
con stack trace expuesto (info disclosure) en vez de actualizar el informe existente, y una línea
de materiales vacía rechazaba TODO el guardado con 422 en vez de omitirse (RN2 roto). Gap real de
`tickets.inspection_report_status` (nada lo pasaba a `completed`, dejando el gate REQ-218
bloqueado para siempre) corregido en el camino. Detalle en
`memory/project_sesion_20260810_batch8_informe_inspeccion.md`.

**Batch 9 (REQ-244/278/279, SCRUM-307/348/349) — DISEÑO APROBADO por Luis 2026-08-10, desglosado
por PM, arranca en la sesión siguiente.** Confirmado en Jira: los 3 tickets siguen en
`In Progress`, sin código todavía. Dos features independientes dentro del mismo batch:

- **REQ-244 (SCRUM-307) — extiende Batch 8, sin tabla nueva.** `GET .../inspection-report` (show)
  ya existe desde Batch 8. Falta:
  1. `InspectionReportPdfService` (Backend Dev) — mismo patrón dompdf que `TicketPdfService`/
     `CatalogPdfService`. Dos métodos: informe lleno (campos + materiales + fotos referenciadas +
     firmas) y plantilla en blanco por tipo/subtipo, reusando
     `InspectionReportService::STATIC_FIELDS_BY_TIPO` (no duplicar el catálogo de labels).
  2. Rutas (Backend Dev): `GET .../inspection-report/pdf` (`permission:servicios.read`, informe
     lleno) y `GET .../inspection-report/pdf/blank` (`role:superadmin,lider_servicios,
     tecnico_servicios` — más angosto, RN de "Descargar plantilla en blanco": Aaron + técnico
     interno).
  3. Frontend (Frontend Dev, depende de 1-2): cablear en `InspectionReportModal.tsx` el toggle del
     botón superior del mockup — `printInformeBlank()` antes de completar / `printInforme()`
     después (mismo criterio que `viewInforme()` ya parcialmente construido en Batch 8).

- **REQ-278/279 (SCRUM-348/349) — Hoja de Reclamo, superficie nueva.** SCRUM-349 depende de
  SCRUM-348 (Jira) — Backend Dev implementa 348 primero.
  1. Migración `servicios.claim_sheets` (Backend Dev): `id, ticket_id FK unique, descripcion_problema,
     fecha_reclamo, diagnostico enum(defectuoso|no_procede|uso_inadecuado|pendiente_evaluacion),
     firma_responsable, estado enum(pendiente|completado), created_by, timestamps`. 1:1 con el
     ticket, solo aplica a `tickets.tipo = 'claim'` (contraparte de REQ-220 RN1: Reclamos nunca
     pasa por Informe de Inspección). Modelo `ClaimSheet`.
  2. `ClaimSheetService` (Backend Dev) — mismo patrón de `assertCanEdit`/lock total tras completar
     que `InspectionReportService` (RN5). Secciones 1-2 (datos del reclamante + producto) se
     precargan del ticket/`ticket_products`, nunca se capturan a mano. RN4: diagnóstico y firma
     obligatorios para completar.
  3. `ClaimSheetController` + rutas (Backend Dev): `GET/PUT .../claim-sheet`
     (lectura `permission:servicios.read`, escritura
     `role:superadmin,lider_servicios,tecnico_servicios` — mismo criterio que Informe de
     Inspección).
  4. `ClaimSheetPdfService` + `GET .../claim-sheet/pdf` (Backend Dev, depende de 1-3) — dompdf,
     4 secciones calcadas del Word real de la empresa (RN1 de SCRUM-349), solo disponible con
     `estado = completado` (RN2).
  5. `ClaimSheetModal.tsx` nuevo (Frontend Dev, depende de 1-4) — formulario de 4 secciones,
     precarga automática, botón "Guardar Hoja de Reclamo" con validación RN4, modo solo lectura
     tras completar (RN5). Wireado en `TicketDetailModal` solo para tickets tipo Reclamos —
     columna/botón "Generar Hoja de Reclamo" → "Completada", mismo patrón que
     `hojaReclamoCell()`/`openHojaReclamoModal()` del mockup.
  6. Botón "Ver/Imprimir" en `ClaimSheetModal.tsx` (Frontend Dev, depende de 4-5) — descarga el PDF
     del backend.

**Dejado fuera a propósito (RN7 de SCRUM-348):** el ticket dice textualmente "se recomienda,
pendiente de definir con el cliente" bloquear la Cotización de un Reclamo hasta que su Hoja de
Reclamo esté completa. El mockup no lo implementa como bloqueo real, solo lo documenta. No hay
decisión cerrada del cliente — **no se construye ningún gate en este batch**; retomar solo si el
cliente lo confirma explícitamente (evita un gate que después haya que deshacer).

**Batch 10 (REQ-251→254 SCRUM-314-317 Informe móvil + REQ-258 SCRUM-321 Comisión Carlos Vergara,
decisión de Luis 2026-08-11) — CERRADO, en `QA` y en dev.atlanticerp.ai** (commits backend `0d0e3db`/
`be6e91e`, frontend `8a03f6b`). Luis decidió retomar REQ-258 (diferido desde Batch 7) junto con
Batch 10 en la misma sesión, en vez de por separado. Visual Review + Pre-QA fusionados en un solo
despacho (a pedido de Luis, ver `feedback_gate_overhead_proportional_to_batch_size`), PASA LIMPIO
en los 5 tickets. Un hallazgo real de CI (no de Pre-QA): el test nuevo escribía a S3 real sin
`Storage::fake('s3-private')`, pasaba local (bucket real `atlanticerp-dev`) pero fallaba en CI (sin
credenciales) — corregido en `be6e91e`, mismo patrón que ya usa `TicketBatch4Test`.

- **Informe móvil (REQ-251→254):** mismo registro que el informe de escritorio (`inspection_reports`,
  columnas `firma_cliente_nombre`/`firma_cliente_imagen` ya creadas en Batch 8 previendo esto) — RN2
  REQ-254, nunca un informe paralelo. Nuevo endpoint `POST .../inspection-report/mobile` (multipart:
  `payload` JSON string + `firma_cliente_imagen` archivo) — una sola llamada al confirmar la firma,
  reusa `InspectionReportService::save()` extendido con un parámetro opcional de imagen (subida a S3
  ANTES de abrir la transacción de Postgres, nunca I/O de red con una transacción abierta). RN4
  (REQ-252, "Guardar informe" no persiste hasta firmar): resuelto 100% en el frontend — el formulario
  vive en memoria entre la pantalla de datos y la de firma (`InspectionReportMobilePage.tsx`), sin
  backend call hasta `saveMobile()`. `InspectionReportPdfService`/blade actualizados para embeber la
  firma gráfica real como data URI en el PDF (RN4 REQ-254) — antes solo mostraba el nombre en texto.
  Nueva ruta `/servicios/tickets/:id/inspection-report/movil`, botón "Ver en móvil" en
  `InspectionReportModal.tsx` (mismo gate `canEdit` que editar el informe, RN4 REQ-251).
  `SignaturePad.tsx` — lienzo táctil con Pointer Events (RN1-RN6 REQ-253).

- **Comisión Carlos Vergara (REQ-258):** el criterio "Calidad del trabajo técnico" (RN2) queda
  **sin auto-sugerencia en este batch** — depende de la tasa de reincidencia de REQ-211 ("Resuelto
  en 1ra visita"), que no existe (ni panel ni dato crudo, ver `InternalTechnicianService`). Decisión
  de Luis: captura manual (`commission_captures.calidad_pct`, nullable), mismo patrón que
  Satisfacción/Puntualidad/Actitud — se retoma la auto-sugerencia cuando REQ-211 exista, para no
  divergir con un cálculo de reincidencia paralelo (mismo criterio que el stub ya documentado de
  `pct_resuelto_primera_visita`). El criterio SLA (RN3) sí es 100% automático —
  `CommissionCalculationService::convertSla()` reusa `slaDiasByTipo()` (ya construido en Batch 7)
  contra los tickets cerrados/resueltos del técnico en el período. Redistribución de peso por
  licencia médica (RN5) es proporcional a los montos configurados de los otros 4 criterios, no un
  reparto igualitario. Nuevo endpoint `GET .../internal-technicians/{id}/commission` — visibilidad
  RN8 (el propio técnico o Gerencia, nunca Aaron) validada en el controller, no solo por rol de ruta
  (`internal_technicians.user_id` ahora expuesto en `list()` para que el frontend decida cuándo
  mostrar el botón — el backend igual re-valida siempre). UI: botón separado ("Ver comisión") del
  existente de captura (Aaron/Gerencia), en `InternalTechnicianCard.tsx` +
  `InternalTechnicianCommissionResultModal.tsx` nuevo (solo lectura, desglose de 5 criterios).

- **Hallazgo propio corregido en el camino, alcance ampliado a pedido de Luis:** los pesos/montos
  de los 5 criterios de REQ-258 (y, de paso, los umbrales de conversión que Batch 7 ya había dejado
  hardcodeados en `CommissionCalculationService` sin que nadie lo marcara como hallazgo) violaban la
  regla dura de CLAUDE.md § "Nunca" (umbrales de negocio nunca hardcodeados). `ServiciosSettingsService`
  se refactoriza con un `schema()` genérico (además de los getters tipados existentes, sin romperlos)
  que respalda un CRUD real nuevo — pantalla **"Ajustes de Servicios"** (`ServiciosSettingsPage.tsx`,
  ruta `/servicios/ajustes`, 6ta pestaña de `ServiciosNavMenu` solo para
  `lider_servicios/management/superadmin`) + `ServiciosSettingsController`
  (`GET/PATCH /servicios/settings`). Esto también le da por primera vez una vista de administración
  real a `sin_agendar_umbral_dias`/`adjuntos_max_*`/`sla_dias_*` (Batches 2/4/7), que existían como
  "configurables" solo de nombre — sin CRUD, eran de solo lectura pese al docblock. El endpoint
  angosto preexistente `/internal-technicians/sla-settings` (con su propia sección en
  `InternalTechnicianCommissionModal.tsx`) se deja intacto por bajo riesgo/ya testeado — misma tabla
  subyacente, sin divergencia de datos posible, aceptado como duplicación menor de UI.

**Siguiente en la fila tras Senior Review/Visual Review/Pre-QA de este batch:** retomar Batch 11-12
(Cotización de Servicio) cuando Luis lo priorice — varios stubs de batches anteriores
(`ExternalTechnicianService::activeProjectsCount()`, REQ-250 historial de cotizaciones) dependen de
que ese módulo exista.

**Regla a seguir de acá en adelante (reforzada 2026-08-05):** cada batch/parte que cierre
Pre-QA/Visual Review actualiza esta sección de Estado/Siguiente paso en el mismo momento, no como
tarea aparte — ver nota de la sesión anterior arriba sobre el mismo gap con Batch 2.
