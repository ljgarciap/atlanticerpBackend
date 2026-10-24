# UX — SCRUM-984 Menú "Manuales de Usuario"

Estado: spec de diseño (UX/UI) para Frontend Dev / Visual Reviewer. Sin mockup en Jira: esta spec ES la referencia visual.
Alcance: solo frontend visual/interacción. Contrato API (listado, presign de subida, URL renovable, borrado) lo define el Architect/Backend; aquí se asumen los campos listados en 2.3.

## 1. Problema
Los usuarios no tienen un lugar único para consultar los manuales (PDF/video) por módulo. Se agrega una entrada de menú, una página de consulta y un panel de administración para quien tiene `manuales.manage`.

## 2. Diseño

### 2.1 Sidebar
- Nueva sección inmediatamente después de "Soporte" (Sidebar.tsx, bloque SCRUM-923), mismo formato: `{ section: t('nav.manuales'), items: [{ id: 'manuales', label: t('nav.manuales'), href: '/manuales', icon: <IcoBookOpen /> }] }`. Sin gate (todo autenticado). Resaltado activo: lo resuelve `isActive()` existente (`/manuales`).
- Icono: `IcoBook` ya lo usa LUMO; para distinguir se crea `IcoBookOpen` (ver 6).
- Ruta `/manuales` en App.tsx, `ManualesPage` (lazy como SoportePage).

### 2.2 Layout de página (desktop >= 1024px)
Patrón de SoportePage/LogsPage: encabezado `h1 text-lg font-bold text-slate-900 dark:text-slate-100` + subtítulo `text-[12px] text-slate-500 dark:text-slate-400`, `mb-4`; barra de filtros en `Card variant="panel"`; contenido.

```
+--------------------------------------------------------------------+
| Manuales de usuario                        [ Administrar ] (*perm) |
| Guías en PDF y video para cada módulo de AtlanticERP.                    |
+--------------------------------------------------------------------+
| [Módulo: Todos v]  (Todos)(PDF)(Video)      [lupa Buscar por nombre]|
+--------------------------------------------------------------------+
| 12 manuales                                                        |
| +------------------+ +------------------+ +------------------+     |
| |[ic] Cómo crear.. | |[ic] Guía de ped..| |[ic] Conteo gene..|     |
| | Bodega    [PDF]  | | Compras  [Video] | | Bodega   [PDF]   |     |
| | 2.4 MB · 12 sep  | | 120 MB · 3 sep   | | 1 MB · 1 sep     |     |
| | [Ver] [Descargar]| | [Ver] [Descargar]| | [Ver] [Descargar]|     |
| +------------------+ +------------------+ +------------------+     |
+--------------------------------------------------------------------+
```
- Grid de tarjetas: `grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3` (tablet 2, móvil 1). Se elige tarjetas (no tabla) porque cada manual es independiente y tiene 2 acciones; el panel admin usa tabla/lista (2.6).
- Orden: fecha descendente. Sin paginación mientras haya <= 60 manuales; si el backend pagina, reutilizar `Pagination` de `components/ui/Pagination.tsx` con default 20.
- Barra de filtros: `Card variant="panel" className="p-3 mb-3 flex flex-wrap gap-2 items-center"`.
  - Módulo: `<select>` nativo con las clases de LogsPage (`rounded-lg border border-slate-200 dark:border-slate-600 px-2 py-2 text-sm bg-white dark:bg-slate-800 focus:outline-none focus:border-primary`), `aria-label` = label del filtro. Opciones: Todos, CRM, Ventas & Diseño, Compras, Bodega, Servicios, Administración & Contabilidad, Gerencia.
  - Tipo: grupo de 3 `Button` segmentado (`variant="outline"`, `active` + `activeVariant="primary"` para el seleccionado), `role="group"` + `aria-pressed` en cada uno. Un solo valor activo; default Todos.
  - Búsqueda: `input type="search"` con `IcoSearch` a la izquierda (`relative`, input `pl-8`), `flex-1 min-w-[180px]`, debounce 250 ms, filtra en cliente por nombre (case/acentos-insensible). Botón limpiar (`IcoClose`, `aria-label`) visible si hay texto.
  - Móvil: los controles se apilan (`flex-wrap`, cada uno `w-full` bajo sm).
