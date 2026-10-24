# Pre-QA + Visual Review — SCRUM-988 / 968 / 1014 / 1015 (2026-10-02)

Entorno: stack local (nginx :8090, Vite :5173), rama `dev`, commits locales sin push. Playwright CLI (specs temporales `e2e/_preqa_scrum1015_etc_*.spec.ts`, sin commitear). Fixtures PreQA sembradas y borradas al terminar (técnicos, tickets, herramienta, cotización, 4 productos de catálogo `brand=PQA`, 13 proveedores). Capturas en `capturas/`.
Migraciones 2026_10_02_100000 (tax_id_dv) y 2026_10_02_110000 (soft deletes técnicos) ya estaban aplicadas al tenant local.

## Veredictos

| Ticket | Veredicto |
|---|---|
| SCRUM-988 | LIMPIO |
| SCRUM-968 | LIMPIO |
| SCRUM-1014 | LIMPIO (observaciones BAJO, no bloqueantes) |
| SCRUM-1015 | NO LIMPIO — 1 hallazgo MEDIO bloqueante (contadores/agenda no se refrescan) + observaciones |

## SCRUM-988 — referencia pública en el buscador de Nueva Cotización
Datos: ML-10156EWH / MIG_LNP002849 (stock 2) sembrado; más un producto con ambas referencias distintas, uno sin referencia de fábrica y uno con ambas iguales.

| Criterio / ruptura intentada | Resultado |
|---|---|
| Autocompletado inline por "10156" muestra `ML-10156EWH — … 2 disp. $100.00` | PASA (988-a) |
| Búsqueda por referencia de fábrica `MIG_LNP002849` encuentra el producto y lo muestra con la pública | PASA (988-a2) |
| Picker "Buscar en catálogo": lista, búsqueda por `ML-10156EWH`, preview ("Referencia: ML-10156EWH"), Seleccionar producto | PASA (988-b, b2) |
| Chip seleccionado y fila agregada a la partida muestran la pública; vía picker e inline; producto sin ref. de fábrica y con refs iguales | PASA (988-d, e) |
| Cantidad/precio/disponible del resultado intactos | PASA |
| No cambia Inventario / detalle de producto / referencia de fábrica | PASA por alcance del diff (solo `QuotePartCard.tsx`); no hay datos de inventario en local para captura |
| Pestaña Familias del picker | Solo verificado por diff (no hay familias en local) |

## SCRUM-968 — aviso de faltante en fila propia
Medido con getBoundingClientRect de los `th` y de las celdas de la fila, aislando el aviso del botón "Guardar" (que aparece al editar y por sí solo ensancha la última columna).

| Criterio | Resultado |
|---|---|
| Aviso en `<tr colSpan=9>` propio justo bajo su ítem; con 2 ítems con faltante, cada uno bajo el suyo (faltan 3 y 2) | PASA, 1440/1280/1024/768 |
| Ancho de columnas idéntico con y sin aviso (ambos estados con "Guardar" visible; y ambos persistidos tras recargar) | PASA, identico al píxel (`[151,197,94,151,128,129,197,78,150]` en ambos) |
| Desaparece al corregir la cantidad; la tabla vuelve a la medida original | PASA |
| Cantidad 0, -1, vacía: sin aviso; 99999: "Faltan 99997" | PASA |
| Sin overflow horizontal del documento en ningún tamaño; en 768 la tabla ya hacía scroll interno antes del cambio | PASA |
| Modo oscuro + EN (el texto del aviso no tiene traducción EN, es el mismo ES) | PASA/observación BAJO |

Observación BAJO (preexistente, fuera de alcance): al pasar una fila a "sucia" aparece "Guardar" y la última columna pasa de 74 a 150 px desplazando el resto unos 10-30 px; no es el aviso.

## SCRUM-1014 — Proveedores: RUC y DV
| Criterio / ruptura | Resultado |
|---|---|
| API: POST con RUC+DV -> GET detalle devuelve `tax_id` y `tax_id_dv` | PASA |
| UI: alta (RUC 8-777-1234, DV 45) -> detalle muestra ambos; edición precarga ambos; editar a 9-888-0001 / 07 -> recarga F5 -> persisten | PASA |
| Vaciar RUC y DV al editar -> quedan null ("—") | PASA |
| DV inválido `abc`, `1.`, `-1` (alta UI y edición UI): mensaje "El DV debe tener 1 o 2 dígitos"; API 422 con `123`, `abc`, `-1`, `1.5` | PASA |
| DV `" "`, `""`, `null` -> guardado como null; DV `07` conserva el cero | PASA |
| RUC duplicado: 422 y mensaje visible en el drawer | PASA |
| Doble clic en Guardar: un solo proveedor | PASA |
| Sin RUC ni DV (proveedor existente Zona Libre, alta nueva) | PASA |
| Rol sin permiso (técnico servicios): 403 en GET/PUT | PASA |

