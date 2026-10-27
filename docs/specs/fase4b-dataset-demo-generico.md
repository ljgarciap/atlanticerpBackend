# Spec — Fase 4b: dataset demo genérico (2026-10-28)

**Estado**: decisiones de §6 RESPONDIDAS por Luis (2026-10-28); pendiente su «adelante» para implementar. Alcance ampliado respecto a la recomendación (ver §6).
**Continúa** `fase4-dataset-demo-generico.md` (§3.2 y §6). Esa spec se escribió el 2026-10-25, **antes** de las fases 4d, 5, 6 y 7; aquí se re-mide y se corrigen sus supuestos (§2).

## 1. Objetivo
Que `db:seed` en `local`/`dev`/`testing` cree un dataset demo con **la misma forma que hoy** pero **contenido neutro**: sin organizaciones reales, sin dominios de correo reales y sin vocabulario de iluminación. Así un cliente de cualquier sector abre el demo y no ve el negocio de otro.

## 2. Hallazgos (medidos sobre `master` 9746abf, 2026-10-28) — corrigen la spec de la Fase 4
1. **El riesgo en los tests es MUCHO menor de lo previsto.** La Fase 4 temía ~25 archivos de test acoplados al contenido del demo. Medido: los tests **crean sus propios fixtures** (`Provider::create(['name' => 'LightCorp'…])`, `SalesProject::create(['name' => 'Torre Delta'…])`); no leen los nombres del seeder. Cambiar el contenido de los seeders **no rompe ningún test por nombre**. Los 42 archivos de test con códigos como `LAMP-COL-001` y los 9 con nombres de empresas llevan el vocabulario en sus fixtures propios, no en el seeder.
2. **El demo tiene organizaciones reales, no solo vocabulario.** 11 seeders demo (~1.700 líneas; `CrmDemoSeeder` 579, `CatalogProductSeeder` 134, `ServiciosDemoSeeder` 169…). Muestra de lo sembrado hoy:
   | Dónde | Contenido actual |
   |---|---|
   | CRM (12 proyectos) | «Centro Comercial Westland», «Hotel Decameron Farallon», «Ministerio de Salud — Edificio Central», «Universidad Latina», «Supermercado El Rey Bethania», «Plaza Tocumen Mall», «Terminal Albrook»… |
   | Servicios (14 tickets) | «Banco General — Sede Principal», «Aeropuerto Marcos A. Gelabert», «Clínica Paitilla», «Super 99», «Hotel Riu Plaza», «Centro Comercial Multiplaza»… |
   | Ventas & Diseño | `[DEMO] Grupo Constructor Delta`, `Inversiones Costa Bella`, «Torre Delta Fase 1»… |
   | Contactos | correos con **dominios reales** (`@minsa.gob.pa`, `@ulatina.ac.pa`, `@elrey.com.pa`, `@decameron.com`, `@westland.com.pa`, `@aeropuertospa.gob.pa`…) |
   | Compras | proveedores «LightCorp», «Iluminex SA» |
   | Catálogo (49 productos, 10 familias) | marcas «LuxTech», «Atlantic Home»; referencias `LAMP-COL-001`, `SPOT-EMP-010`, `RIEL-TRK-050`; familias «Riel Direccionable», «Iluminación LED Básica», «Empotrables & Downlights»… |
3. **La forma a conservar** (conteos hoy): 2 clientes maestros, 2 subclientes, 3 proyectos de venta, 6 tarjetas de pipeline (una por etapa), 49 productos, 10 familias, 12 proyectos CRM, 14 tickets de Servicios, 2 proveedores. Los tests de CI corren con este demo sembrado (entorno `testing`), así que **los conteos y estados importan; los nombres no**.
4. **El frontend casi no cambia.** Los 17 archivos de `src` con nombres de empresas son todos **tests con sus propios mocks**; ningún componente de producto los lleva. Solo 7–8 specs e2e (Playwright, no corren en CI) afirman contra el demo sembrado y habría que actualizarlos.
5. **Hay dos cosas que NO son «demo» y conviene no mezclar**:
   - **Vocabulario de iluminación en el modelo del catálogo de productos** (categorías `iluminacion_techo`, `lamparas_piso_mesa`…, atributos como grado IP o vida útil estimada). Es el modelo, no el dato: es del tipo de la Fase 7 (vocabulario por empresa), y la spec de la Fase 4 ya lo dejó fuera.
   - **Los almacenes los crea una MIGRACIÓN, no un seeder** (`2026_07_21_100019_replace_bodega_warehouses_with_physical_catalog`): «Bodega Central», «Showroom Obarrio» (un barrio de Ciudad de Panamá), «Showroom SM», «Showroom Cliente», «Merma», «Reclamos y Devoluciones», «Reserva Servicios». Existen en **todo** tenant, producción incluida, y la lógica de ventas depende de esos nombres (`PARA_VENTA`, `EXCLUYEN_DISPONIBLE`, `Warehouse::operativas`). Es estructura del cliente anterior dentro del núcleo: mismo problema que la clase B de la 4a, pero escondido en una migración.

## 3. Diseño propuesto
### 3.1 `DemoDataset` — un único archivo de constantes
Todos los textos demo (nombres, razones sociales, contactos, productos, familias, proveedores, sitios, proyectos) salen de `Database\Seeders\Support\DemoDataset`, no repartidos por 11 seeders. Los seeders **conservan su lógica y sus conteos** y solo leen de ahí.