- Filtros combinables (AND). Contador "N manuales" (`text-[12px] text-slate-500`, `aria-live="polite"`).

### 2.3 Anatomía de la tarjeta (`ManualCard`)
`Card variant="list-item" shadow` con `p-4 flex flex-col gap-3 h-full`; `accentColor` no se usa.
Campos consumidos: `id, name, module, type ('pdf'|'video'), size_bytes, created_at`.
1. Fila superior: recuadro de icono 36x36 `rounded-lg bg-primary-soft text-primary-dark dark:bg-slate-700 dark:text-primary-light` con `IcoFileText` (PDF) o `IcoVideo` (video); a la derecha, badge de tipo (`text-[11px] font-semibold rounded-full px-2 py-0.5`, PDF: `bg-slate-100 text-slate-700`, Video: `bg-primary-soft text-primary-dark`; dark: `bg-slate-700 text-slate-200`). El tipo nunca se comunica solo por color: lleva texto "PDF"/"Video" e icono.
2. Nombre: `h3 text-sm font-semibold text-slate-900 dark:text-slate-100`, `line-clamp-2`, `title` con nombre completo.
3. Módulo: texto `text-[12px] text-slate-500 dark:text-slate-400`.
4. Meta: "{tamaño} · {fecha}" `text-[12px] text-slate-500`. Tamaño: B/KB/MB/GB 1 decimal; fecha con `formatDateFromTimestamp` (`@/utils/dates`, ya usado en ShareLinkModal).
5. Acciones (al fondo, `mt-auto flex gap-2`): `Button variant="primary"` "Ver" (PDF) / "Reproducir" (video) con `IcoEye`/`IcoPlay`, `flex-1`; `Button variant="outline"` "Descargar" con `IcoDownload`. Con `loading` mientras se pide la URL firmada. Descargar = pide URL renovable con disposition attachment y dispara descarga (`<a download>`); Ver PDF = `window.open(url,'_blank','noopener')` (si el navegador bloquea el popup, mostrar toast de error y no perder el clic). Aria: `aria-label` "Ver {nombre}" / "Descargar {nombre}".
- Hover: `hover:border-primary/40`; focus de botones visible (focus ring existente de Tailwind: `focus-visible:ring-2 focus-visible:ring-primary`).

### 2.4 Modal de video (`ManualVideoModal`)
Reutilizar patrón de ShareLinkModal: overlay `fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[1100] flex items-start justify-center p-4 sm:p-6`, `Card variant="modal"` `w-full max-w-3xl`.
- `role="dialog" aria-modal="true" aria-labelledby` (título = nombre del manual). Header: título `text-base font-semibold` + `IcoClose` (`Button variant="icon"`, `aria-label` "Cerrar").
- Cuerpo: `<video controls preload="metadata" playsInline className="w-full rounded-lg bg-black aspect-video">` con `src` = URL firmada. Sin autoplay.
- URL renovable: si `<video>` dispara `error` (URL vencida), pedir una nueva una vez automáticamente, conservando `currentTime`; si vuelve a fallar, mostrar estado de error (abajo) con "Reintentar".
- Estados: cargando URL (skeleton `aspect-video animate-pulse bg-slate-200 dark:bg-slate-700` + texto "Preparando video..."); error (`IcoAlertTriangle` + mensaje + botón Reintentar + Descargar como alternativa).
- Teclado: Esc cierra; foco inicial al botón Cerrar; focus trap dentro del modal (Tab cicla); al cerrar, foco vuelve a la tarjeta/botón que lo abrió; al cerrar se pausa y se vacía `src`. Espacio/flechas operan el reproductor nativo.
- Móvil: `p-2`, video ancho completo, header sticky.