Observaciones (BAJO, no bloquean): (a) el alta con campo `maxLength=2` trunca silenciosamente "123" a "12" y lo guarda; (b) la API acepta DV sin RUC; (c) dos POST concurrentes con el mismo RUC crearon dos proveedores (la unicidad es solo de aplicación, sin índice único; preexistente); (d) el modal de la página de cotización registra un 403 en consola a `/compras/providers?per_page=all` para vendedor (preexistente, ajeno).

## SCRUM-1015 — Técnicos internos: Editar y Eliminar
| Criterio / ruptura | Resultado |
|---|---|
| Líder de servicios y superadmin ven Editar/Eliminar en cada tarjeta; técnico, garantías, gerencia y compras NO (compras ni entra a la pantalla). API PUT/DELETE: 403 para técnico, garantías, gerencia (Daniela, Líder compras), vendedor | PASA |
| Editar abre el mismo formulario ("Editar técnico interno") con datos precargados; cancelar no persiste; nombre en blanco deshabilita Guardar; email inválido -> toast "Ingresa un correo electrónico válido" y el modal sigue abierto; doble clic guarda una vez; el cambio se ve en la tarjeta sin recargar y tras F5; editar sin cambios cierra | PASA |
| Alta ("+ Nuevo técnico") sigue igual: modal vacío, crea y aparece | PASA |
| Eliminar pide confirmación; cancelar no elimina; confirmar (doble clic) elimina; sigue eliminado tras F5; sale del filtro de la Agenda equipo y de `/servicios/technicians/internal`; 404 al borrar/editar dos veces | PASA |
| Historial: ticket cerrado de un técnico eliminado sigue consultable con su técnico (`GET /tickets/{id}` y listado) | PASA |
| Agendar un ticket con un técnico eliminado -> 422 (con el mismo técnico activo -> 200) | PASA |
| Seeder `InternalTechnicianSeeder` no resucita ni reinserta a un eliminado | PASA |
| Bloqueo 422 con servicios reported/scheduled/on_site o herramientas asignadas: mensaje visible en toast ("No se puede eliminar al técnico: tiene 1 servicio(s) pendiente(s) asignado(s). Reasígnalos primero.") | PASA — decisión del Arquitecto, no está en el ticket: señalada como observación, no como fallo |

### HALLAZGOS
**MEDIO (bloqueante del criterio "se reflejan de inmediato en … cualquier otra pantalla donde se muestre el técnico") — la tarjeta "ACTIVOS HOY n de N registrados" y la caché de la Agenda no se refrescan tras eliminar/editar/crear.**
`InternalTechniciansPage.tsx` (líneas ~127, 137, 147) hace `invalidateQueries({ queryKey: ['servicios-internal-technicians'] })`. TanStack hace match por elementos del array, no por prefijo de string, por lo que NO alcanza a `['servicios-internal-technicians-stats']` (`InternalTechnicianStatCards.tsx:12`) ni a `['servicios-internal-technicians-agenda', …]` (`InternalTechnicianAgendaView.tsx:34`).
Reproducción: login `liderservicios@test.com` -> /servicios/tecnicos (tarjeta "4 de 4 registrados") -> Eliminar a un técnico y confirmar -> el listado lo quita pero la tarjeta sigue en "4 de 4" (observado a 0.3, 1.5 y 4 s); tras F5 muestra "3 de 3". Mismo defecto en el alta (preexistente) y, por clave, en la agenda cacheada. Fix chico: invalidar también las tres claves (o `predicate: q => String(q.queryKey[0]).startsWith('servicios-internal-technicians')`) en los tres `onCreated/onDeleted`.

**BAJO — la API permite asignar una herramienta a un técnico eliminado** (`ToolController::updateAsignacion`, `Rule::exists('internal_technicians','id')` ignora `deleted_at`): `PATCH /servicios/tools/{id}/asignacion {"assigned_to_technician_id":<id eliminado>}` -> 200. La UI no lo ofrece, solo por API directa. Sugerido: `Rule::exists(...)->whereNull('deleted_at')`.

