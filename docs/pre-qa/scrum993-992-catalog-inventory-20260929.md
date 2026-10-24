# Pre-QA — SCRUM-993 (Inventario de Compras en SQL) + SCRUM-992 (búsqueda trigram)

Fecha: 2026-09-28/29 (UTC) · Entorno: dev.atlanticerp.ai · Backend origin/dev 819a566 (desplegado 01:18 UTC; de 00:52 a 01:18 UTC
estuvo bd59a0d = solo SCRUM-993, y ahí corrieron mis primeras pruebas de chips; las re-corrí completas sobre 819a566) ·
Frontend f30383c · Catálogo real: 29.630 productos activos + 5 inactivos (+1 de prueba, ver "Escrituras").
Método: API real con token temporal de superadmin (emitido por tinker; tokens de rol restringido/limitado/sin permiso con claims
sintéticos), verificación contra SQL independiente (solo SELECT), Playwright CLI headless con sesión inyectada (localStorage) contra
dev.atlanticerp.ai, vitest. NO se usó Claude in Chrome. No se tocó código, no hubo push, ni transiciones/comentarios en Jira.

## Veredicto

- **SCRUM-993: pasada limpia** (sin hallazgos CRÍTICO/ALTO propios). Paridad exacta de los 7 chips, 10 combinaciones de orden,
  paginación, kpis, consumidores y concurrencia. Ver "Lo que sí funciona".
- **SCRUM-992: NO pasa a QA todavía — vuelve a PM.** Hallazgo H1 (ALTO): la pantalla **Catálogo de Ventas&Diseño**
  (`GET /api/ventas-diseno/catalog`, la que usa `CatalogPage.tsx`, donde sí se puso el debounce) NO adoptó nada de SCRUM-992/993:
  sigue cargando ~30K modelos + `families` por request (2,6–4,3 s, ~176 MB de pico con `memory_limit` 256 MB). La spec lista
  "Catálogo (Ventas&Diseño)" en Fase 1 y los criterios 1 y 2. No lo autoclasifico como alcance diferido: **PM/Luis deben confirmar**
  si el `/catalog` está dentro o fuera de SCRUM-992 (y si es el mismo riesgo de 500 de SCRUM-993). Hasta esa confirmación
  este hallazgo bloquea el cierre de SCRUM-992.
- El resto de hallazgos son MEDIO/BAJO, no bloqueantes por sí solos (detalle abajo).

## Hallazgos

### H1 — ALTO (bloqueante de SCRUM-992 hasta decisión de PM/Luis) — `GET /api/ventas-diseno/catalog` sin migrar
- Criterio incumplido: SCRUM-992 #1 (p95 < 300 ms en Catálogo) y #2 (ninguna búsqueda hidrata más de per_page/límite; < 32 MB);
  spec "Fase 1 = Catálogo (Ventas&Diseño)". Mismo patrón de causa raíz de SCRUM-993 (`->get()->all()` del catálogo completo).
- Código: `CatalogService::list()` hace `CatalogProduct::with('families')->where('is_active')->...->get()->all()`, luego
  `committedMap/enCaminoMap/enBodegaExcluidaMap` sobre ~30K IDs, filtra el texto con `str_contains` en PHP y pagina con
  `Pagination::ofArray()`. No usa `search_text`/`applySearch()`. Último commit del servicio: d03f5aa (anterior a SCRUM-992).
- Reproducción (superadmin): `GET https://dev.atlanticerp.ai/api/ventas-diseno/catalog?search=lampara+colgante&page=1&per_page=20`
  y `?per_page=20` y `?per_page=all`.
- Esperado: < 300 ms, pocos MB. Real: 200 pero 2,6–4,3 s por request (medido por HTTP: 3,5 s, 4,3 s, 3,0 s, 3,35 s, 2,6 s) y en
  proceso (tinker, `handle()`): 3,0–3,4 s con pico ~175,7 MB frente a `memory_limit=256M` (69 %). Sin búsqueda, con búsqueda,
  con `per_page=all` y con `stock=con`: mismo orden de magnitud. Es la pantalla donde el usuario más teclea (debounce 250 ms ya
  aplicado en frontend, pero cada request cuesta 3–4 s), y con 2–3 usuarios concurrentes está cerca del mismo agotamiento de
  memoria que causó los 500 de SCRUM-993.
- Evidencia UI: en Playwright, escribir "lampara colgante" y esperar 4,5 s aún mostraba el listado sin filtrar ("29630 productos");
  a los 14 s recién mostró "238 productos" (la latencia de 3–4 s del endpoint, no un bug del debounce).

