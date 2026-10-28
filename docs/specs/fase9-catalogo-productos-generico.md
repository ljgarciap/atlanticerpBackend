# Spec — Fase 9: catálogo de productos genérico (retira la última excepción del contrato de neutralidad)

**Estado**: decisiones de Luis registradas (§6, 2026-10-29) — pendiente de su «adelante» para implementar.
**Origen**: excepciones temporales con dueño «catálogo de productos genérico» en `tests/Support/TerminosDelClienteAnterior::PERMITIDOS` y su espejo en `neutralidadDelRepo.test.ts`.

## 1. Hechos verificados (medidos hoy en `master`)
- **Categorías de producto fijas en código**: `CatalogProduct::CATEGORIES` = 8 claves del sector de iluminación (`candelabros_colgantes`, `iluminacion_techo`, `apliques_pared`, `iluminacion_bano`, `iluminacion_exterior`, `lamparas_piso_mesa`,
  `iluminacion_empotrada`, `bombillos`). La usan 4 validaciones (`StoreInventoryProductRequest` — **obligatoria** al crear —, `UpdateInventoryProductRequest`, `StorePurchaseOrderRequest` para producto nuevo en una orden) y el
  generador de la foto de relleno UAT (`CatalogUatPlaceholderPhotoController`, mapa de colores por categoría). La columna `catalog_products.category` es un `string(50)` nullable, **sin CHECK ni FK**: ya admite cualquier clave.
- **Frontend**: lista duplicada en `NewProductModal.tsx` (`CATALOG_PRODUCT_CATEGORIES`) y en `InventarioPage.tsx`; las etiquetas viven en i18n (`compras:…categories.*`, es/en) y se muestran en Inventario, el catálogo de Ventas y reposiciones.
- **El filtro y la búsqueda del catálogo ya son por datos** (`distinctActiveCategories`: categorías realmente usadas), no por la lista fija.
- **Mecanismo listo**: la Fase 7 dejó los catálogos por empresa (`catalog_entries`, `config/catalogs.php`: dominio con `usage` = tabla/columna que guarda la clave, entradas de sistema/en uso protegidas, propiedades tipadas, API pública y de admin, hooks `useCatalog`/`useCatalogOptions`). Las categorías son un dominio más.
- **`dialux`**: booleano `crm_projects.dialux` («incluye cálculo en DIALux», software de cálculo de iluminación). Aparece en 7 archivos de backend (modelo, requests, controlador, servicio, 2 prompts de IA, seeder demo), en el filtro del listado de proyectos (`dialux=all|only|none`), en la tarjeta del proyecto (insignia ámbar) y en el modal; i18n es/en.
- **Hallazgo adicional (no estaba en el contrato)**: los **12 sub-campos de la ficha técnica** (`CatalogProduct::TECHNICAL_SPEC_FIELDS`: `voltage`, `power`, `socket_type`, `color_temperature`, `luminous_flux`, `dimensions`, `weight`, `material_finish`, `ip_rating`,
  `estimated_lifespan`, `warranty`, `certifications`) son vocabulario de iluminación: se validan con esa lista, se muestran en el modal de ficha técnica (`TechnicalSpecModal`), el PDF del catálogo y los i18n de Bodega/Compras. Se guardan en un JSONB (`technical_spec`), así que las claves ya son libres en BD.

## 2. Diseño propuesto
**9a — Categorías de producto como catálogo por empresa** (cierra la mayor parte del contrato)
1. Dominio `product_category` en `config/catalogs.php` (`usage` = `CatalogProduct`/`category`; propiedad opcional `color` `#RRGGBB` para la foto de relleno UAT). Sin propiedades de comportamiento: la lógica nunca pregunta por una categoría concreta.
2. Las 4 validaciones leen las claves vigentes del dominio (fachada `Catalogs`) en vez de `CATEGORIES`; se retira la constante.
3. **Migración de datos sin vocabulario en el código nuevo**: crea una entrada por cada `category` DISTINTA que ya exista en `catalog_products` (etiqueta = clave humanizada, editable después en Ajustes) y, siempre, una entrada de sistema «General» (`general`) para que
   el campo obligatorio tenga al menos una opción en un tenant nuevo. Así un entorno con datos conserva sus categorías y un tenant nuevo arranca neutro.
