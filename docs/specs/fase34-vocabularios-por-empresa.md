# Fase 34 — Vocabularios fijos → catálogos por empresa

Estado: **borrador para aprobar (2026-11-19).** Nada se implementa sin el «adelante» de Luis.

## Origen
La pasada de «verificar» (2026-11-19) tachó lo resuelto por las Fases 12–32. Lo que sigue abierto de verdad
y es de la misma naturaleza que la Fase 7 (listas fijas en el código que cada empresa debería poder editar):

| # | Vocabulario | Dónde vive hoy (verificado) | Catálogo actual |
|---|---|---|---|
| 1 | Motivos de ajuste de inventario | `AdjustmentRequestLine::MOTIVOS` | — |
| 2 | Motivos de devolución de cliente | `CustomerReturnLine::REASONS` | — |
| 3 | Motivos de faltante en picking | `OrderItem::SHORTAGE_REASONS` | — |
| 4 | Roles de contacto de cotización/tarjeta (`client`, `architect`, `other`) | validación + `PipelineCard::hasArchitectContact()` (lógica de negocio sobre `architect`) | — |
| 5 | Formas de pago (incluye Yappy, regional) | `AdminContPayment`, `AdminContPettyCashReport` | — |
| 6 | Especialidades de técnico (`general`, `warranty`) | `InternalTechnician::ESPECIALIDAD_*` | — |
| 7 | Formularios del informe de inspección por tipo | `InspectionReportService::STATIC_FIELDS_BY_TIPO` | — |

Mecanismo disponible: `catalog_entries` + `config/catalogs.php` (dominios, `usage`/`usage_json`, props tipadas,
entradas de sistema que no se borran), fachadas con memo `CatalogCache`, hooks `useCatalog*` en el frontend.

## Decisiones de Luis (en pregunta, sin responder)
1. **Alcance**: ¿los 7 de una vez, o por bloques? Propuesta: **34a** motivos (1–3, bajo riesgo), **34b** formas de pago y especialidades (5–6), **34c** roles de contacto (4) e informe de inspección (7, el más grande).
2. **Formas de pago por país**: ¿catálogo único por empresa (la empresa quita Yappy si no aplica) o con propiedad `paises` que filtre según la región de la empresa (Fase 6)?
3. **Informe de inspección (7)**: ¿catálogo completo de campos por tipo (editable) o solo etiquetas/obligatoriedad de los campos de sistema? Lo primero es varios días.
4. **Roles de contacto (4)**: `architect` tiene lógica de negocio. Propuesta: queda como entrada de sistema con propiedad `comportamiento`, igual que `ticket_type`.

## Criterios de aceptación (por bloque)
- Cero lista fija en el código para el vocabulario migrado (lectura por fachada + memo).
- Entradas de sistema no borrables; entradas en uso no borrables ni desactivables sin reasignar.
- Migración reversible que crea las entradas de sistema y las que ya estén en uso (como la 7a/9a).
- Contrato de neutralidad en verde; backend y frontend con tests; humo `e2e:humo` y un recorrido en Playwright por bloque.

## Fuera de alcance
Correo real, `digifact`, DIAN y AWS (decisiones de fondo de Luis).