### 2.5 Estados de la página
| Estado | Presentación |
|---|---|
| Cargando | 6 tarjetas esqueleto (`Card list-item` con bloques `animate-pulse bg-slate-200 dark:bg-slate-700 rounded`), filtros deshabilitados; `aria-busy="true"` en el contenedor. |
| Vacío (filtro de módulo sin manuales) | Bloque centrado en `Card panel py-10`: `IcoBookOpen` 28px `text-slate-300`, texto "No hay manuales disponibles para este módulo". Si además hay búsqueda/tipo activos y 0 resultados: "Ningún manual coincide con tu búsqueda" + `ghost` "Limpiar filtros". Si el catálogo completo está vacío (Todos, sin búsqueda): "Aún no hay manuales publicados." (admins ven además botón "Subir manual"). |
| Error de carga | `Card panel` centrado: `IcoAlertTriangle` + "No se pudieron cargar los manuales." + `Button outline` "Reintentar" (mismo patrón que SoportePage `unavailable`). |
| Error de acción (URL/descarga) | Toast (Toaster existente) "No se pudo abrir el manual. Intenta de nuevo." |
| Sin permiso admin | El botón "Administrar" y el panel NO se renderizan (no hay versión deshabilitada). Acceso directo no aplica (es panel en la misma página). El backend igual responde 403. |
| Poblado | Grid 2.2. |

### 2.6 Panel "Administrar" (solo `manuales.manage`)
- Botón `Button variant="outline"` "Administrar" (icono `IcoSettings`) en el encabezado, alinea a la derecha (`flex justify-between items-start`; en móvil baja bajo el título). Alterna un panel `Card variant="panel" p-4 mb-3` colapsable sobre los filtros (`aria-expanded`, `aria-controls`). Estado abierto se recuerda solo en sesión (no localStorage obligatorio).
- Contenido del panel, 2 zonas:
  1. **Subir manual**: formulario en `grid gap-3 md:grid-cols-2`:
     - Zona de archivo: área `border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg p-4 text-center` con `IcoUpload`, texto "Arrastra un archivo o selecciona uno" + `Button outline` "Seleccionar archivo" (`<input type="file" accept=".pdf,video/mp4,video/webm">` oculto, asociado a label; toda el área es foco/teclado: Enter/Espacio abre el selector). Drag-over: `border-primary bg-primary-soft dark:bg-slate-700`.
     - Helper dinámico bajo la zona: "PDF hasta {maxPdf} MB. Video MP4 o WebM hasta {maxVideo} MB." Los valores vienen de la configuración (endpoint/ajuste paramétrico), NUNCA hardcodeados; mientras cargan, el helper muestra esqueleto.
     - Módulo: `<select>` requerido (sin la opción "Todos"; placeholder "Selecciona un módulo").
     - Nombre visible: `<input>` requerido, máx. 120 caracteres, prellenado con el nombre del archivo sin extensión (editable), contador no necesario.
     - Validación cliente inmediata al elegir archivo: extensión/MIME y tamaño contra los límites; error inline bajo la zona (`text-[12px] text-red-600 dark:text-red-400`, `role="alert"`), el botón Subir queda deshabilitado.
     - Botón `Button primary` "Subir manual" (deshabilitado hasta archivo válido + módulo + nombre).
  2. **Manuales publicados**: lista/tabla compacta (`Card list-item` por fila, o tabla en >= md): nombre, módulo, tipo, tamaño, fecha, botón `Button danger-text` con `IcoTrash` "Eliminar" (`aria-label` "Eliminar {nombre}"). En móvil cada fila es una mini-tarjeta apilada.

