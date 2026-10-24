# Senior Review — SCRUM-1010 ampliación A+B (filtro por familia y guardia de /inventario)

Fecha: 2026-10-01. Commits: backend `3b6077b6`, frontend `fc9dbe9`. Solo lectura (no se corrió test.sh/deploy).

## Veredicto: APROBADO (0 blockers, 3 sugerencias no bloqueantes)

## 1. Backend — filtro `family_id`

- `parseFamilyId()`: entero positivo; ausente, vacío, no numérico o 0 = sin filtro. `?family_id[]=1` (array) da `null` en `is_numeric`, sin error. Correcto. Valor decimal "1.5" se castea a 1: inocuo.
- Subconsulta `cp.id IN (SELECT catalog_product_id FROM <pivot> WHERE family_id = ?)`. El nombre de tabla sale de `CatalogProductFamily::products()->getTable()`, que ya incluye el schema del tenant (`{tenant}_ventas_diseno.catalog_product_family_items`), igual que el resto del módulo. Valor ligado como parámetro, sin inyección.
- Rendimiento: el pivot tiene `unique(family_id, catalog_product_id)` (migración `2026_07_19_100004`), con `family_id` como columna líder, así que la subconsulta es un index scan acotado a las filas de la familia (decenas). Postgres lo resuelve como semi-join. Todo en SQL, sin `->get()`, antes de `paginate()` (mismo criterio de SCRUM-993). No añade carga por fila. Sin medición con 30K (no se corrió stack), pero el plan es trivialmente barato; recomendable que Pre-QA lo confirme con el catálogo de dev.
- Acceso: el filtro se aplica DESPUÉS de `resolveAccess()` (403 antes de tocar la query) y solo estrecha el conjunto; no cambia `restricted`/`can_manage`, ni el `format()` que oculta costos. No puede exponer datos fuera del acceso. UAT (Compras oculto, no superadmin) sigue cortando en `resolveAccess()`.
- Combinación con búsqueda, chip, `warehouse_ids` e `inactivos`: todos son `AND` sobre la misma query; correcto. KPIs siguen globales (`$this->kpis()`), consistente con el filtro de bodegas existente.
- Tests (6): filtro simple, producto compartido en 2 familias, id inexistente (vacío), valores inválidos ignorados (`''`, `abc`, `0`), combinación con bodega, 403 sin permiso. Prueban comportamiento real. Gap menor: no hay test explícito de modo restringido (`ventas_diseno.read`) con `family_id` ni de combinación con búsqueda de texto (el nombre del test lo promete, solo cubre bodega).

## 2. Frontend — select de familia

- `family_id: familyId !== '' ? Number(familyId) : undefined`; `setPage(1)` al cambiar; incluido en `hasActiveFilters` y en "Limpiar filtros". La búsqueda de texto no cambia (decisión de Luis). Correcto.
- Opciones desde `useInventoryFamilies(1, 'all')` (mismo queryKey que ya usa el detalle del producto, `staleTime` 5 min, se comparte caché). `per_page=all` está soportado (`Pagination::MAX_ALL`). `families()` solo devuelve familias activas: una familia inactiva no aparece en el select, razonable.
- i18n es/en con claves nuevas, sin glyphs; sin emojis; sin umbrales hardcodeados.
- Tests (3 nuevos): opciones y default sin `family_id`, selección manda `family_id` numérico + `page: 1`, combinación y "Limpiar". El tercero reconoce que no puede afirmar una llamada nueva por caché de React Query y verifica estado observable (select vuelve a '' y desaparece el botón); aceptable.
- Ajuste al test `SCRUM-237 — asignar familia...`: legítimo. Ahora hay `<option>` "Kit Baño" en el select nativo además de la opción del combobox; el test pasa a elegir el elemento que no es `OPTION`. No oculta ningún bug; el comportamiento verificado (guardar y confirmar con la familia) no cambia.

## 3. Guardia de `/inventario` (App.tsx)

- `RequirePermission` evalúa `superadmin.all || some(required)` sobre `user.permissions`, el mismo claim `permissions` del JWT que lee `resolveAccess()`. Conjunto: `compras.read`, `compras.limited.view`, `ventas_diseno.read` = exactamente las 3 ramas de `resolveAccess()`, más superadmin siempre. Sin diferencias en el caso base: no hay rol que el backend deje pasar y la guardia bloquee, ni al revés.
- Única divergencia (preexistente, no regresión): UAT. Con Compras oculto, el backend devuelve 403 a todo no-superadmin aunque tenga `compras.read` real, mientras el claim `permissions` no refleja la máscara UAT (`menuVisibility.__module__`). La guardia deja pasar en ese caso, igual que antes del commit; el sidebar ya oculta el link. No se empeora nada. Ver sugerencia S1.
- Deep-links: `?chip=en_atencion` (Reportes de Compras, exige `compras.read`), `?chip=bajo_stock|sin_stock` (Home de Compras), `?chip=por_ingresar` (Ingreso de Mercancía, `compras.read`), `?chip=bajo_stock_sin_ordenar` (Gerencia, rol management con `compras.read`), y el botón de `CatalogPage` (ventas_diseno) quedan dentro del conjunto permitido; sin cambio para quien ya entraba con datos. Los query params se conservan (no hay redirect para quien pasa). Quien antes entraba y solo veía un 403 ahora se redirige a su home: es el objetivo del cambio.
- Sidebar: ítem Inventario aparece con `modules.compras.view` o ventas_diseno; `effectivePermissionsV2()` traduce `view` a `compras.read`, así que visibilidad y guardia coinciden.

## Hallazgos

Blockers: ninguno.

Sugerencias (no bloqueantes):
- S1. La guardia no replica el corte UAT (ver sección 3). Si se quisiera paridad total, añadir un chequeo de `menuVisibility.compras.__module__ !== false` para no-superadmin; requiere decisión de producto (hoy el comportamiento es el mismo que antes). Dejarlo documentado.
- S2. No hay test unitario de la guardia (p. ej. un rol solo con `bodega.read` es redirigido; `compras.limited.view` y `ventas_diseno.read` pasan; superadmin pasa). Es una regla de acceso ya reportada por Pre-QA; conviene promoverla a un test (vitest sobre `App` o e2e) según la regla del proyecto de no perder gates.
- S3. Test de backend `test_family_id_se_combina_con_la_busqueda_y_con_el_filtro_de_bodega` no ejercita la búsqueda de texto pese al nombre; añadir `&search=` o renombrar. Y un caso de modo restringido (`ventas_diseno.read`) con `family_id`.
- Observación: `families()` (backend, preexistente) carga con eager todas las familias y sus productos y pagina en PHP; ahora se invoca al montar `ProductsTab` (antes solo al abrir un detalle). Con las familias actuales (decenas de productos) es irrelevante; vigilar si crece. Pre-QA debería medir `GET /compras/families?per_page=all` con el catálogo real.

Convenciones: commits en español con formato `tipo(scope)`, sin emojis, i18n sin glyphs, sin constantes de negocio hardcodeadas. PHPStan y vitest completos ya reportados OK por el solicitante; no re-ejecutados.