4. Frontend: `NewProductModal`, `InventarioPage` y las etiquetas usan `useCatalogOptions('product_category')`; se borran las listas duplicadas y las claves i18n `categories.*`.
5. Demo: `CatalogUatTestDataSeeder` y la foto de relleno pasan a categorías neutras (`general`, `accesorios`, `repuestos`, `consumibles`) sembradas como entradas del dominio.
6. Contrato: se quitan de `PERMITIDOS` (ambos repos) las claves y etiquetas de categoría.

**9b — `dialux` → campo genérico** (según decisión §6-2)
Renombre no destructivo de la columna y del filtro de API, textos i18n neutros, y los 2 prompts de IA leen el campo genérico. Se quita `dialux` de `PERMITIDOS`.

**9c — Ficha técnica configurable** (solo si Luis lo incluye, §6-3)
Dominio `technical_spec_field` (clave + etiqueta + orden) con los campos genéricos de sistema (dimensiones, peso, material, garantía, certificaciones) y, por migración de datos, las claves que ya tengan valor en algún producto. `TECHNICAL_SPEC_FIELDS`, el modal, el PDF y los i18n
dejan de fijar vocabulario de iluminación. Se añade al contrato (`luminous`, `flujo luminoso`, `casquillo`, `color_temperature`…) para que no vuelva.

## 3. Fuera de alcance
Familias de producto (ya genéricas desde la 4b), rotación (`alta/media/baja/unica`, genérica), el modelo de datos de `technical_sheet` (archivo), cualquier cambio de precios o stock.

## 4. Criterios de aceptación
1. Ningún archivo versionado fuera de migraciones históricas y `docs/` contiene las 8 claves ni sus etiquetas, ni `dialux` (contrato sin excepciones de catálogo de productos).
2. Un tenant nuevo ofrece solo «General» y las categorías que su superadmin cree en Ajustes; crear un producto con una categoría propia funciona de punta a punta (alta, filtro del catálogo, ficha, PDF).
3. Un entorno con datos conserva cada categoría ya asignada (misma clave) tras migrar, y `down()` no pierde datos.
4. No se puede borrar una categoría en uso (comportamiento del dominio ya existente).
5. PHPStan L8, suites completas de backend y frontend en verde; Playwright CLI: Inventario, Catálogo de Ventas, alta de producto y Ajustes → Catálogos sin el vocabulario viejo.

## 5. Riesgos
- Es la fase con más texto de sector repartido (i18n es/en en 5 archivos, ~17 archivos de test/e2e en el frontend y varios en el backend): mismo método mecánico de las Fases 4b/4c/8, con el contrato como red.
- Un cambio de contrato de API si se renombra `dialux` (filtro y campo): backend y frontend se mezclan en orden (backend primero).
- La migración de datos deriva las categorías de lo que hay: un entorno con productos de categorías mal escritas las conserva tal cual (se corrigen desde Ajustes).
- Respaldo obligatorio antes de migrar un entorno con datos (regla del workspace para cambios de BD).

## 6. Decisiones de Luis (2026-10-29)
1. Categorías por defecto: **«General» (de sistema) + ejemplos neutros solo en el demo** (Accesorios, Repuestos, Consumibles); un tenant de producción arranca solo con «General». ✅
2. `dialux`: **renombrar a «Requiere estudio técnico»** (no destructivo: columna, filtro de API y textos). ✅
3. Ficha técnica: **9c entra en esta fase** (dominio configurable por empresa). ✅
4. Entrega propuesta (a confirmar con el «adelante»): 1 PR por repo por sub-fase, apilados: 9a categorías → 9b estudio técnico → 9c ficha técnica.