### H2 — MEDIO — `GET /api/bodega/inventory`: 2,0–3,7 s y ~161 MB de pico por request
- Reproducción: `GET /api/bodega/inventory?per_page=20` (con o sin `search`). HTTP 2,0–3,7 s en 15 mediciones; en proceso
  1,9–2,3 s / ~161 MB (límite 256 MB). Causa ya anotada en el Senior Review de SCRUM-992 ("Hallazgo fuera de alcance",
  `BodegaInventoryKpiService::kpis()` hidrata el catálogo). Funciona (200) pero es la misma familia del 500 de dev; recomiendo
  confirmar con PM que existe el ticket aparte y su prioridad. La búsqueda de Bodega en sí es correcta (ver abajo).

### H3 — MEDIO — Respaldo tolerante a typos: más lento que el criterio y con falsos positivos con términos cortos/especiales
- Latencia del respaldo (0 resultados parciales): Inventario 0,8–1,0 s en proceso (1,2–1,5 s por HTTP); Nueva Orden 0,5–0,7 s;
  criterio #1 pide p95 < 300 ms. La ruta parcial sí cumple (30–110 ms en proceso).
- Falsos positivos (`word_similarity >= 0.5` sobre términos cortos/basura): Inventario `search=100%` -> 9.063 resultados con
  `fuzzy=true` (los "100-" de referencias); `search=x%y` -> 156; una palabra de 39 letras `aaaa...` -> 55; Nueva Orden `zzzqq` ->
  2 (RF04105, RF11004, estos sí contienen "zzz"). Es diseño aprobado (umbral 0.5) pero el usuario ve miles de filas "aproximadas"
  para un término que no existe. Sugerencia para PM: tope o umbral mayor para términos < 4 caracteres.

