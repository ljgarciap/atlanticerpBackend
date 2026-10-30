# Fase 14i — Etiquetas de catálogo en varios idiomas

**Estado**: EN BORRADOR, pendiente de que Luis decida §5. Origen: «Abierto» de las Fases 7, 14c y 14b: «una etiqueta por entrada (idioma de la empresa); en inglés las listas muestran el texto sembrado en español». No se implementa sin su «adelante».

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

## 5. Decisiones para Luis
1. **Idiomas**: `es` (base) y `en`, extensibles por configuración. Recomendado: **sí**, son los de la aplicación.
2. **Si falta la traducción**: mostrar la etiqueta base en español. Recomendado: **sí** (nunca una entrada en blanco).
3. **Idioma de los documentos del servidor** (PDF, Excel, correos): **el del usuario que lo genera**, y el idioma por defecto de la empresa cuando no hay usuario (trabajos automáticos, correos). Alternativa: siempre el de la empresa. Recomendado: **el del usuario**, con ese respaldo.
4. **Semilla en inglés**: cargar la traducción de las entradas que ya la tienen en la interfaz y dejar el resto vacío. Recomendado: **sí**; el resto se completa desde la pantalla.
5. **Entrega**: 1 PR de backend y 1 de frontend — propuesta, no se preguntó.

## 6. Riesgos
- Dos fuentes de traducción para los mismos textos (el JSON de la interfaz y el catálogo): el catálogo manda cuando hay datos; el JSON queda solo como respaldo mientras carga. Hay que dejarlo documentado para no mantener ambos a mano.
- Los documentos ya emitidos (PDF guardados) no cambian.

## 7. No-alcance
Traducir las claves, las propiedades o los nombres de los dominios; el contenido libre de los usuarios (descripciones, notas); más de dos idiomas en la interfaz; y la traducción automática con IA (existe para las órdenes de compra y no se reutiliza aquí).