#### Flujo de subida paso a paso
1. Admin abre Administrar. 2. Elige/arrastra archivo; se valida tipo y tamaño (límites dinámicos). Si es inválido: error inline, no se avanza. 3. Completa módulo y nombre. 4. Pulsa "Subir manual": el formulario pasa a estado "subiendo" (campos deshabilitados); se pide la URL prefirmada al backend ("Preparando subida..."). 5. Subida directa a S3 (PUT, XHR para progreso): barra `role="progressbar" aria-valuenow/min/max` (patrón de ReplacementRequestsPage:254), `h-2 rounded-full bg-slate-200 dark:bg-slate-700` con relleno `bg-primary`, texto "{pct}% · {subido} de {total}" y botón `outline` "Cancelar". 6. Al terminar, se confirma/registra el manual en el backend ("Finalizando..."). 7. Éxito: toast "Manual subido", el formulario se limpia, el listado se invalida y refresca (TanStack `invalidateQueries`, nunca setQueryData), el foco va al nombre del nuevo manual o al formulario.
- Cancelación: aborta el XHR; vuelve al formulario con los datos conservados y mensaje "Subida cancelada". No queda registro en la lista.
- Error (red, URL vencida, 403 de S3, fallo al registrar): bloque de error en el panel con `IcoAlertTriangle`, mensaje específico y botones "Reintentar" (repite desde el paso 4 pidiendo nueva URL firmada, conserva archivo y campos) y "Descartar". 
- Protección: si hay subida en curso y el usuario navega/cierra, `beforeunload` advierte; el botón Administrar no colapsa el panel durante la subida.
- Borrado: clic en Eliminar abre modal de confirmación (patrón `ExitWithoutSavingModal`/modal Card pequeño `max-w-sm`, `role="alertdialog"`): título "Eliminar manual", cuerpo "Se eliminará «{nombre}» de forma permanente. Esta acción no se puede deshacer.", botones `outline` "Cancelar" (foco inicial) y `danger` "Eliminar" (`loading` durante la petición). Éxito: toast "Manual eliminado" + refresco. Error: toast "No se pudo eliminar el manual."

## 3. Textos exactos (namespace nuevo `manuales`, archivos `i18n/locales/{es,en}/manuales.json`; registrar en i18n/index.ts; más `nav.manuales` en common.json)
| Clave | es | en |
|---|---|---|
| nav.manuales (common) | Manuales de usuario | User manuals |
| title | Manuales de usuario | User manuals |
| subtitle | Guías en PDF y video para cada módulo de AtlanticERP. | PDF and video guides for each AtlanticERP module. |
| filters.module | Módulo | Module |
| filters.moduleAll | Todos los módulos | All modules |
| filters.type | Tipo | Type |
| filters.typeAll / typePdf / typeVideo | Todos / PDF / Video | All / PDF / Video |
| filters.search | Buscar por nombre | Search by name |
| filters.clearSearch | Limpiar búsqueda | Clear search |
| filters.clear | Limpiar filtros | Clear filters |
| modules.crm / ventasDiseno / compras / bodega / servicios / adminContab / gerencia | CRM / Ventas & Diseño / Compras / Bodega / Servicios / Administración & Contabilidad / Gerencia | CRM / Sales & Design / Purchasing / Warehouse / Services / Administration & Accounting / Management |
| count_one / count_other | {{count}} manual / {{count}} manuales | {{count}} manual / {{count}} manuals |
| type.pdf / type.video | PDF / Video | PDF / Video |
| card.view | Ver | View |
| card.play | Reproducir | Play |
| card.download | Descargar | Download |
| card.viewAria | Ver {{name}} | View {{name}} |
| card.downloadAria | Descargar {{name}} | Download {{name}} |
| empty.module | No hay manuales disponibles para este módulo | No manuals available for this module |
| empty.search | Ningún manual coincide con tu búsqueda | No manuals match your search |
| empty.catalog | Aún no hay manuales publicados. | No manuals published yet. |
| loading | Cargando manuales... | Loading manuals... |
| error.load | No se pudieron cargar los manuales. | Manuals could not be loaded. |
| error.open | No se pudo abrir el manual. Intenta de nuevo. | The manual could not be opened. Please try again. |
| retry | Reintentar | Retry |
| video.preparing | Preparando video... | Preparing video... |
| video.error | No se pudo reproducir el video. | The video could not be played. |
| video.close | Cerrar | Close |
| admin.button | Administrar | Manage |
| admin.upload | Subir manual | Upload manual |
| admin.selectFile | Seleccionar archivo | Select file |
| admin.dropHint | Arrastra un archivo o selecciona uno | Drag a file or select one |
| admin.limits | PDF hasta {{pdf}} MB. Video MP4 o WebM hasta {{video}} MB. | PDF up to {{pdf}} MB. MP4 or WebM video up to {{video}} MB. |
| admin.module / modulePlaceholder | Módulo / Selecciona un módulo | Module / Select a module |
| admin.name | Nombre visible | Display name |
| admin.errType | Formato no permitido. Usa PDF, MP4 o WebM. | Unsupported format. Use PDF, MP4 or WebM. |
| admin.errSizePdf | El PDF supera el límite de {{max}} MB. | The PDF exceeds the {{max}} MB limit. |
| admin.errSizeVideo | El video supera el límite de {{max}} MB. | The video exceeds the {{max}} MB limit. |
| admin.preparing | Preparando subida... | Preparing upload... |
| admin.uploading | Subiendo... {{pct}}% | Uploading... {{pct}}% |
| admin.finalizing | Finalizando... | Finishing... |
| admin.cancel | Cancelar | Cancel |
| admin.cancelled | Subida cancelada. | Upload cancelled. |
| admin.uploadError | No se pudo subir el archivo. Puedes reintentar. | The file could not be uploaded. You can retry. |
| admin.discard | Descartar | Discard |
| admin.uploaded | Manual subido | Manual uploaded |
| admin.published | Manuales publicados | Published manuals |
| admin.delete | Eliminar | Delete |
| admin.deleteAria | Eliminar {{name}} | Delete {{name}} |
| admin.deleteTitle | Eliminar manual | Delete manual |
| admin.deleteBody | Se eliminará «{{name}}» de forma permanente. Esta acción no se puede deshacer. | "{{name}}" will be permanently deleted. This action cannot be undone. |
| admin.deleted | Manual eliminado | Manual deleted |
| admin.deleteError | No se pudo eliminar el manual. | The manual could not be deleted. |
Ningún string lleva glyph/emoji; las comillas «» son tipográficas, no iconos.

