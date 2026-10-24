# SCRUM-990 — Spec UX/UI: Zona Libre de solo consulta (Bodega > Bodegas)

Rol: UX/UI. Insumos: diseño del Arquitecto (sección 3), `BodegasPage.tsx`, `i18n/locales/{es,en}/bodega.json`, `components/ui/{Card,Button,Pagination}`, `components/icons/`. Sin mockup en el ticket: esta spec es la referencia de Visual Review. No cambia datos ni API (contrato: `GET /bodega/zona-libre/productos`, `solo_consulta` en `index()`, `show()` con KPIs).

## 1. Problema hoy
`BodegasPage` muestra Zona Libre como una pestaña más con la tabla de 15 columnas (Por servir, En camino, Stock mínimo, Acción...), inventada para bodegas físicas; con Lafayette (~25.800 filas, stock ajeno) parece inventario propio e infla totales. Se identifica solo por `modo_detalle === 'pendiente'` (inferencia frágil).

## 2. Pestaña/tarjeta en Vista Bodegas
- Sigue en la fila de pestañas (`Button variant="outline"` con `active`, mismas clases `!text-xs !px-3 !py-1.5`), **al final** de la lista y separada por un divisor vertical (`border-l border-slate-200 pl-2 ml-1`) para que no parezca una bodega operativa más.
- Dentro de la pestaña, tras el nombre, una insignia pequeña `IcoEye` (12px) + "Solo consulta" (`text-[10px] px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium`). Nunca un color de alerta ni lima/teal: es neutro.
- KPIs (mismo `KpiCard`, solo estos 3): **Productos de Lafayette** (`total_products`, color `#94a3b8` gris, no teal/lima para no igualarlos a los KPI de Atlantic), **Disponible (Lafayette)** = `total_units`, **Última sincronización** (`last_synced_at`, `formatDateFromTimestamp` + hora). **NO se muestran**: Responsable, % de capacidad, "unidades en total" como suma de inventario.
- Ningún total, contador o badge global de la pantalla suma Zona Libre (criterio también de backend).
- Botones del encabezado: con Zona Libre activa se ocultan "Administrar ubicaciones" y "Solicitudes de reubicación" (no existen para esta pestaña). Las demás pestañas no cambian.

## 3. Detalle de Zona Libre (`ZonaLibreCatalogoTable`, reemplaza la tabla de 15 columnas y los chips Todos/Espacio libre, que tampoco se muestran)
Orden vertical: aviso permanente > KPIs > barra de filtros > tabla > paginación > pie de ayuda.

**Aviso permanente** (no descartable, `role="note"`, arriba de los KPIs): `div rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 flex gap-3` con `IcoLock` (14) + título "Solo consulta" (`text-sm font-semibold text-slate-800`) + texto (`text-[12px] text-slate-600`). Contraste >= 4.5:1 en ambos modos (clases slate ya remapeadas en `html.dark`).

**Filtros**: `AutocompleteInput` no se reutiliza (sus sugerencias consultan stock de bodega); usar `<input type="search">` con `IcoSearch`, debounce 400 ms, mínimo 2 caracteres, Enter aplica de inmediato, mismas clases `px-3 py-2 border border-slate-300 rounded-lg text-sm`. Select de categoría (lista desde `meta`/endpoint de categorías distintas; si no hay, se omite). Toggle `Toggle` "Solo con disponibilidad" (apagado por defecto: **los de stock 0 se ven**). Botón "Limpiar filtros" (`Button variant="outline"`). Todo cambio vuelve a `page=1`.

**Tabla** (`Card variant="panel" overflow-hidden overflow-x-auto`, `table w-full text-sm`, `Th` existente):
| Columna | Contenido | Orden |
|---|---|---|
| Imagen | miniatura 40x40 `rounded-md object-cover`, `loading="lazy"`, `alt=""` (decorativa); sin URL o error de carga: cuadro `bg-slate-100` con `IcoImage` | no |
| Ref. Lafayette | `lafayette_id` | sí |
| Referencia | `referencia` (`font-semibold`), "—" si null | sí (defecto, asc) |
| Descripción | `descripcion` (máx. 2 líneas, `line-clamp-2`, `title` con el texto completo) | sí |
| Marca | `marca` o "—" | sí |
| Categoría | `categoria` o "—" | sí |
| Disponible (Lafayette) | entero alineado a la derecha; 0 se muestra "0" en `text-slate-400` (no rojo, no badge "Sin stock") | sí |
| Precio Lafayette | `precio` (precio_5) con formato moneda existente. **Solo con permiso** (`meta.puede_ver_precio`: compras.read o superadmin.all): sin permiso la columna, su encabezado, el orden por precio y el precio de la tarjeta móvil NO se muestran (7 columnas) y nunca se envía `sort=precio`. Sin etiqueta de tipo de precio | sí, con permiso |
Encabezados ordenables: `<button>` dentro de `th` con `aria-sort` y flecha `IcoChevronDown` (girada en asc). Filas sin hover de acción ni cursor pointer (no son clicables; no hay detalle por producto).
**Paginación**: `Pagination` existente, servidor, `perPage` 25/50/100 (sin opción "Todas": ~25.800 filas nunca se cargan). Texto de conteo: "{{from}}–{{to}} de {{total}}".