### H4 — MEDIO — Ruta "Buscar Sustituto" sin tope (conservada a propósito)
- `GET /api/compras/products?provider_id=187&original_catalog_product_id=1` (sin `search`): 200 en ~3,9 s, ~135 MB de pico,
  devuelve todos los candidatos del proveedor (25.8K) para rankear en PHP. El Senior lo aceptó ("conserva el comportamiento
  previo"), pero no es escalable con Lafayette. La UI real (`ReplacementRequestsPage`) siempre manda `search` o `original`
  con término; con `search` sí se acota a 500. Riesgo bajo de uso, listado por completitud.

### H5 — BAJO — Nueva Orden/Zona Libre: tope de 100 sin indicación en la UI
- Con Lafayette (25.816 productos) el picker sin texto muestra solo los primeros 100 (orden por nombre) y con "led" solo 100 de
  miles; no hay leyenda "mostrando 100 de N". Ninguna pantalla revisada depende de recibir el catálogo completo del proveedor
  (`NewProductModal` precheck de referencia duplicada: probé 185 referencias reales, incluidas 40 con símbolos y 25 de <=3
  caracteres; 184/185 devuelven la exacta primero; la excepción es `10147B-WW` + 3 NBSP finales, dato sucio de la referencia).

### H6 — BAJO — `page` gigante devuelve filas inconsistentes
- `GET /api/compras/inventory?page=9223372036854775808` -> 200 con 20 filas (las de la página 1) y
  `meta.current_page=9223372036854775807`. `page=99999999999` -> 0 filas correcto. Antes (`array_slice`) devolvía vacío. Sin 5xx.

### H7 — BAJO — Orden por nombre difiere del `strcasecmp` anterior con mayúsculas acentuadas
- `sort_by=name` (asc/desc): el orden de la API coincide 100 % (0 posiciones distintas) con "minúsculas Unicode + bytes" de
  Postgres, pero difiere en 5 posiciones del `strcasecmp` de PHP anterior (603 nombres con mayúscula acentuada, ej. "APOLO PLAFÓN").
  Es el riesgo que anotó el Senior; el nuevo orden es arguablemente mejor. `reference` coincide en las dos semánticas.

### H8 — BAJO (preexistente) — Parámetros de query tipo array -> 500 `ErrorException`
- `GET /api/compras/inventory?search[]=x`, `?chip[]=x`, `/api/compras/products?search[]=x`,
  `/api/ventas-diseno/catalog-products?search[]=x`, `/api/bodega/inventory?search[]=x` -> 500 "Array to string conversion"
  (el `(string) $request->query(...)` es el mismo del código anterior). Solo request manipulada, no sale de la UI. El body del 500
  en dev incluye excepción y ruta (`APP_DEBUG` activo en dev; confirmar que test/prod no lo exponen — no verificado).

### H9 — BAJO — Cambios de comportamiento de búsqueda (por diseño, para que QA no los reporte como bug)
- Ventas&Diseño `catalog-products`: antes subcadena contigua de la frase completa por campo (sensible a acentos); ahora todas las
  palabras en cualquier orden sobre el texto concatenado, sin acentos. Términos de 1–2 letras: prefijo de palabra (antes
  "contiene"). La coincidencia exacta de **marca** ya no sube al tope (antes `isExactMatch` incluía `brand`; ahora solo
  referencia/ref. de fábrica/nombre).
- Observación de UI (no verificada como preexistente): el desplegable "Todas las categorías" del Catálogo muestra la clave cruda
  `newOrder.newProduct.categories.LAMPARAS`.

## Lo que sí funciona (evidencia)

### SCRUM-993
- **Paridad de chips contra SQL independiente** (mi propio SQL con subconsultas correlacionadas + reglas en Python sobre los 29.635
  productos): `todos` 29.630, `inactivos` 5 (6 tras crear el producto de prueba), `por_ingresar` 5, `en_atencion` 26.279,
  `bajo_stock` 2, `sin_stock` 26.280, `bajo_stock_sin_ordenar` 26.233 -> 0 faltantes, 0 sobrantes, 0 duplicados, `meta.total`
  correcto, recorriendo TODAS las páginas de cada chip (per_page=all, ~30 páginas). Repetido sobre 819a566 tras el redeploy: igual.
  Además, en cada una de las ~29.6K filas devueltas `por_servir`, `por_ingresar`, `en_camino`, `disponible` y `estado` coinciden
  (0 discrepancias). Cubre NULL en `stock_quantity`/`reorder_point`/`rotation` (mayoría del dato), `en_camino` con recepciones
  parciales/órdenes recibidas (56 productos con agregados > 0), 10 con rotación baja, 2 con bodega excluida.
- **Orden**: los 5 permitidos x asc/desc (10 combinaciones), recorriendo todas las páginas (29.630 filas únicas cada una, sin
  repetidos ni saltos): `reference`, `price_full`, `stock_quantity`, `created_at` coinciden exactamente con el orden esperado
  (NULL primero en asc, último en desc, desempate por id). `name`: ver H7. `sort_by` inválido/`sort_dir` inválido/inyección -> 200 con
  el default.
- **Paginación/parámetros**: `page=0/-3/abc` -> página 1; `per_page=0/1/1000/abc/-5` -> 20; `per_page=all` -> 1.000 filas/30
  páginas; 10/20/50/100 correctos; `chip` desconocido o con inyección -> `todos`. `meta.total/last_page` correctos con filtros.
- **`warehouse_ids[]`**: bodega 8 -> 3 productos, 13 -> 2, 8+13 -> 5 (unión), 6 -> 3.350 y 6+7 -> 9.668 (coinciden con SQL
  restringido a activos); combinada con chip (13 + sin_stock -> 1, 13 + bajo_stock -> 0) y con búsqueda. Vacío/negativo/0/no
  numérico = sin filtro; inexistente/enorme = 0 resultados; sin 5xx.
- **Combinación búsqueda + chip + bodegas + orden + paginación**: 4 recorridos completos (240, 285, 1.948 y 5.423 filas), 0 repetidos,
  `meta.total` = filas recorridas.
- **kpis del payload vs SQL independiente**: total 29.630, low 2, out 26.280, in_attention 26.279, total_value 1.712.851,06 — idénticos.
  Consumidores: `/api/compras/reports/inventory-attention` mismos números; Home de Compras `bajo_stock` 2 / `sin_stock` 26.280;
  Gerencia `bajo_stock_sin_ordenar` 26.233 (= cálculo independiente).
- **Shape** de respuesta: 40 campos por fila (incl. `por_servir`, `por_ingresar`, `en_camino`, `warehouses`, `estado`, `disponible`),
  `fuzzy/restricted/can_manage/kpis/data/meta` como antes; el diff de 41fbff2 no toca `format()`.
- **Permisos**: superadmin `restricted=false,can_manage=true`; `ventas_diseno.read` -> `restricted=true,can_manage=false` y las
  filas no traen `cost`; `compras.limited.view` -> `restricted=false,can_manage=false`; `crm.read` -> 403; `?mode=ventas` ->
  `restricted=true`.
- **Rendimiento**: `GET /api/compras/inventory` 74–500 ms y pico < 10 MB en proceso (0,3 MB paginado; 9 MB con per_page=all)
  vs. 134 MB+ y 500 antes. Por HTTP 0,3–0,6 s. Concurrencia: 3 rondas de 10 peticiones simultáneas mezcladas (chips, búsqueda,
  Nueva Orden, Home, fuzzy): 30/30 en 200 (latencia sube a 1–3 s por el servidor compartido, sin errores).
- **nginx access.log de dev**: 5xx totales = 2 a las 00:48:03 UTC (navegador de un usuario, 5 bytes = firma de agotamiento de
  memoria; anteriores al deploy de bd59a0d a las 00:52:58 UTC, es decir el bug original) y 5 mías provocados a propósito (H8, ver
  arriba). Ninguno de los tres endpoints migrados dio 5xx con entrada válida durante toda mi sesión. Sin "Allowed memory"/"fatal" en
  los logs del contenedor en las últimas 3 h.

### SCRUM-992
- **Acentos/mayúsculas**: `ILUMINACION`, `Iluminación`, `iluminacion` -> mismos 35 resultados en Inventario/Nueva Orden/Bodega;
  `lámpara`/`LAMPARA` -> 1.397 idénticos; `ÑANDÚ` = `nandu`.
- **Referencia exacta primero**: Nueva Orden `0001`, `001464`, `LAMP-COL-001` -> la exacta en posición 1; `ILUMINACION` deja
  primero los que tienen el nombre como prefijo. Ventas&Diseño igual.
- **Dos o más palabras en cualquier orden**: `lampara colgante` = `colgante lampara` (245 en Inventario/Nueva Orden/Bodega).
- **Términos cortos**: `a` (6.800), `ab` (80), `la`, `l`: prefijo de palabra, sin 5xx, sin traer el catálogo completo.
- **Caracteres especiales/inyección**: `%`, `_`, `\`, `'`, `"`, `; drop table x;--`, `' OR '1'='1`, emoji, japonés, cadena de 300
  caracteres, solo espacios -> 200; `%`/`_` se buscan literales (0 resultados, no "todo"); solo espacios = sin búsqueda; ningún efecto SQL.
- **Typos con respaldo**: `ilumnacion` -> 35 con `fuzzy=true`; `lamparra colgnte` -> 285 `fuzzy=true`; `zzzzqqqq`/`qq` -> 0 con
  `fuzzy=false`; `fuzzy` false siempre que la parcial da resultados. **En Ventas&Diseño (`catalog-products`) `ilumnacion`/`lamparra`
  -> 0 resultados y `fuzzy=false`** (sin respaldo, SCRUM-796).
- **Producto nuevo/editado sin pasos manuales** (producto de prueba PREQA-992-A): recién creado se encuentra por referencia
  (`PREQA-992-A`, `preqa 992`), por nombre con acentos (`ÑANDÚ` y `nandu`) en Nueva Orden, Inventario, Ventas&Diseño y Bodega;
  con `factory_reference` y `brand` NULL, `search_text` = `preqa-992-a  prueba pre-qa nandu iluminacion` (sin error). Tras editar
  nombre y marca: deja de encontrarse por el nombre viejo y se encuentra por el nuevo (`zzunicowordz`, `Renombrado`) al instante.
  Tras `is_active=false`: desaparece de todos los buscadores y solo aparece con el chip `inactivos`.
- **Topes**: Nueva Orden 100 (con y sin búsqueda); listado vacío de Ventas&Diseño 100; búsqueda de Ventas&Diseño 6.
- **Bodega**: búsqueda por nombre de proveedor (`Zona Libre` -> 16 = productos del proveedor 19 "Zona Libre de Colón"; `Zona` -> 19;
  no confundir con el proveedor 187 Lafayette).
- **Latencia (parcial)**: Ventas&Diseño `catalog-products` 29–37 ms en proceso (0,28–0,47 s por HTTP); Nueva Orden 36–50 ms;
  Inventario búsqueda parcial 74–96 ms.
- **Frontend (Playwright real en dev, sesión inyectada)**: Catálogo — 11 teclas en 574 ms = **1 request** (`search=lampara+col`);
  el input mantuvo el valor y el foco; vaciar el input restaura el listado sin nuevo request (cache de la query previa); 1 carácter
  no dispara request. Respuesta obsoleta: retrasé 3 s la respuesta de `search=la`, tipeé después `lampara colgante`: el resultado
  final fue el de `lampara colgante` (238) y la respuesta tardía de `la` no lo pisó. Nueva Orden (proveedor Lafayette) — 12 teclas =
  **1 request** (`provider_id=187&search=lampara+colg`), foco conservado, 1 carácter y vaciar sin requests. Inventario de Compras/
  Bodega usan `AutocompleteInput` (debounce 300 ms propio, descarta respuestas fuera de orden), no el hook nuevo.
- **vitest** (`npx vitest run`): useDebouncedValue (4), CatalogPage (19), QuotePartCard (38), components/compras (todos),
  InventarioPage (60) -> 14 archivos / 200 tests en verde.

## Escrituras hechas en dev
Únicamente el producto de prueba permitido: `catalog_products.reference = 'PREQA-992-A'` (creado y editado vía Eloquent en tinker:
`name` "Renombrado zzunicowordz", `brand` "MarcaPreQA", precio/costo 1) y **dejado `is_active = false`**; no se borró nada. Efecto
colateral esperado: las filas de `audit_logs` que genera el trait de auditoría del modelo por esos 3 cambios. Ninguna otra escritura
(todo lo demás fueron SELECT y GET). Tokens JWT temporales emitidos por tinker (no persisten).

## No verificado (y por qué)
- **UI en navegador real** de Inventario de Compras, Inventario de Bodega, Nueva Orden de Zona Libre y `QuotePartCard`: solo API +
  vitest (para Inventario/QuotePartCard); las pantallas de Catálogo y Nueva Orden de Compras sí en Playwright. Zona Libre y
  QuotePartCard requieren armar un proyecto/proveedor/flujo con escrituras.
- **Modo demo** (`visibleForDemoMode`) en el buscador de Ventas&Diseño: en dev el gate global `demo_mode_enabled=false` y ningún
  usuario lo tiene activo; activarlo exige escribir configuración. Solo revisión de código: el scope se compone con AND antes de
  `applySearch()` (sin `orWhere` sueltos), en ambas ramas.
- **Bordes finos con datos reales** (`disponible == reorder_point`, `en_camino` con múltiples órdenes por producto, rotación `baja`
  con disponible <= punto): el dato de dev casi no los tiene (2 con bodega excluida, 2 bajo_stock, 10 baja). Los cubre el test
  `InventoryKpiServiceSqlParityTest` del Senior; no lo re-ejecuté (no corrí `infra/test.sh`).
- `EXPLAIN`/uso del índice GIN, tiempo de creación del índice, migración con `DB_MIGRATE_USERNAME` en test/prod, sync de Lafayette
  post-columna generada, y criterio 8 de SCRUM-993 (30 min de uso real + alarma CloudWatch): no medidos.
- Fase 2 (Servicios, Ajustes, Cotizaciones, Ordenes) y tokens de otros roles en los buscadores de Bodega/Ventas&Diseño.
- Excel/mockup de los REQ: no aplica (tickets técnicos de rendimiento, sin filas de Excel asociadas); tampoco hay permisos angostos
  ni umbrales de negocio nuevos (los parámetros de búsqueda `0.5`/100/6/2 caracteres son técnicos y están como constantes).

## Recomendaciones para QA (marly.rangel)
1. Inventario de Compras en dev.atlanticerp.ai: los 7 chips, cambiar orden por cada columna en ambos sentidos y paginar; anotar que "Por
   ingresar" tiene 5, "Bajo stock" 2, "Sin stock" ~26.280 (dato real de dev, casi todo Lafayette sin stock cargado).
2. Combinar búsqueda + chip + filtro de bodega (bodegas 8 y 13 son las que tienen pocos productos) y verificar que el total del
   encabezado coincide con las filas al recorrer páginas.
3. Buscar con y sin acentos y mayúsculas ("ILUMINACION", "Iluminación", "lámpara"), dos palabras invertidas, un typo ("ilumnacion")
   en Inventario/Nueva Orden (debe avisar "aproximado") y en el buscador de la cotización (NO debe ser tolerante a typos).
4. Nueva Orden con Lafayette: comprobar que 100 resultados máximos no estorban al flujo; crear un producto nuevo y buscarlo al instante.
5. Catálogo de Ventas&Diseño: esperar 3–4 s por búsqueda hasta que se resuelva H1; no reportar la lentitud como bug nuevo.
6. Los textos crudos de i18n (`newOrder.newProduct.categories.LAMPARAS` en el filtro de categorías del Catálogo) y el producto
   `PREQA-992-A` (inactivo) son artefactos conocidos.
7. Sugerido para promover a e2e permanente (decisión de PM): ráfaga de teclas = 1 request en Catálogo y Nueva Orden; paridad de chips.
