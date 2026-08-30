Feature: Demo — ampliar catálogo de productos/familias para Cotizaciones, SCRUM-715 (item 9 de
SCRUM-711)
Date: 2026-07-29
Pre-QA: pasada real en navegador (Playwright headless contra `localhost:5173`, stack Docker local),
subagente aislado sin lectura previa de `CatalogProductSeeder.php`/`CatalogProductFamilySeeder.php`
ni del diff de implementación — probado como caja negra contra la app real, código de UI
(`QuotePartCard.tsx`/`CatalogPage.tsx`) solo consultado para ubicar selectores, no para sesgar el
resultado. Verificación de datos vía `tinker` (solo lectura) para confirmar magnitudes/precios
exactos al investigar hallazgos.
Usuario usado: `designer@atlantic.test` (Vendedor/Diseñador real, `ventas_diseno.write`) — a
propósito, no `management` (superadmin), para no enmascarar un problema de permisos.
Datos de prueba: 3 cotizaciones borrador en blanco (`Designer Demo`, sin cliente) creadas para
ejercitar el picker dentro de una cotización real — **borradas al final de la sesión** junto con
sus partidas/ítems, entorno queda limpio (confirmado con `GET /api/ventas-diseno/quotes` → `data: []`).

---

## Resumen

| Criterio de aceptación | Resultado |
|---|---|
| Catálogo de productos disponible (46, antes 10) | ✅ Limpio |
| Catálogo de familias disponible (10, antes 3) | ✅ Limpio |
| Búsqueda funcional (referencia/descripción, fuzzy, familias) | ✅ Limpio — 1 hallazgo MEDIO no bloqueante (ver abajo) |
| Selección e inserción correcta de ítems (costo/precio/referencia/descripción reales) | ✅ Limpio |

**0 CRÍTICO. 1 MEDIO no bloqueante (comportamiento pre-existente de REQ-036, fuera del alcance de
este ticket). Pasa a QA.**

---

## Verificación de datos (vía tinker, solo lectura)

```
Products total: 46        | Active: 46  | Inactive: 0
Families total: 10        | Inactive: 0
Min price: $4.50 (BOMB-LED-200)   | Max price: $890.00 (CAND-SAL-005)
Min cost:  $1.80                  | Max cost:  $410.00
Duplicate references: 0
Families: Riel Direccionable(8), Sala Elegante(6), Iluminación LED Básica(6),
Iluminación Exterior(5), Colgantes de Diseño(6), Empotrables & Downlights(6),
Baño & Espejo(3), Cocina & Bajo Alacena(3), Oficina & Comercial(3),
Decorativo Vintage/Industrial(3)  → suma 46 ✅
```
Ningún producto ni familia inactivo en el seed actual — el criterio "excluir inactivos de
listados/búsqueda" queda sin caso negativo real para ejercitar en este momento (no es un bug, solo
no hay dato con el que romperlo).

---

## Búsqueda por producto (`/ventas-diseno/catalog`, tab Buscar)

| Escenario de ruptura intentado | Resultado |
|---|---|
| Búsqueda vacía (string "") | ✅ No dispara fetch (guard `query.length > 0` en cliente), solo placeholder |
| Búsqueda solo espacios ("   ", 3 espacios) | ⚠️ MEDIO — ver hallazgo abajo |
| Referencia inexistente ("ZZZ-9999-NOPE") | ✅ "Sin resultados", sin crash |
| Sensibilidad a mayúsculas ("led" vs "LED") | ✅ Ambos devuelven 18 resultados idénticos |
| Caracteres especiales ("%$#@!") | ✅ "Sin resultados", sin excepción visible ni consola rota |
| Typo con tolerancia fuzzy ("Bombllo" → Bombillo, "Coglante" → Colgante) | ✅ Banner de aproximados se muestra, resultados correctos (BOMB-*, LAMP-COL-*/COLG-*) |
| Cost nunca viaja en la respuesta de búsqueda | ✅ Confirmado inspeccionando el JSON crudo de `GET /api/ventas-diseno/catalog-products` en 6 búsquedas distintas — ningún payload contiene la clave `cost` |

### ⚠️ MEDIO — búsqueda de solo espacios en blanco devuelve el catálogo completo sin aviso

Reproducir: en `/ventas-diseno/catalog` (o dentro del picker de una cotización), tipear 3 espacios
("   ") en el buscador. El cliente sí dispara el fetch (longitud > 0), pero el backend
aparentemente hace `trim()` del término antes de filtrar, lo que lo deja vacío y termina
matcheando "todo" — los 46 productos se listan sin paginación ni ningún indicador de que la
búsqueda no filtró nada. Contraste: una búsqueda realmente vacía (string "") no dispara fetch en
absoluto por un guard del lado del cliente, así que el comportamiento es inconsistente entre
"vacío" y "solo espacios" pese a que ambos deberían tratarse igual desde la perspectiva del
usuario.
**No bloqueante para SCRUM-715**: este comportamiento pertenece al buscador (`REQ-036`,
`CatalogProductPicker`/`CatalogPage`), que este ticket no tocó — solo amplió la data del seeder.
Con 10 productos el mismo bug ya existía y pasaba desapercibido por ser una lista corta; con 46
productos se vuelve más visible (toda la lista sin filtrar), pero no es una regresión introducida
por este ticket ni afecta ningún criterio de aceptación de SCRUM-715 (catálogo disponible,
selección/inserción con costo/precio reales). Recomendado abrir un ticket aparte para REQ-036 si
se decide corregirlo.