**Pie de ayuda** (`text-[12px] text-slate-500`, bajo la paginación): "¿Necesita usar un producto en AtlanticERP? Créelo manualmente como producto de AtlanticERP desde Compras; no se copia desde esta lista."

## 4. Acciones que NO existen
Sin Reubicar, Ver detalle, editar, mover, ajustar, contar, reservar, exportar inventario, ni checkboxes/selección, ni columna "Acción". Sin botones deshabilitados ni tooltips "no disponible": la ausencia se explica solo con el aviso permanente y el pie de ayuda. Sin menú contextual ni doble clic. Exportar/imprimir tampoco aparece (criterio de Luis: no es inventario de Atlantic).

## 5. Estados
- **Cargando** (primera carga): 8 filas esqueleto `animate-pulse bg-slate-100 rounded` dentro de la tabla, KPIs con "—"; `aria-busy="true"` en la tabla. Cambio de página/filtro: se mantiene la tabla previa con `opacity-60` (sin saltos de layout).
- **Vacío por búsqueda/filtros**: fila `colSpan=8` centrada "No hay productos de Lafayette que coincidan con la búsqueda." + enlace "Limpiar filtros".
- **Catálogo sin sincronizar** (`total_products = 0` sin filtros, o `last_synced_at` null): panel centrado `IcoClock` + "El catálogo de Lafayette aún no se ha sincronizado." + "Los productos aparecerán después de la primera sincronización automática." KPIs y filtros ocultos; el aviso permanente se mantiene.
- **Error**: panel con `IcoAlertTriangle` (`text-amber-600`), "No se pudo cargar el catálogo de Lafayette." + `Button variant="outline"` "Reintentar" (refetch); `role="alert"`. La falla no afecta a las otras pestañas.
- **Fuera de la fuente**: ocultos por defecto, sin interruptor para verlos (decisión de alcance mínimo); la tabla no muestra filas "fantasma".
- **Sincronización antigua**: decisión de Luis (2026-10-01) — NO se implementa un aviso por antigüedad. La pantalla solo muestra la fecha de la última sincronización. Un aviso exigiría un umbral parametrizable en `system_settings` (regla del workspace) y en dev/test la sincronización es semanal, así que alertaría siempre. Mejora futura registrada.

## 6. Otros selectores (destino de recepción, devolución, conteo, reubicación, alta de producto, ajustes)
Criterio único: todo selector de bodega donde se escribe stock lista solo bodegas `solo_consulta = false` (la API ya las filtra con `operativas()`); Zona Libre no aparece ni deshabilitada. Sin cambios de layout. Excepción: Vista Bodegas (pestañas) y reportes de lectura pueden listarla, siempre con la insignia "Solo consulta". `RelocateModal` no se abre desde ella.

## 7. Responsive, tema, accesibilidad
- Desktop >= 1024: tabla completa. Tablet 768–1023: oculta columnas Ref. Lafayette y Marca (`hidden lg:table-cell`), sigue con scroll-x como respaldo. Móvil < 768: filas como tarjetas (`md:hidden`): imagen + referencia/descripción (2 líneas) y debajo "Disponible" y "Precio" en dos columnas; filtros apilados a ancho completo; KPIs 1 columna (`auto-fit minmax(160px,1fr)` existente); pestañas con scroll horizontal (`overflow-x-auto flex-nowrap`).
- Modo oscuro: usar solo clases slate ya remapeadas en `index.css` (`html.dark`); prohibidos hex nuevos salvo los `#5BA5A0/#9fc54d/#94a3b8` de `KpiCard`. Verificar aviso y skeleton en ambos modos.
- A11y: `<table>` con `<caption class="sr-only">` "Catálogo de productos de Lafayette (solo consulta)"; input de búsqueda con `<label class="sr-only">` y `aria-describedby` al aviso; foco visible (`focus-visible:ring-2 ring-[#5BA5A0]`) en ordenación, pestañas, toggle y paginación; orden de tab: pestañas > búsqueda > categoría > toggle > limpiar > encabezados > paginación; anunciar resultados con `aria-live="polite"` ("{{total}} productos"); la insignia "Solo consulta" lleva el texto, no solo el icono.

