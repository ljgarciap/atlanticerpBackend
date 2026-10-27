# Fase 7 — Catálogos de vocabulario configurables (2026-10-26)

**Estado**: decisiones tomadas (§4); diseño en §6 PENDIENTE DE APROBACIÓN. Sin código hasta aprobar. Origen: análisis `erp/docs/analisis/aislamiento-nucleo-2026-10-24.md` (fase 7) y aviso de la 6d.

## 1. Objetivo
Que el vocabulario del negocio (tipos de ticket, categorías, tasa de impuesto visible) sea DATOS por empresa y no constantes del código, para que el ERP sirva a otros sectores y países sin tocar PHP.

## 2. Hallazgos (medidos sobre `master`)
| Pieza | Hoy | Acoplamiento |
|---|---|---|
| Tasa del impuesto | Ya es datos: `itbms_rates` + `ItbmsRateResolver`. **Quedan 2 residuos del 7 %**: ajuste `itbms_percent` de Servicios (default 7) y `ITBMS_RATE_LOCAL_PREVIEW = 0.07` en `GoodsReceiptWizardPage` (solo vista previa; el backend calcula con la tasa real) + 2 textos «7 %» | Bajo |
| Tipos de ticket de Servicios | Constantes `Ticket::TIPO_*` (instalación, garantía, reclamo, retrofit), `PREFIX_BY_TIPO` (INS/GAR/REC/RET), `SUBTIPOS_BY_TIPO` | **Alto**: el tipo gobierna flujo, subtipos, folio, hoja de reclamo/inspección y compuertas de cierre. 9 archivos de app, ~25 de tests, 6 del frontend |
| Categorías de proveedor | 6 constantes (`locales`, `zona_libre`, `china`, `europa`, `online`, `latinoamerica`); `locales` define origen local y dispara la validación de identificación (6c) y el ITBMS al recibir | **Medio**: la lógica depende de que `locales` sea especial |
| Tipos de arquitecto/prescriptor | `Architect::TYPES` (6 valores) | Bajo |
| Etapas del pipeline | `lead/design/quote/proposal/approved/lost` en 6 servicios | Medio (lógica de embudo) |

## 3. Opciones de diseño
- **A. Catálogo por tenant en tablas** (`catalog_entries`: dominio, clave, etiqueta, orden, activo, propiedades JSON) con CRUD en Ajustes. Las claves que el código usa para lógica quedan como «claves de sistema» no borrables; se pueden renombrar las etiquetas y AÑADIR entradas que se comporten como una existente.
- **B. Solo etiquetas** (override de texto por tenant, claves intactas): barato y seguro; no permite tipos nuevos.
- **C. Diferir los de alto acoplamiento** (tipos de ticket, etapas) y hacer solo los de bajo acoplamiento (categorías de proveedor, tipos de arquitecto, residuos del 7 %).

## 4. Decisiones de Luis (2026-10-26)
1. **Mecanismo: A — catálogo completo por tenant** (incluye tipos de ticket y etapas del pipeline), no solo etiquetas.
2. **Residuos del 7 %: se quitan** (Servicios usa la tasa por defecto de `itbms_rates`; la vista previa de recepción lee la tasa real).
3. **Entrega: 2 PR por repo** por cada sub-fase.

## 5. Criterios de aceptación (comunes)
Panamá/ILUMINACIÓN sin cambio visible con los datos por defecto; migración aditiva y reversible; sembrado de los valores actuales; tests de equivalencia; suite completa verde.

## 6. Diseño propuesto (A, en 3 sub-fases; cada una con su «adelante»)
**Principio**: la lógica no depende de la CLAVE de una entrada sino de su **comportamiento** (propiedades). Hoy el código pregunta «¿es `claim`?»; después preguntará «¿esta entrada exige hoja de reclamo?». Las 4 entradas actuales se siembran con las propiedades que hoy tienen implícitas, así que con los datos por defecto nada cambia. Una entrada de sistema (la que el código aún referencia como respaldo) no se borra, pero sí se renombra, se reordena y se desactiva si no tiene uso.

- **7a — Infraestructura + dominios simples**: tabla de tenant `catalog_entries` (`domain`, `key`, `label`, `sort`, `active`, `is_system`, `props` JSONB; única `domain+key`), modelo/servicio con caché por tenant, API de lectura general (`GET /api/catalogs/{domain}`) y CRUD con permiso fino (`settings.catalogs.manage`, solo por nivel de seguridad, con chequeo de fuga), pantalla en Ajustes y hook `useCatalog`. Primeros dominios: **categorías de proveedor** (props: `origin` local/internacional, `valida_identificacion`, `ajusta_impuesto_en_recepcion`), **tipos de arquitecto/prescriptor** y los **residuos del 7 %**. Las reglas `in:` de validación leen el catálogo.
- **7b — Tipos de ticket y subtipos** (el de mayor acoplamiento): dominio `ticket_type` con props `prefijo` de folio, `sla_dias`, `color`, `subtipos`, `comportamiento` (`instalacion` | `garantia` | `reclamo` | `retrofit` | `generico`: decide hoja de reclamo, informe de inspección, plantilla de campos, especialidad del técnico y reportes). Reemplaza `Ticket::TIPOS`, `PREFIX_BY_TIPO`, `SUBTIPOS_BY_TIPO`, los 4 `KEY_SLA_DIAS_*` (migran a props) y las 6 tablas de etiquetas duplicadas (PDF, inicio, reportes). Un tipo nuevo elige un comportamiento base y hereda su flujo.
- **7c — Etapas del pipeline**: dominio `pipeline_stage` con `tipo` (`abierta` | `ganada` | `perdida`), orden y etiqueta; los 6 servicios que repiten la lista dejan de tenerla fija. Spec propia antes de tocar el embudo.

**Riesgos conocidos**: (1) `tickets.tipo` y `pipeline_cards.stage` son texto con datos existentes → se conservan las claves actuales como entradas de sistema, sin migrar datos; (2) los permisos finos nuevos no deben compartir `security_level` con roles sin derecho (lección de la Fase 2 del esquema de permisos); (3) los textos traducidos del frontend (`servicios.json` etc.) hoy mapean clave→texto: la etiqueta pasa a venir del catálogo, con el i18n como respaldo de las claves de sistema.
