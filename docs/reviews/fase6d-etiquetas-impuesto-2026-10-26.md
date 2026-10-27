# Senior Review — Fase 6d: etiquetas visibles del impuesto por país (2026-10-26)

**Veredicto**: aprobada, 0 bloqueantes. Spec: `docs/specs/fase6-fiscal-por-pais.md` §10.

## Verificado
- Panamá sin cambio: el valor por omisión (`ITBMS`) reproduce cada texto y PDF previos; suites AdminCont/Servicios/VentasDiseno y frontend completo verdes.
- Solo texto: ninguna migración, columna, ruta ni permiso renombrados; el único campo nuevo de API es `tax_label`.
- `{{tax}}` se resuelve en cada traducción (getter), no al cargar: cambia con la sesión sin recargar.

## Sugerencias (no bloqueantes)
1. La tasa del 7 % mostrada en 2 textos y en la vista previa de recepción de mercancía es de Panamá (Fase 7: tasas por país).
2. Mensajes del PAC panameño conservan «ITBMS» a propósito.
3. Un PDF generado fuera de una petición con tenant (comando) usa el país por omisión (PA).