## 4. Tokens y componentes a reutilizar
- Componentes: `Card` (panel, list-item, modal), `Button` (primary/outline/danger/danger-text/ghost/icon, `loading`, `active`), `Pagination` (solo si hay paginación backend), `Toaster`/`toastStore`, patrón de modal de ShareLinkModal, `formatDateFromTimestamp`, `cn`.
- Colores: `primary`, `primary-dark`, `primary-light`, `primary-soft`, `accent` (no se necesita acento lima salvo nada; no introducir), `slate-*` con variantes `dark:`; errores `red-600/red-400`. Sin hex sueltos (evitar el `#5BA5A0` literal que aparece en LogsPage; usar `focus:border-primary`).
- Tipografía/espaciado: títulos `text-lg font-bold`, subtítulo `text-[12px]`, gutters `gap-3`, paneles `p-3/p-4`, radios `rounded-lg`.
- Modo oscuro: toda clase de color con su par `dark:` (ya cubierto arriba). Toggle Sol/Luna del TopBar no requiere trabajo.

## 5. Accesibilidad
- Contraste AA en ambos temas (texto slate-500 sobre blanco/slate-800 verificar >= 4.5:1; `text-[12px]` secundario usar `slate-600` si falla en claro).
- Orden de foco: encabezado > Administrar > filtros (módulo, tipo, búsqueda) > tarjetas (Ver, Descargar). Landmark: contenido dentro del `main` del AppShell; grid como `ul/li` con `aria-label` del listado.
- Cambios de resultados anunciados vía contador `aria-live="polite"`. Errores inline `role="alert"`. Modales con dialog/alertdialog, trap, Esc, retorno de foco. Progreso con `progressbar`. Tamaño táctil de botones >= 40px en móvil.
- Respetar `prefers-reduced-motion`: el `animate-pulse` de esqueletos puede quedar; sin animaciones adicionales.

