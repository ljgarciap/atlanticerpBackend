# Fase 34 — Vocabularios fijos → catálogos por empresa

Estado: **34a implementada (2026-11-19). CORRECCIÓN DE ALCANCE: al empezar se verificó contra el código y 5 de los 7 vocabularios ya son catálogos (ver §Verificación al implementar).**

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

## Verificación al implementar 34a (2026-11-19) — la tabla de «Origen» estaba desfasada
Al abrir el código para 34a, la tabla de arriba resultó **errónea en 5 de 7 filas** (se armó leyendo constantes que siguen existiendo
como claves de siembra, no su uso). Las Fases 14b/14c ya los habían migrado, con sus dominios en `config/catalogs.php`:

| # | Vocabulario | Estado real |
|---|---|---|
| 1 | Motivos de ajuste | **Ya catálogo** (`adjustment_reason`, Fase 14c) |
| 2 | Motivos de devolución | **Ya catálogo** (`return_reason`, Fase 14c) |
| 3 | Motivos de faltante en picking | **Abierto → hecho en 34a** (`shortage_reason`) |
| 4 | Roles de contacto | **Ya catálogo** (`contact_role`, 14c; `DirectoryService::ALLOWED_TYPES` es otra lista, del directorio) |
| 5 | Formas de pago | **Ya catálogo** (`payment_method`, 14b, con propiedades de comportamiento; falta solo la propiedad `paises`) |
| 6 | Especialidades de técnico | **Ya catálogo** (`technician_specialty`, 14c) |
| 7 | Informe de inspección | **Abierto** (`STATIC_FIELDS_BY_TIPO` sigue fijo) |

**Alcance real que queda de la Fase 34**: 34a (motivo de faltante, hecho), 34b' (propiedad `paises` en `payment_method` para que Yappy solo
aparezca en Panamá) y 34c' (etiquetas/obligatoriedad del informe de inspección). Las decisiones de Luis siguen válidas.

## 34a — resultado
- Dominio `shortage_reason` (`config/catalogs.php`), sembrado por `2026_11_28_100000_seed_shortage_reason_catalog` con las claves y el orden de
  siempre (más cualquier otro valor ya guardado). De sistema: `sin_stock` (reposición y liberación de pedidos) y `otra` (texto libre, se guarda como `otra:<texto>`).
- `OrderItem::SHORTAGE_REASONS` y 4 constantes sin lógica retiradas; `register-delivery` valida `items.*.motivo` contra el catálogo activo (422 si no existe).
- Frontend: `useShortageReasons` (`useFixedLists`), el selector de `RegisterDeliveryModal` y el motivo del detalle del pedido leen el catálogo con respaldo.
- Tests: `ShortageReasonCatalogTest` (3), regresión de `DeliveryFlowTest`/`FixedListCatalogsTest`/`ReplenishmentControllerTest`; frontend 265 archivos / 3017 tests.

## 34b — resultado
- Propiedad `paises` en el dominio `payment_method` (lista de códigos de `config/regions.php`, validados; vacía = todos). `CatalogService::entries()` solo OFRECE las
  entradas de la región de la empresa en cualquier dominio que declare `paises`; lo histórico (`onlyActive: false`, etiquetas de cobros ya hechos) no se filtra.
- Migración `2026_11_28_200000`: `yappy` → `paises = ['PA']` (reversible).
- Frontend: etiqueta de la propiedad en Ajustes → Catálogos (es/en); los selectores ya leen el catálogo filtrado.
- Tests: `PaymentMethodCountriesTest` (2); backend 5412 tests, PHPStan 0; frontend 265 archivos / 3017 tests.
- **Abierto**: los reportes «ventas por medio de pago» y «cobros por cliente» tienen una columna fija de Yappy (históricos); el respaldo `usePaymentMethods` del frontend conserva Yappy.

## 34c — resultado (Fase 34 COMPLETA)
- Dominio `inspection_field` (`fixed_set`: sin altas ni bajas) con una entrada de sistema por cada campo del informe (18; `InspectionReportService::defaultFieldLabels()`),
  sembrado por `2026_11_28_300000`. Propiedades editables: etiqueta (es/en) y `obligatorio` (por omisión falso = cero cambio). Qué campos lleva cada tipo de ticket, su
  control y sus opciones siguen en el código (decisión de Luis: solo etiquetas y obligatoriedad).
- `fieldsForTicket` devuelve la etiqueta de la empresa y `obligatorio`. Los hallazgos guardan y envían su `key` y se emparejan por ella (sin `key`, por etiqueta: informes
  ya guardados); al completar (no en borrador) un campo obligatorio vacío → 422. `POST /admin/catalogs/inspection_field` → 422.
- Frontend: modal de escritorio y página móvil mandan `key` y marcan `*` el obligatorio; el panel de Catálogos oculta el alta en dominios `fixed_set`.
- Tests: `InspectionFieldCatalogTest` (4) + 1 de frontend; backend 5416 tests, PHPStan 0; frontend 265 archivos / 3018. Lección: un test propio con vocabulario del sector
  anterior rompe el contrato de neutralidad (lo vio la suite completa): redactar con términos neutros desde el primer commit.
- **Abierto**: las opciones de los selectores («Defecto de fábrica»…) siguen fijas; los informes viejos sin `key` se emparejan por etiqueta (si la empresa la renombra antes de re-guardarlos se muestran como hallazgo adicional).