---

## Familias (`tab Familias`)

| Escenario de ruptura intentado | Resultado |
|---|---|
| Listado de 10 familias con conteo de productos | ✅ Conteos correctos, suman 46 |
| Entrar a familia #1 (Baño & Espejo, 3 productos), volver, entrar a familia #3 (Colgantes de Diseño, 6 productos) | ✅ Sin fuga de estado — los productos listados en cada familia son distintos y correctos, ningún residuo de la familia anterior |
| Familia grande: Riel Direccionable (8 productos, la más numerosa) | ✅ 8 productos listados correctamente, precios de $9.90 a $92 sin glitches de formato |
| Familia chica: Oficina & Comercial (3 productos) | ✅ 3 productos correctos ($48–$110) |
| Familia con 0 productos | N/A — no existe ninguna familia con 0 productos en el seed actual, no hay caso para romper |

---

## Selección e inserción de ítems en una cotización real (`QuotePartCard` → `CatalogProductPicker`)

| Escenario de ruptura intentado | Resultado |
|---|---|
| Insertar producto más barato del catálogo (BOMB-LED-200, $4.50/costo $1.80) | ✅ `unit_price=4.5`, `cost=1.8`, total línea `$4.50` — coincide exacto con el catálogo |
| Insertar producto más caro del catálogo (CAND-SAL-005, $890/costo $410) | ✅ `unit_price=890`, `cost=410`, total línea `$890.00` — coincide exacto; subtotal de partida con ambos ítems `$894.50` (4.50+890), suma correcta, sin errores de redondeo en ningún extremo de precio |
| Costo visible en la ficha de búsqueda/preview antes de insertar (NO debería, REQ-036 AC2) | ✅ Confirmado que `cost` nunca aparece en la ficha técnica de preview (ni en el JSON de red) — solo aparece en la fila del ítem ya insertado en la cotización, como corresponde |
| Insertar el MISMO producto (BOMB-LED-200) dos veces en la misma partida | ✅ Rechazado por el backend (422) con mensaje claro en español: **"Este producto ya está en la partida"** — el formulario permanece abierto, sin fila duplicada fantasma, sin crash |
| Alta en lote de familia completa ("Seleccionar productos" en tab Familias) — familia Baño & Espejo (3 productos) | ✅ Los 3 productos se insertaron con referencia/precio/costo reales (APLQ-BAN-501 $62/costo28, ESP-BAN-500 $54/costo24.5, TECHO-BAN-502 $36.50/costo16.5), subtotal de partida `$152.50` correcto |
| Doble click rápido en "+ Agregar" (doble envío) | ✅ El botón se deshabilita tras el primer click (React Query `isPending`) — exactamente 1 `POST /items` disparado, 1 solo ítem creado, sin duplicado |
| Recargar la página a mitad de flujo (producto seleccionado en el picker, ANTES de confirmar "+ Agregar") | ✅ Cero llamadas `POST /items` antes de la recarga (nada persiste hasta el confirm explícito), sin ítem huérfano tras recargar, sin crash — el draft es 100% client-side hasta el click final de confirmación |

---

## Lo que sí funciona

- 46 productos / 10 familias disponibles y consultables tanto desde `/ventas-diseno/catalog`
  (modo consulta) como desde el picker inline dentro de una cotización (`QuotePartCard`).
- Búsqueda por referencia/descripción funcional, insensible a mayúsculas, con tolerancia fuzzy a
  typos reales (probado con 2 typos distintos, ambos resueltos correctamente con banner de aviso).
- `cost` nunca se filtra en ningún JSON de búsqueda/listado de familias — verificado en 6+
  respuestas de red distintas, cumpliendo REQ-036 AC2 incluso con el catálogo 4.6× más grande.
- Inserción de ítems desde catálogo resuelve correctamente costo/precio/referencia/descripción
  server-side en ambos extremos del rango de precios ($4.50 y $890), sin problemas de formato o
  redondeo de `formatMoney`.
- Alta en lote por familia (REQ-037) funciona correctamente con la data nueva.
- Protecciones ya existentes (bloqueo de duplicado en la misma partida, deshabilitado de doble
  envío, ausencia de persistencia prematura que evita huérfanos en recarga) siguen funcionando
  intactas con el catálogo ampliado.
- 0 productos/familias duplicados o inactivos mal configurados en el seed nuevo de 46 productos.
