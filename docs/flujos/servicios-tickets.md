# Flujo: tickets de Servicios

Del reporte de un ticket a su cierre: instalaciones y garantías. Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-28 contra el stack local con las cuentas demo de cada rol.

## Actores
| Rol (cuenta demo) | Qué hace |
|---|---|
| Vendedor | reporta tickets (`servicios.tickets.create`); no agenda ni cambia estados [E] |
| Líder de Servicios | agenda, cambia estados, cancela, decide cotizaciones [E] |
| Técnico interno (`general`) | atiende instalaciones; informe y cotización del ticket que tiene asignado [E] |
| Técnico de garantías (`warranty`) | atiende garantías; no puede ser asignado a una instalación [E] |

## 1. Crear un ticket
`POST servicios/tickets {tipo, subtipo, tipo_instalacion, descripcion, …}` [E].
- El `tipo` sale del catálogo `ticket_type` (instalación, garantía, reclamo, retrofit; con prefijo de folio y SLA en días por empresa) [E][C].
- El **subtipo es obligatorio** y depende del tipo: instalación → `installation`/`inspection`; garantía → `warranty_generic`/`replacement_inspection`. Un subtipo de otro tipo responde 422 [E].
- Folio por tipo y año: `INS-2026-0005`, `GAR-2026-0005` [E]. Nace en `reported` [E].
- Una garantía de inspección nace con `inspection_report_status = pending`; una instalación, `not_applicable` [E].

## 2. Agendar
`PATCH servicios/tickets/{id}/agendar {internal_technician_id, scheduled_at, …}` [E].
- **El campo se llama `internal_technician_id` pero espera el id de USUARIO del técnico**, no el de su ficha [E][C].
- Regla de especialidad: el técnico `general` atiende instalaciones; el de `warranty` no (422 «no atiende este tipo de ticket») [E].
- El primer agendamiento pasa el ticket de `reported` a `scheduled`; reagendar **exige motivo** y deja historial [E][C].

## 3. Estados
`reported → scheduled → on_site → resolved → closed` (+ `cancelled`). `PATCH servicios/tickets/{id}/estado` solo para quien tiene `servicios.tickets.manage` (el técnico recibe 403) [E].
- Cada entrada a `on_site` cuenta una visita; el primer paso a `resolved`/`closed` fija `closed_at` [C].
- **Única compuerta**: pasar a `resolved` o `closed` [C]. Para una **instalación** sin informe ni cotización no hay ningún requisito (se cerró sin agendar ni técnico) [E].
- Para una **garantía de inspección** se exigen, en este orden: el informe de inspección completo («falta completar el informe de inspección») y la cotización aprobada («falta aprobar la cotización») [E].

## 4. Informe de inspección
`PUT servicios/tickets/{id}/inspection-report` con `conclusion` (obligatoria), `findings[]`, `materiales[]`, firmas [E].
- Lo escribe **solo el técnico asignado o el líder**; otro técnico, 403 con mensaje explícito; el vendedor, 403 [E].
- Genera folio `INF-2026-0001` y deja `inspection_report_status = completed` [E].

## 5. Cotización de servicio
`POST …/quote` (borrador) → `POST …/quote/items {tipo: product|labor|subcontracted, quantity, unit_price}` → `PATCH …/quote/enviar` → `PATCH …/quote/decidir {estado: approved|rejected}` [E].
- Crea y arma la cotización el técnico asignado o el líder; **decide solo el líder** (el técnico recibe 403: nunca decide su propia cotización) [E].
- La respuesta trae el margen mínimo (30 %), el ITBMS (7 %) y las condiciones comerciales de la empresa, configurables [E][C].

## 6. Cancelar
`PATCH servicios/tickets/{id}/cancelar {motivo}`: motivo obligatorio, solo el líder (el técnico 403), no se cancela dos veces (422) y no pasa por las compuertas de cierre [E].
- **Corregido en esta fase**: `PATCH …/estado {estado: cancelled}` cancelaba sin motivo; ahora responde 422 y remite a la cancelación (backend #41) [E].

## Candidatos a cambio o mejora
1. **Las transiciones de estado son libres**: se puede ir de `reported` a `closed` sin agendar ni técnico, y volver de `closed` a `reported`. Solo `resolved`/`closed` tienen compuertas, y solo para algunos tipos. [E]
2. **Un ticket cancelado se puede mover de estado**, es decir, reabrir sin rastro de la decisión. [E]
3. **`internal_technician_id` recibe un id de usuario**: el nombre induce a error (y confundió esta propia prueba). [E][C]
4. **El subtipo "installation" es a la vez tipo y subtipo** y los subtipos de sistema conservan significado en el código (ya anotado en la Fase 7b). [C]
5. **Instalaciones sin compuerta de cierre**: ni informe, ni cotización, ni evidencia; el SLA (7 días) no bloquea nada. [E]
6. **Especialidades técnicas fijas** (`general`, `warranty`) en el código, no un catálogo por empresa. [C]
7. **Sin ejercitar**: técnicos externos y su tarifa, herramientas y asignaciones, insumos y solicitudes de compra, hoja de reclamo, adjuntos, reportes e indicadores del mes, informe móvil. [C]
