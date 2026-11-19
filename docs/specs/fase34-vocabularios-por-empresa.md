# Fase 34 — Vocabularios fijos → catálogos por empresa

Estado: **decisiones tomadas (2026-11-19); falta el «adelante» de Luis para implementar 34a.**

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

## Decisiones de Luis (2026-11-19)
1. **Alcance: por bloques** — 34a motivos (1–3), 34b formas de pago y especialidades (5–6), 34c roles de contacto (4) e informe de inspección (7). Un PR por repositorio por bloque.
2. **Formas de pago: con propiedad `paises`** que filtra según la región de la empresa (Fase 6); Yappy solo aparece en Panamá.
3. **Informe de inspección: solo etiquetas y obligatoriedad** de los campos de sistema (no campos nuevos por tipo).
4. **Roles de contacto** (propuesta del Arquitecto, sin objeción): `architect` queda como entrada de sistema con propiedad `comportamiento`, como `ticket_type`.

## Criterios de aceptación (por bloque)
- Cero lista fija en el código para el vocabulario migrado (lectura por fachada + memo).
- Entradas de sistema no borrables; entradas en uso no borrables ni desactivables sin reasignar.
- Migración reversible que crea las entradas de sistema y las que ya estén en uso (como la 7a/9a).
- Contrato de neutralidad en verde; backend y frontend con tests; humo `e2e:humo` y un recorrido en Playwright por bloque.

## Fuera de alcance
Correo real, `digifact`, DIAN y AWS (decisiones de fondo de Luis).
