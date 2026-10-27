# Senior Review — Fase 4b: dataset demo genérico (2026-10-28)

**Alcance**: backend y frontend, rama `fase4b/dataset-demo` en ambos repos (1 PR por repo).
**Veredicto**: APROBADO. 0 bloqueantes. 5 hallazgos propios corregidos durante la implementación, 4 sugerencias abiertas y 2 excepciones temporales con dueño.

## Qué se hizo
- **`DemoDataset`** (`database/seeders/Support/`): única fuente de los textos demo. Los 11 seeders demo conservan lógica, conteos, estados y relaciones y solo leen de ahí. Nombres, contactos, correos, proveedores, marcas, productos y familias son neutros (`[DEMO] Proyecto 01`, `contacto01@demo.test`, `DEMO-001 · Marca Demo A`…). La red producto-familia (49 membresías, un producto en varias familias) se conserva con una tabla de equivalencias referencia→`DEMO-NNN`.
- **Tests y mocks**: ~115 archivos del backend y 118 del frontend (tests, mocks y e2e) neutralizados por reemplazo mecánico con tabla de equivalencias; i18n fuera del motor. Se retira la categoría huérfana `LAMPARAS` (venía del catálogo de Lafayette, retirado en la Fase 5) y su test.
- **Contratos**: `DemoDatasetNeutralidadTest` (ejerce los seeders demo —que se saltan en `testing`— cambiando el entorno a `local`; compara la FORMA y escanea las tablas sembradas), `NeutralidadDelRepoTest` (repo completo del backend) y `neutralidadDelRepo.test.ts` (frontend). Lista única de términos en `Tests\Support\TerminosDelClienteAnterior`.

## Hallazgo de la medición que cambió el riesgo
Los tests **crean sus propios fixtures** (`Provider::create(['name' => 'LightCorp'])`), no leen el contenido del seeder, y los seeders demo se saltan en `testing`. La Fase 4 preveía ~25 tests acoplados; en la práctica cambiar el demo no rompe ningún test por nombre. El riesgo real estuvo en los fixtures de **búsqueda tolerante a acentos y typos** (ver hallazgo 4).

## Hallazgos corregidos durante la implementación
1. **El motor reescribió prompts de IA de producción** en `app/` («empresa de diseño y suministro de iluminación» → «…de catalogación», sin sentido) y una plantilla de correo. Es trabajo de la 4c: se revirtió todo `app/`, `resources/`, `config/` y `routes/`; el motor se limita a tests y seeders y solo se tocaron a mano 4 comentarios.
2. **Reemplazo dentro de un identificador**: `..._usa_banistmo_...` pasó a `..._usa_banco demo b_...` y el archivo no compilaba. El motor ahora une con guion bajo cuando la coincidencia toca un identificador.
3. **El motor reescribió los propios contratos** (sus listas de términos, p. ej. `candelabro`→`modelo`) y rompió su lógica. Los archivos de contrato se excluyen del motor.
4. **16 tests de búsqueda rotos** (acentos `Lámpara`↔`lampara` y typos `lampra`, `ilumnacion`): «Producto»/«Artículo» perdían el acento, chocaban con otros fixtures y no cubrían los typos escritos a propósito. Para esos 8 archivos se usó una tabla que **conserva acentos y longitud** (`Vástago`, `Artefacto`, `Catalogación`) y se adaptaron los typos a mano (`vastgo colgnte`, `catalgacion`, `artefcto cilindrca`…). Detalle: la similitud trigram exige palabras de longitud parecida (una de 5 letras no sirve para sustituir a una de 9).
5. **Casos que el motor no podía ver**: variantes en MAYÚSCULAS (`LAMPARA`, `CANDELABRO-1`), palabras pegadas a un dígito (`luminaria1`), un nombre inventado con acento (`Ilumínica Panamá`) y consultas en minúscula (`led-10`).

## Excepciones del contrato (solo pueden encogerse)
- **Permanentes**: migraciones históricas y documentos fechados de `docs/` (se archivan, no se reescriben).
- **Temporales con dueño** (el contrato falla si un dueño ya no tiene ningún uso o si un archivo pendiente ya está limpio):
  - **«catálogo de productos genérico»**: las 8 claves de `CatalogProduct::CATEGORIES` (`iluminacion_techo`, `lamparas_piso_mesa`, `bombillos`…), sus etiquetas i18n, el campo `dialux` del CRM y las búsquedas por etiqueta de categoría.
  - **«almacenes configurables»**: `showroom` y «Obarrio». Se ve en pantalla («En Showroom Obarrio: 100» en el catálogo y el inventario de Bodega).
  - **Fase 4c**: 5 prompts de IA con el sector fijo y la plantilla PDF del estado de cuenta de comisiones («Atlantic Diseño e Iluminación, S.A.», razón social del cliente anterior).

## Sugerencias abiertas (no bloquean)
1. **Nombres de personas** en fixtures de tests (contactos con nombre y apellido): el contrato cubre organizaciones, marcas, bancos y lugares, no personas. No se auditó.
2. **Los specs e2e `preqa-*` no corren en CI**: se neutralizaron por reemplazo mecánico sin ejecutarlos; los que afirman contra datos demo concretos pueden necesitar ajuste al correrlos contra el demo nuevo.
3. **Entornos ya sembrados** con el demo viejo no cambian solos; hay que resembrar. Además `DemoProviderAssignmentSeeder` crea por `firstOrCreate` por nombre, así que un entorno viejo acabaría con «LightCorp» y «[DEMO] Proveedor A» a la vez.
4. **Seeders de «datos del cliente»** sin llamador (clase B de la 4a) se neutralizaron como ejemplos; siguen en el repo hasta que se decida borrarlos.

## Verificación
- PHPStan L8: 0 errores. Backend, suite completa: **5150 tests, 0 fallos**. Frontend: `tsc` limpio, **242 archivos / 2978 tests**.
- **Playwright CLI con la BD local reseteada y sembrada con el demo nuevo** (forma idéntica: 2 clientes maestros, 3 proyectos de venta, 6 tarjetas, 49 productos, 10 familias, 12 proyectos CRM, 14 tickets, 2 proveedores): Vendedor, Técnico de Servicios y Auxiliar de Bodega (12 pantallas) y Gerencia con el ámbito «Equipo» y detalles abiertos (13 pantallas) → **0 coincidencias prohibidas** en el texto visible. Lo único del cliente anterior que se ve es «Showroom Obarrio» (almacén, excepción temporal).