### 3.2 Contenido neutro
| Hoy | Genérico |
|---|---|
| Proyectos CRM / de venta («Westland», «Decameron», «Torre Delta»…) | `[DEMO] Proyecto 01…12` (CRM), `[DEMO] Proyecto Alfa/Beta/Gamma` (venta) |
| Clientes maestros / subclientes | `[DEMO] Cliente Maestro A/B`, `[DEMO] Cliente A1/B1` |
| Obras de Servicios («Banco General — Sede Principal»…) | `[DEMO] Sitio 01…14` |
| Contactos con correos de empresas reales | `contacto01@demo.test` … |
| Proveedores «LightCorp», «Iluminex SA» | `[DEMO] Proveedor A/B` |
| Productos `LAMP-COL-001 · Lámpara colgante LED…` (marcas LuxTech/Atlantic Home) | `DEMO-001 · Producto demo 1…49`, marcas `Marca demo A/B` |
| Familias «Riel Direccionable»… | `Familia demo 01…10` |
Se conserva el prefijo `[DEMO]` (ya lo usan la limpieza de datos de prueba y los filtros).

### 3.3 Contrato de aceptación automático
- **Forma**: test que siembra el demo y compara conteos por tabla y por estado contra los de hoy (2/2/3/6/49/10/12/14/2…).
- **Neutralidad**: test que recorre las tablas sembradas y falla si encuentra un término de la lista prohibida (organizaciones, dominios y marcas de §2 + vocabulario de iluminación: `ilumin`, `lámpara`, `luminaria`, `LED`, `spot`, `riel`…).
- **Fuente**: el mismo escaneo sobre `database/seeders/` (nada de nombres reales en el código que siembra).
- **Repositorio completo (decisión 2)**: `NeutralidadDelRepoTest` (backend) y `neutralidadDelRepo.test.ts` (frontend) escanean `app/`, `database/`, `tests/`, `src/` y `e2e/` con la lista de entidades reales **y** de vocabulario del sector, con las excepciones de §6.1 como lista explícita que solo puede encogerse.

## 4. Fuera de alcance
Modelo del catálogo de productos (atributos y categorías de iluminación) → fase «catálogo de productos genérico»; los **almacenes** creados por migración y `showroom` en Bodega → fase «almacenes configurables» (ambas con su excepción temporal en el contrato, §6.1); fiscal y Legacy (ya hechos).

## 5. Entrega y verificación
Backend (1 PR): `DemoDataset` + los 11 seeders + ~100 archivos de test con fixtures neutralizados + los tests de contrato. Frontend (1 PR): mocks de ~85 archivos de `src` y los e2e. Reemplazo mecánico con un script con tabla de equivalencias (como en las fases 3 y 4a), verificado con las suites completas; los fixtures de los tests conservan su forma (mismas relaciones y valores), solo cambian los textos. Verificación: PHPStan L8; **suite completa del backend** (debe pasar sin tocar ningún test por nombre — si alguno se rompe, el supuesto del hallazgo 1 es falso y se reabre); `tsc` + vitest del frontend; Playwright CLI antes/después con un Vendedor, un Técnico y un Auxiliar de Bodega: mismas pantallas, mismos conteos, sin vocabulario del cliente anterior.

## 6. Decisiones de Luis (2026-10-28)
1. **Alcance: contenido sembrado + fixtures de tests y mocks** (no solo lo sembrado). ≈ 250 archivos de test, mocks y e2e entre los dos repos, por reemplazo mecánico.
2. **Contrato: «todo el repositorio»**, incluido el **vocabulario del sector** (iluminación/lámpara/luminaria/showroom), no solo las entidades reales.
3. **Almacenes por migración: fase propia después** (no se incluyen en la 4b).
4. **Entrega: 1 PR por repo.**

### 6.1 Cómo se reconcilian 2 y 3 (propuesta del Analista, a confirmar con el «adelante»)
El vocabulario del sector no es solo dato demo: está en el **modelo** (categorías `iluminacion_techo`, `lamparas_piso_mesa`…, atributos de producto de iluminación; 31 archivos de `app/`, 56 del frontend) y en la **estructura de Bodega** (`showroom`, 11 archivos que además llevan «Obarrio»). Prohibirlo en todo el repo hoy es imposible sin esas fases. Por eso el contrato es **el estado final**, y la 4b lo introduce con una **lista de excepciones explícita, versionada y que solo puede encogerse** (el test falla si una excepción ya no hace falta, o si se añade una nueva sin justificar), igual que `ClientDataInRepoTest` de la 4a:
- **Excepciones permanentes por inmutabilidad**: migraciones históricas y documentos fechados (reportes de QA, ADR y specs en `docs/`, que se archivan, no se reescriben).
- **Excepciones temporales con dueño** (cada una se borra cuando su fase termine): (a) modelo del catálogo de productos y sus categorías/atributos → **fase «catálogo de productos genérico»** (por abrir); (b) Bodega: `showroom` y los 11 archivos con «Obarrio» → **fase «almacenes configurables»** (por abrir, ya decidida como posterior).
Todo lo demás (seeders, tests, mocks, e2e, i18n que no sea del modelo, comentarios) queda **limpio al terminar la 4b**.

## 7. Riesgos
- Que algún test dependa en silencio del demo sembrado (conteos, orden, un nombre). Mitigación: forma idéntica + suite completa antes del PR; si falla, se corrige el seeder, no el test.
- El prefijo `[DEMO]` y los nombres cortos pueden afectar búsquedas/orden alfabético que los tests asumen: por eso se conserva la forma, incluido el orden relativo.
- Un cliente que ya tenga el demo viejo sembrado no cambia solo (hay que resembrar); no se migra ningún dato.