**BAJO — reportes:** `ReportsService::distribucionTecnico` (línea ~189) y `ReportsController::comisionCarlosVergara` (`has_bonus_plan` first) usan el scope por defecto: un técnico eliminado desaparece de la distribución por técnico incluso de meses pasados, y si se eliminase al técnico con plan de bonificación (Carlos, sin servicios/herramientas abiertos) la comisión daría 404. Revisar si "historial intacto" debe incluir reportes (`withTrashed()` en el reporte histórico).

**BAJO — el usuario (`auth.users`) del técnico eliminado sigue activo** (puede loguearse y mantiene su rol); la eliminación solo afecta al perfil `internal_technicians`. Confirmar con Daniela que es lo esperado.

## Lo que sí funciona (resumen)
Los cuatro tickets cumplen su camino feliz y casi todos los de ruptura en navegador real y API. Único bloqueante: el MEDIO de SCRUM-1015 de arriba. No se tocó Jira, ni se creó marker, ni se commiteó/pusheó nada.

---

# RONDA 2 — re-verificación completa de SCRUM-1015 tras fixes (commits frontend 21b1321, backend 728c94dd)

Fixture re-sembrada y borrada al terminar (técnicos PreQA, tickets PQA-*, herramientas PQA-*, cotización, productos PQA, proveedores PreQA; Garantias Servicios Test restaurado). Sin commits ni push. Capturas nuevas: `capturas/r2-1015-stats-tras-eliminar.png`.

## Veredicto ronda 2
| Ticket | Veredicto |
|---|---|
| SCRUM-1015 | LIMPIO (los 2 hallazgos corregidos; observaciones por decisión del Arquitecto) |
| SCRUM-988 | LIMPIO (smoke) |
| SCRUM-968 | LIMPIO (smoke) |
| SCRUM-1014 | LIMPIO (smoke) |

## SCRUM-1015 — checklist completo
| Prueba | Resultado |
|---|---|
| Roles UI: lider_servicios y superadmin ven Editar/Eliminar/Nuevo; técnico, garantías, gerencia no; compras no entra a la pantalla. API PUT/DELETE: 403 para técnico, garantías, Líder compras, Daniela y vendedor | PASA |
| Precarga al editar, cancelar sin persistir, nombre en blanco (Guardar deshabilitado), email inválido (toast), doble clic, editar sin cambios | PASA |
| Alta sigue igual | PASA |
| Eliminar: confirmación, cancelar no elimina, doble clic, no reaparece tras F5 | PASA |
| Hallazgo MEDIO anterior, reproducción exacta (sin F5): tarjeta "Activos hoy" 5 de 5 -> 4 de 4 tras eliminar; tras crear 4 -> 5; tras editar se mantiene 5 de 5 y la tarjeta muestra el nombre nuevo | PASA (corregido) |
| Agenda equipo (filtro de técnicos) tras crear / renombrar / eliminar, navegando por SPA sin F5 | PASA: aparece, cambia de nombre y desaparece |
| Herramientas (selectores de técnico de Insumos y herramientas) tras crear / renombrar / eliminar sin F5 | PASA |
| Hallazgo BAJO anterior: `PATCH /servicios/tools/{id}/asignacion` con técnico eliminado | 422 (corregido); con técnico activo 200 y desasignar 200 |
| Selector de asignación `/servicios/technicians/internal` excluye al eliminado; agendar ticket con el eliminado -> 422 | PASA |
| Bloqueos 422 con servicio scheduled (Carlos) o herramienta asignada (Pedro): mensaje visible | PASA (decisión del Arquitecto, observación) |
| Historial: ticket cerrado de Miguel eliminado sigue consultable con su técnico | PASA |
| Seeder `InternalTechnicianSeeder` no resucita al eliminado | PASA |

Observaciones que siguen (decisión de Arquitecto, no bloquean): distribución por técnico y comisión de Carlos con un eliminado; usuario auth del técnico eliminado sigue activo.

## Smoke 988 / 968 / 1014
- 988: inline, picker y preview muestran `ML-10156EWH`; la búsqueda por referencia de fábrica funciona; chip seleccionado con la pública. PASA.
- 968: avisos en fila propia bajo cada ítem (1440/1024/768), sin overflow del documento; el cambio de ancho observado al editar es el botón "Guardar" (comprobado en ronda 1 aislando ambos). Aviso: en esta corrida el baseline no era limpio porque dos specs compartieron la cotización en paralelo; la medición aislada de ronda 1 sigue siendo la referencia. PASA.
- 1014: alta -> detalle y edición -> recarga persisten RUC/DV, vaciar, DV inválido con mensaje, RUC duplicado 422. PASA. (Un spec de duplicado por UI falló solo porque su RUC base ya no existía tras la limpieza; verificado por API: 422.)