## 8. Textos (namespace `bodega`, clave `warehouses.zonaLibre.*`; sin glyphs embebidos)
| Clave | es | en |
|---|---|---|
| badge | Solo consulta | View only |
| noticeTitle | Solo consulta | View only |
| noticeBody | Datos de Lafayette Lighting, no forman parte del inventario de Atlantic. | Lafayette Lighting data; not part of Atlantic inventory. |
| kpis.products / kpis.available / kpis.lastSync | Productos de Lafayette / Disponible (Lafayette) / Última sincronización | Lafayette products / Available (Lafayette) / Last sync |
| filters.search | Buscar por referencia, descripción o marca... | Search by reference, description or brand... |
| filters.withStock | Solo con disponibilidad | Only with availability |
| table.image / lafayetteId / reference / description / brand / category / available / price | Imagen / Ref. Lafayette / Referencia / Descripción / Marca / Categoría / Disponible (Lafayette) / Precio Lafayette | Image / Lafayette ref. / Reference / Description / Brand / Category / Available (Lafayette) / Lafayette price |
| table.caption | Catálogo de productos de Lafayette (solo consulta) | Lafayette product catalog (view only) |
| range | {{from}}–{{to}} de {{total}} | {{from}}–{{to}} of {{total}} |
| empty | No hay productos de Lafayette que coincidan con la búsqueda. | No Lafayette products match your search. |
| notSynced / notSyncedHint | El catálogo de Lafayette aún no se ha sincronizado. / Los productos aparecerán después de la primera sincronización automática. | The Lafayette catalog has not been synced yet. / Products will appear after the first automatic sync. |
| error / retry | No se pudo cargar el catálogo de Lafayette. / Reintentar | Could not load the Lafayette catalog. / Retry |
| help | ¿Necesita usar un producto en AtlanticERP? Créelo manualmente como producto de AtlanticERP desde Compras; no se copia desde esta lista. | Need a product in AtlanticERP? Create it manually as a AtlanticERP product from Purchasing; it is not copied from this list. |
Reutilizar `warehouses.filters.clear` y `common:buscador.sinResultados`.

## 9. Reutilizar / iconos
Reutilizar: `Card`, `Button`, `Toggle`, `Pagination`, `KpiCard`/`Th` (extraer a archivo compartido si hace falta), `formatDateFromTimestamp`. Iconos existentes en `components/icons/`: `IcoLock`, `IcoEye`, `IcoSearch`, `IcoImage`, `IcoClock`, `IcoAlertTriangle`, `IcoChevronDown`. **No hace falta ningún icono nuevo ni librería.**

## 10. Criterios de aceptación visuales (Playwright, Visual Reviewer)
1. Vista Bodegas: pestaña "Zona Libre" visible al final, con insignia "Solo consulta"; las demás pestañas sin insignia y con la tabla de 15 columnas intacta.
2. Al abrir Zona Libre: aviso permanente "Solo consulta" + texto "no forman parte del inventario de Atlantic" visible sin scroll; 3 KPIs (sin Responsable ni % capacidad); fecha de última sincronización.
3. Tabla con las 8 columnas exactas con permiso de precio (7 sin `meta.puede_ver_precio`, sin encabezado Precio ni su orden); 0 botones en `tbody`; no existe columna/encabezado "Acción", ni texto "Reubicar", "Ver detalle", "Ajustar", "Contar"; tampoco los chips Todos/Espacio libre ni los botones de ubicaciones/solicitudes de reubicación.
4. Paginación del servidor: primera página <= 25 filas `tbody tr`; cambiar página pide otra con `page=2`; no hay opción "Todas"; ninguna respuesta trae >100 filas.
5. Búsqueda (con y sin acentos) filtra y vuelve a página 1; sin resultados muestra el texto de vacío y "Limpiar filtros" restablece.
6. Al menos una fila con "0" en Disponible visible sin activar ningún filtro; con "Solo con disponibilidad" desaparecen.
7. Ordenar por Disponible/Precio alterna `aria-sort` ascending/descending.
8. Precio mostrado solo bajo el encabezado "Precio Lafayette" y solo con permiso; ningún otro rótulo de tipo de precio; sin permiso no hay columna ni orden por precio.
9. Estados: interceptar la API para forzar cargando (esqueleto), 500 (mensaje + "Reintentar" que recupera), `total_products=0` (panel "aún no se ha sincronizado").
10. Totales de Atlantic (KPIs de otras pestañas, Compras Inventario) no cambian con Zona Libre cargada; Zona Libre ausente en selectores de recepción/conteo/devolución/reubicación.
11. Responsive 1280/768/390 px sin scroll horizontal de página; en 390 px filas como tarjetas. Captura en modo claro y oscuro sin texto ilegible.
12. Teclado: Tab recorre pestañas, búsqueda, toggle y encabezados con foco visible; Enter ordena; aviso con `role="note"`; error con `role="alert"`.

## Fuera de alcance
Rediseño de otras pestañas o pantallas de selección, detalle por producto, precarga/alta desde Lafayette, exportaciones, cambios de marca o tokens.
