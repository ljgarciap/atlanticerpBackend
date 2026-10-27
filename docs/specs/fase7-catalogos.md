# Fase 7 — Catálogos de vocabulario configurables (2026-10-26)

**Estado**: diseño aprobado por Luis (2026-10-26). 7a IMPLEMENTADA (§7), pendiente de merge; 7b y 7c pendientes (cada una con su «adelante»). Origen: análisis `erp/docs/analisis/aislamiento-nucleo-2026-10-24.md` (fase 7) y aviso de la 6d.

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

## 7. Resultado de la 7a — infraestructura de catálogos + dominios simples (2026-10-26)
- **Tabla** `catalog_entries` (esquema `{tenant}_auth`; `domain`, `key`, `label`, `sort_order`, `is_active`, `is_system`, `props` JSONB; única `domain+key`), migración `2026_10_27_100000` aditiva y reversible, sembrada con los valores que eran constantes: 6 categorías de proveedor (con `origen` y `valida_identificacion` = lo que antes
  derivaba de `category === 'locales'`) y 6 tipos de arquitecto. Todas `is_system` (el código aún las referencia): se renombran y reordenan, no se borran ni se desactivan.
- **Código** `App\Shared\Catalogs` (`CatalogEntry`, `CatalogService`, `CatalogController`) + `config/catalogs.php` (dominios, tabla/columna en uso, propiedades admitidas con tipo). Una entrada en uso por registros no se borra ni se desactiva; las propiedades se validan contra el esquema del dominio.
- **API**: lectura general `GET /api/catalogs/domains` y `GET /api/catalogs/{domain}` (activas); administración `GET|POST|PUT|DELETE /api/admin/catalogs/{domain}[/{id}]` bajo `permission:superadmin.all` (mismo criterio que módulos y roles; **no se creó permiso nuevo**, así que no hay riesgo de fuga por nivel compartido).
- **Comportamiento tomado del catálogo**: `Provider::deriveOrigin()` y `Provider::categoryValidatesTaxId()` (validación de identificación de la 6c); las reglas `in:` de proveedores y asociados leen las claves activas. Migración `2026_10_27_100001`: se elimina el CHECK fijo de `architects.type` y la columna pasa a 60 caracteres (sin eso un tipo nuevo no se podía guardar).
- **Residuos del 7 % (decisión de Luis)**: el ajuste de Servicios `itbms_percent` ya no tiene un 7 fijo (su valor por omisión es la tasa por defecto de Configuración Fiscal; sigue siendo editable); la recepción de mercancía manda `local_tax_percentage` y la vista previa del frontend ya no usa `0.07`.
- **Frontend**: `catalogsApi`, `useCatalog`/`useCatalogOptions` (con respaldo mientras carga o si falla), pestaña **Catálogos** en Configuración global (`CatalogsPanel`: renombrar, reordenar, activar, propiedades de comportamiento, alta y borrado con el motivo del rechazo), selects de categoría de proveedor desde el catálogo (alta y detalle).
- **Limitación conocida**: la etiqueta de una entrada es un solo texto (el idioma de la empresa); las traducciones es/en solo rigen como respaldo. Los tipos de asociado todavía no se muestran desde el catálogo en el frontend (siguen con su texto traducido; el backend ya acepta los nuevos): pendiente de 7b junto con el resto del vocabulario.
- **Tests**: backend +5 (`CatalogEntriesTest`: siembra, lectura/permisos, categoría nueva con comportamiento, inactivas/inexistentes, protección de entradas de sistema y en uso); suites Compras/VentasDiseno/Servicios/Auth/Shared 2713 tests verdes, PHPStan 0. Frontend +8 (`CatalogsPanel`, `useCatalog`), 239 archivos / 2967 tests.
- Informe: `docs/reviews/fase7a-infra-catalogos-2026-10-26.md`.