## 6. Iconos nuevos (en `components/icons/index.tsx`, mismo helper `base(size)`, Feather outline, stroke=currentColor, default size 18, `IconProps`)
- `IcoBookOpen` (Feather book-open): `<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>`
- `IcoVideo` (Feather video): `<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>`
- `IcoPlay` (Feather play): `<polygon points="5 3 19 12 5 21 5 3"/>`
- `IcoUpload` (Feather upload): `<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>`
- `IcoTrash` (Feather trash-2): `<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>`
Reutilizados sin cambio: `IcoFileText`, `IcoEye`, `IcoDownload`, `IcoSearch`, `IcoClose`, `IcoSettings`, `IcoAlertTriangle`, `IcoCheck`. Sin IA-iconografía; sin librería nueva.

## 7. Fuera de alcance
Reordenar/editar/reemplazar un manual existente (solo subir y borrar), categorías múltiples por manual, comentarios, versionado, vista previa embebida de PDF, miniaturas de video, paginación server-side si no la exige el backend, notificaciones de nuevos manuales, cambios al contrato API o a permisos (Architect/Backend).

## 8. Criterios de aceptación visuales (Playwright / Visual Reviewer)
Viewports: 1440x900, 768x1024, 375x812; ambos temas (clase `dark` en `html`).
1. Sidebar: ítem "Manuales de usuario" aparece justo después de "Soporte", con icono SVG (sin emoji), resaltado al estar en `/manuales`; visible con un usuario sin permisos especiales.
2. `/manuales` muestra h1 "Manuales de usuario", subtítulo, barra de filtros con select de módulo (8 opciones, "Todos" por defecto), 3 botones de tipo (Todos activo con `aria-pressed="true"`) y campo de búsqueda.
3. Grid: 3 columnas a 1440, 2 a 768, 1 a 375; sin scroll horizontal en ninguno.
4. Cada tarjeta muestra icono por tipo, nombre, módulo, badge tipo, tamaño y fecha, botones Ver/Reproducir y Descargar.
5. Filtrar por módulo sin resultados muestra exactamente "No hay manuales disponibles para este módulo"; búsqueda sin resultados muestra el mensaje de búsqueda y "Limpiar filtros" restablece.
6. Filtros módulo + tipo + búsqueda se combinan; el contador actualiza (`aria-live`).
7. Ver en un PDF abre pestaña nueva (evento `popup`); Descargar dispara descarga.
8. Reproducir abre `role="dialog"` con `<video controls>`; Esc cierra; foco inicial en Cerrar; Tab no sale del modal; al cerrar el foco vuelve al botón origen; video pausado.
9. Usuario sin `manuales.manage`: no existe el botón "Administrar" ni el panel en el DOM. Con permiso: existe y despliega el panel (`aria-expanded`).
10. Panel admin muestra el helper con los límites tomados de la configuración (mockear distinto valor y verificar que el texto cambia; no hardcodeado).
11. Archivo no permitido o sobre el límite: error inline `role="alert"` y botón "Subir manual" deshabilitado.
12. Subida: aparece `role="progressbar"` con `aria-valuenow` creciente y botón "Cancelar"; al cancelar vuelve el formulario con datos conservados; al fallar aparece "Reintentar" que reanuda con el mismo archivo; al éxito toast "Manual subido" y la tarjeta aparece en el listado.
13. Eliminar abre `role="alertdialog"` con foco en "Cancelar" y el texto con el nombre; confirmar elimina y muestra toast; cancelar no cambia nada.
14. Estados cargando (esqueletos, `aria-busy`), error de carga (mensaje + Reintentar) verificados interceptando la red.
15. Modo oscuro: sin texto ilegible ni fondos blancos residuales; cambio es/en traduce todas las cadenas sin claves crudas visibles.
16. Regla de iconografía: ningún carácter emoji en el DOM de la página (regex de emoji sobre `innerText` = 0 coincidencias).
