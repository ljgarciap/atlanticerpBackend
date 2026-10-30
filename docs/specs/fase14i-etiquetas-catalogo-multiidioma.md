# Fase 14i — Etiquetas de catálogo en varios idiomas

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); «adelante con 14i» dado; **implementada** (§8), pendiente de mezclar. Origen: «Abierto» de las Fases 7, 14c y 14b: «una etiqueta por entrada (idioma de la empresa); en inglés las listas muestran el texto sembrado en español». No se implementa sin su «adelante».

## 1. Objetivo
Los catálogos por empresa (categorías de proveedor, formas de pago, tipos de ticket, etapas del pipeline, motivos, especialidades…) guardan UNA etiqueta por entrada. Un usuario con la interfaz en inglés ve esas listas en español, mientras el resto de la pantalla está en inglés. Que cada entrada pueda tener su etiqueta en cada idioma de la aplicación, con un respaldo sensato cuando falta.

## 2. Hallazgos (medidos sobre `master`)
- `catalog_entries.label` es un `varchar(120)` único; los 11 dominios sembrados (≈110 entradas) quedaron en español.
- La interfaz soporta `es` y `en`. El idioma del usuario vive en `users.preferences.language`, con el idioma por defecto del sistema como respaldo (`default_language`).
- El **frontend** lee los catálogos por ~8 hooks (`useCatalog`, `useFixedLists`, `useProductCategories`, `useTicketTypes`, `useTechnicalSpecFields`, `usePipelineStages`, `usePaymentMethods`, `ProviderFormDrawer`); casi todos ya traen un **respaldo con traducciones de la interfaz** mientras carga o si falla (por eso hoy, sin conexión al catálogo, se ve en inglés; con catálogo, en español).
- El **backend** usa la etiqueta en documentos y exportaciones generados por el servidor (recibo y arqueo en PDF, documento fiscal, Excel, correos) a través de fachadas (`PaymentMethods::label`, `TicketTypes::labels`, `PipelineStages`…).
- La pantalla de administración (`CatalogsPanel`, Ajustes → Catálogos) edita una sola etiqueta por entrada.

## 3. Diseño propuesto
- **Datos**: columna nueva `catalog_entries.labels` (`jsonb` nullable, `{"en": "Wire transfer"}`) con las traducciones; `label` se conserva como la etiqueta base y respaldo (hoy en español). Migración aditiva y reversible.
- **Idiomas**: lista en `config/catalogs.php` (`languages`: `es` y `en`); agregar un idioma es agregar una fila. `es` es la etiqueta base (`label`); el resto vive en `labels`.
- **API**: `GET /api/catalogs/{domain}` devuelve `label` YA resuelta en el idioma del usuario (traducción si existe; si no, la base) y `labels` con todas; el admin (`/api/admin/catalogs/...`) edita `labels` por idioma. Validación: clave de idioma en la lista, texto ≤ 120.
- **Backend**: `CatalogService::label($domain, $key, ?$lang)` resuelve con el mismo respaldo; las fachadas lo usan. Idioma de los documentos según decisión 3.
- **Frontend**: `CatalogsPanel` suma un campo por idioma adicional («Etiqueta en inglés»), con la base como ayuda; los hooks no cambian (reciben `label` ya resuelta).
- **Semilla**: una migración de datos carga la traducción al inglés de las entradas que ya tienen una en la interfaz (formas de pago, motivos, especialidades, categorías…); el resto queda vacío y cae a la etiqueta base (decisión 4).

## 4. Criterios de aceptación
1. Con la interfaz en inglés, las listas del catálogo se ven en inglés donde hay traducción y en español (etiqueta base) donde no.
2. Con la interfaz en español nada cambia.
3. Un superadmin puede escribir la etiqueta en inglés de cualquier entrada desde Ajustes → Catálogos; vaciarla vuelve al respaldo.
4. Los documentos generados por el servidor usan el idioma definido en la decisión 3.
5. Las entradas existentes y sus claves no cambian; ningún dato se pierde; la migración es reversible.
6. Tests de API (resolución por idioma y respaldo), de la fachada y del panel; PHPStan, `tsc` y suites en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Idiomas**: `es` (base) y `en`, extensibles por configuración. ✅
2. **Si falta la traducción**: se muestra la etiqueta base en español. ✅
3. **Documentos del servidor** (PDF, Excel, correos): en el idioma del usuario que los genera; si no hay usuario (trabajos y correos automáticos), el idioma por defecto de la empresa. ✅
4. **Semilla en inglés**: se carga la traducción de las entradas que ya la tienen en la interfaz; el resto queda vacío y cae a la base. ✅
5. **Entrega**: 1 PR de backend y 1 de frontend — propuesta, no se preguntó.

## 6. Riesgos
- Dos fuentes de traducción para los mismos textos (el JSON de la interfaz y el catálogo): el catálogo manda cuando hay datos; el JSON queda solo como respaldo mientras carga. Hay que dejarlo documentado para no mantener ambos a mano.
- Los documentos ya emitidos (PDF guardados) no cambian.

## 7. No-alcance
Traducir las claves, las propiedades o los nombres de los dominios; el contenido libre de los usuarios (descripciones, notas); más de dos idiomas en la interfaz; y la traducción automática con IA (existe para las órdenes de compra y no se reutiliza aquí).

## 8. Resultado
- **Migración `2026_11_08_100000`** (reversible): columna `catalog_entries.labels` (jsonb) y siembra del inglés de 54 de las 55 entradas existentes, emparejadas con las traducciones que la interfaz ya traía (formas de pago, motivos, especialidades, categorías, etapas, tipos de ticket…). Solo escribe donde `labels` está vacío. La base (`label`) no cambia.
- **Backend**: `config/catalogs.php` `languages` (`es` base, `en`); `CatalogEntry::labelFor()`; `CatalogService::currentLanguage()` (idioma del usuario por el `sub` del JWT, una consulta por petición; sin usuario, el idioma por defecto de la empresa), `labelOf()` y `labels()`. `GET /api/catalogs/{domain}` devuelve `label` ya resuelta y `labels`; la administración devuelve la base y las traducciones y acepta `labels` por idioma (vacío = quita la traducción; idioma sin soporte o la base = 422). `GET /catalogs/domains` informa `languages`. Las fachadas (`PaymentMethods`, `TicketTypes`, `PipelineStages`) y el PDF del catálogo usan el idioma resuelto.
- **Frontend**: Ajustes → Catálogos tiene un campo por idioma adicional (al editar y al crear); `useCatalog` incluye el idioma en la clave, así que cambiar el idioma vuelve a pedir el catálogo; los hooks no cambian.
- **Nota**: la primera etiqueta de una petición hace UNA consulta (idioma por defecto de la empresa); el test de memo (`CatalogCacheTest`) la calienta antes de exigir cero consultas en el bucle.
- **Verificación**: `CatalogLabelsTest` (sembrado en inglés, resolución por idioma con respaldo, administración y validación, idiomas en `domains`, fachadas con y sin usuario), 2 tests del panel; backend 5238 tests y PHPStan 0; frontend 247 archivos / 2976 tests y `tsc` limpio.
