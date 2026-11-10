# Fase 31 — Ventas & Diseño y administración

Estado: **APROBADA** por Luis (2026-11-09); decisiones en §3 y propuestas por defecto de §4 aceptadas. Origen: `docs/flujos/pendientes-priorizados.md`, Fase 31, y los catálogos `ventas-diseno.md`, `transversales-administracion.md` y `cadena-venta-a-cobro.md`. Dos entregas con un PR por repositorio cada una, backend primero.

## 1. Verificación previa (2026-11-09)
Contra `master`: siguen abiertos el perfil de empresa gateado por `EnsureIsPrimaryApprover`, la falta de baja de clientes y arquitectos, las rutas de ficha técnica sin historial y el doble estado de la cotización. **«Aprobaciones pendientes» del Inicio ya no parece vacía**: `HomeController::approvalsPending` usa `ApprovalsPendingService`; se confirma al implementar y, si es así, se tacha. Lo ya resuelto se tacha en su catálogo, no se reimplementa.

## 2. Alcance
### 31a — Ventas & Diseño
| # | Qué cambia |
|---|---|
| 1 | Clientes y arquitectos: **desactivar** (nunca borrar con historial), ocultos por defecto con filtro `incluir_inactivos`. |
| 2 | El detalle del arquitecto cuenta también los proyectos como asociado (con desglose comisión / asociado). |
| 3 | Cotización: campo calculado `estado_visible` único en la API y la pantalla; `status` y `document_status` no cambian. |
| 4 | Fase 23, abiertos: reemplazar el contacto único de un subcliente en una operación; historial de la ficha técnica (quitar no pierde la versión anterior); prueba estructural de que toda ruta con alcance Mías/Equipo lleve la guarda `team.scope`; documentar las concesiones de comisión en entornos ya sembrados. |
| 5 | Calendario sin Outlook: mensaje claro y uso del CRUD interno de eventos como respaldo (si es poco, solo documentar). |

### 31b — Administración
| # | Qué cambia |
|---|---|
| 6 | Perfil de empresa visible y editable por Gerencia además del aprobador primario y sus suplentes. |
| 7 | Departamentos: desactivar y reasignar usuarios; el selector sigue mostrando el departamento propio de un usuario aunque esté desactivado. |
| 8 | Reglas de aprobación solapadas: se **impiden** las de igual alcance y rango (hoy solo se avisa). |
| 9 | Reglas de notificación para Compras, Bodega y Servicios (hoy solo CRM), por evento de una lista corta. |
| 10 | Ajustes globales: valores de texto y lista además de enteros, con una vista que los agrupe. |

## 3. Decisiones de Luis (2026-11-09)
1. **Partida en dos**: 31a Ventas y 31b Administración.
2. **Perfil de empresa**: aprobador, suplentes y Gerencia.
3. **Baja de clientes y arquitectos**: solo desactivar, nunca borrar.
4. **Cotización**: `estado_visible` único; los campos internos no cambian.

## 4. Por aprobar (propuesta por defecto)
- Ítems 5, 8, 9 y 10 no han sido decididos por Luis. Propuesta: 8 sí (impedir solapes iguales con 422); 9 solo con 3 eventos por módulo; 10 solo textos, sin listas; 5 solo documentar si el CRUD interno no basta.

## 5. Criterios de aceptación
Por grupo: tests de permisos por rol y de regresión, catálogo en `docs/flujos/` con candidatos tachados, pasos nuevos en `npm run e2e:flujos`, suite completa verde y revisión de seguridad antes de mezclar. Migraciones aditivas que no cambian el comportamiento al desplegar.

## 6. Resultado de la 31a (Ventas), 2026-11-09
| # | Hecho |
|---|---|
| 1 | `master_clients.is_active` y `architects.is_active` (migración `2026_11_26_100000`); `PATCH clients/{id}/active` y `architects/{id}/active` (`ventas_diseno.edit`); ocultos en listas y selectores salvo `incluir_inactivos=1`. |
| 2 | El detalle del asociado suma los proyectos donde figura como asociado, con `role` por fila. |
| 3 | `estado_visible` en el detalle y el listado de cotizaciones. |
| 4 | `POST sub-clients/{id}/contacts/{contactId}/replace` (crea, traslada las cotizaciones vinculadas y borra el anterior); historial de la ficha técnica (`catalog_technical_sheet_versions`, ya no se borra el archivo anterior) con `GET catalog/{id}/technical-sheet/history`; prueba estructural `AlcanceMiasEquipoTest`; notas de concesiones documentadas. |
| 5 | Calendario sin Outlook: solo documentado. |
| — | «Aprobaciones pendientes» del Inicio de Ventas estaba vacía por diseño: ahora lista las solicitudes de pase a Pedido (también en la vista consolidada de Gerencia). |

Una migración aditiva y reversible. Cambio de comportamiento: reemplazar o quitar una ficha técnica ya no borra el archivo anterior.

## 7. Resultado de la 31b (Administración), 2026-11-10
| # | Hecho |
|---|---|
| 6 | `company-profile` (ver, editar, logo) con `primary_approver_only:perfil_empresa`: aprobador primario, suplentes, Gerencia (`platform.management`) y superadmin. La configuración fiscal sigue solo del aprobador. |
| 7 | `POST departments/{id}/reassign` (a otro activo o a ninguno; 422 si el destino es el mismo o está inactivo), `user_count` en la lista, `GET departments?all=1&incluir=ID` conserva el departamento propio aunque esté desactivado. Pantalla: botón «Reasignar usuarios». |
| 8 | Guardar una regla de aprobación activa que se solape con otra activa del mismo alcance → 422 (`regla_en_conflicto`). Las que ya estaban solapadas no se tocan; la pantalla ya mostraba el 422. |
| 9 | `NotifiableModel::REGISTRY` suma módulo y eventos: Compras (`PurchaseOrder` creada/actualizada, `ReplacementRequest` creada), Bodega (`Order` creado/actualizado, `CustomerReturn` creada), Servicios (`Ticket` creado/actualizado, `InspectionReport` actualizado). Evento fuera de la lista → 422; «asignados del proyecto» solo en CRM. |
| 10 | Ajustes globales de **texto** (`type: text`, `max_length`, sin reautenticación) junto a los enteros; clave inicial `support_contact`. Las listas no se hicieron (propuesta por defecto: solo texto). |

Sin migraciones. Cambios de comportamiento: las reglas de aprobación solapadas ya no se guardan; Gerencia ve el perfil de empresa.
**Abierto**: `support_contact` aún no lo muestra ninguna pantalla; las notificaciones de Compras/Bodega/Servicios no se probaron por correo real; el ítem 5 de la 31a/31b sobre reglas de notificación usa la fuente `crm_rule` en los envíos; sin e2e nuevo; el botón de reasignar usa la lista completa de departamentos activos.

