# Spec — Fase 4b: dataset demo genérico (2026-10-28)

**Estado**: BORRADOR del Analista, pendiente de las decisiones de §6. No se implementa sin el «adelante» de Luis.
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

## 4. Fuera de alcance
Modelo del catálogo de productos (atributos y categorías de iluminación) → fase tipo 7; los **almacenes** creados por migración (ver decisión 4); fixtures propios de los tests y mocks del frontend (§6, decisión 2); fiscal y Legacy (ya hechos).

## 5. Entrega y verificación
Backend: `DemoDataset` + los 11 seeders + los 2 tests de contrato. Frontend: solo los 7–8 specs e2e que afirman contra el demo. Verificación: PHPStan L8; **suite completa del backend** (debe pasar sin tocar ningún test por nombre — si alguno se rompe, el supuesto del hallazgo 1 es falso y se reabre); `tsc` + vitest del frontend; Playwright CLI antes/después con un Vendedor, un Técnico y un Auxiliar de Bodega: mismas pantallas, mismos conteos, sin vocabulario del cliente anterior.

## 6. Decisiones que necesita de Luis
1. **Alcance**: ¿solo el contenido sembrado (recomendado), o además neutralizar los fixtures de ~100 archivos de test y mocks (cosmético, mecánico, sin valor funcional)?
2. **Fixtures de tests y mocks con nombres reales**: ¿se dejan (recomendado: son datos de prueba autocontenidos) o se neutralizan en una pasada aparte?
3. **Contrato**: ¿el escaneo de términos prohibidos se aplica solo al demo y a los seeders (recomendado) o a todo el repositorio?
4. **Almacenes por migración**: ¿se abre una fase propia para que el catálogo de almacenes sea configurable por empresa (como la Fase 7), o se acepta como está por ahora?
5. **Entrega**: ¿1 PR por repo (recomendado: el cambio es acotado) o 2 por repo como en la 7?

## 7. Riesgos
- Que algún test dependa en silencio del demo sembrado (conteos, orden, un nombre). Mitigación: forma idéntica + suite completa antes del PR; si falla, se corrige el seeder, no el test.
- El prefijo `[DEMO]` y los nombres cortos pueden afectar búsquedas/orden alfabético que los tests asumen: por eso se conserva la forma, incluido el orden relativo.
- Un cliente que ya tenga el demo viejo sembrado no cambia solo (hay que resembrar); no se migra ningún dato.
