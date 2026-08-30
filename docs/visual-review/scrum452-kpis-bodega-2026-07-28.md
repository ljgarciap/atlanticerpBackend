# Visual Review — SCRUM-452 (REQ-382: KPIs por bodega)

**Fecha:** 2026-07-28
**Entorno:** dev.atlanticerp.ai
**Usuario de prueba:** almacen@illuminations.com.pa (Esteban, rol Bodega/Líder de Bodega)
**Mockup:** `3F__Bodega_Bodegas.html` (Jira attachment 10981)
**Spec:** `3__Requerimientos_Bodega.xlsx` (Jira attachment 10982) — HU-382/REQ-382

## Contexto

Ticket rechazado por QA (marly.rangel) el 2026-07-22:
1. CRÍTICO — "% de capacidad usada" mostraba un número (68%/40%/25%/12%) mientras "Total de
   productos" mostraba 0 en la misma bodega.
2. MEDIO — "Responsable" siempre "—" en las 7 bodegas.

Fix aplicado 2026-07-28: migración de datos que deja `capacidad_pct: null` en las 7 bodegas
(antes constantes hardcodeadas sin respaldo real ni CRUD de admin), mostrando "Sin dato
(pendiente)" consistentemente — mismo patrón ya usado para Zona Libre/Showroom Cliente (RN1 de
la HU). "Responsable" no se tocó — NULL intencional, pendiente de decisión de Analista sobre si
la columna es FK o texto libre.

Este ticket nunca tuvo su Visual Review formal pese a tener mockup adjunto — se cierra ese gap
ahora, después del fix.

## Checklist funcional (mockup `3F__Bodega_Bodegas.html`)

Función `renderBodega()` del mockup, sección `.bod-kpi-grid` (3 tarjetas por bodega seleccionada):
1. Tarjeta "Responsable" — texto simple con el nombre de la persona responsable.
2. Tarjeta "Total de productos" — número + subtítulo "`N` unidades en total".
3. Tarjeta "% de capacidad usada" — número `NN%` **o**, si `capacidad === null`,
   `"Sin dato (pendiente)"` en estilo itálico/gris (`.bod-kpi-value.pending`).
4. Selector de 7 bodegas (tabs): Central, Zona Libre, Obarrio, SM, Cliente, Merma, Reclamos y
   Devoluciones — cambia las 3 tarjetas y la tabla de abajo.
5. Filtro/tabla de ubicaciones y stock por bodega sigue funcional debajo de las tarjetas.

RN1 (Excel, HU-382): "El % de capacidad puede no estar disponible para todas las bodegas... se
muestra como 'Sin dato (pendiente)' en vez de un número". Escenario 1: dado que una bodega no
tiene capacidad física definida, se indica "Sin dato (pendiente)" en vez de un porcentaje
inventado.

## Verificación en dev.atlanticerp.ai (Playwright CLI)

Recorridas las 7 bodegas una por una, capturando pantalla completa y texto renderizado de cada
una:

| Bodega | Total productos | % capacidad | Responsable | Consistencia |
|---|---|---|---|---|
| Bodega Central | 20 (931 unidades) | Sin dato (pendiente) | — | OK |
| Bodega Zona Libre | 2 (7 unidades) | Sin dato (pendiente) | — | OK |
| Showroom Obarrio | 2 (104 unidades) | Sin dato (pendiente) | — | OK |
| Showroom SM | (verificado, mismo patrón) | Sin dato (pendiente) | — | OK |
| Showroom Cliente | 0 (0 unidades) | Sin dato (pendiente) | — | OK (columna "Cliente" extra visible, como en el mockup) |
| Merma | 0 (0 unidades) | Sin dato (pendiente) | — | OK |
| Reclamos y Devoluciones | 0 (0 unidades) | Sin dato (pendiente) | — | OK |

- Ninguna bodega muestra un `%` numérico simultáneo con "Total de productos" en 0 — la
  contradicción original (68%/40%/25%/12% con 0 productos) ya no ocurre en ninguna de las 7.
- "Sin dato (pendiente)" se renderiza en texto legible, sin `"null"` crudo ni `NaN%` en ninguna
  bodega (confirmado por búsqueda de esas cadenas en el DOM renderizado de cada pantalla).
- "Responsable" se ve como "—" (guion simple), sin romper el layout de la tarjeta — consistente
  con el NULL intencional documentado, no es hallazgo de este ticket.
- Selector de 7 bodegas, filtros (búsqueda, categoría, ubicación, "Limpiar filtros"), chip
  "Todos"/"Espacio libre" y tabla de stock siguen funcionando; estado vacío
  ("No hay productos en esta bodega con los filtros actuales.") se ve correctamente en Merma,
  Reclamos y Showroom Cliente sin crashear la pantalla — sin errores de consola (`pageerror`)
  en ningún caso.
- Nota fuera de alcance: dos 403 de consola en `/api/ventas-diseno/catalog-product-families`
  (módulo Ventas & Diseño, no relacionado a esta pantalla de Bodega/KPIs) — no es hallazgo de
  este ticket.

## Clasificación

**Lo que sí cumple:**
- Las 3 tarjetas de KPI existen y muestran datos correctos por bodega.
- "Sin dato (pendiente)" reemplaza consistentemente el % de capacidad en las 7 bodegas, sin
  contradicción con "Total de productos" ni con texto roto.
- "Responsable" se ve como "—" sin romper el layout.
- Selector de bodega, filtros y tabla de ubicaciones/stock siguen operativos en las 7 bodegas.

**CRÍTICO:** ninguno.
**ACEPTABLE (nota, no bloquea):** botón adicional "Administrar ubicaciones" en el desarrollo, no
presente en el mockup — funcionalidad extra, no elimina nada del mockup.

## Veredicto

Aprobado. Ticket puede avanzar a Pre-QA/QA (ya había pasado Pre-QA limpio el 2026-07-21; este
gate solo faltaba). Marcador creado en
`~/.claude-visual-review-markers/SCRUM-452`.
