<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- Lote 4 (SCRUM-395, rebote 2026-08-16) — rediseño contra mockup: mismo patrón visual ya
         validado con el cliente en `estatus-pedido-pdf.blade.php` (SCRUM-411/REQ-341) — ícono +
         ATLANTIC + "Powered by AtlanticERP", título grande, datos generales en 2 columnas por
         posición absoluta, pie de página institucional. Agrega sección "Datos de entrega" (RN1:
         nunca precios, ya respetado abajo) para firma física al imprimir — no existía antes. --}}

    {{-- SCRUM-395 (rebote 2026-08-17→18, alcance acotado a impresión multi-página — RESTO del
         requerimiento sin tocar): el margin-top/bottom de @page reserva el espacio para el
         encabezado (`.doc-header`, position:fixed+top, se repite en cada página) y para el pie de
         página (dibujado con `page_text()`, ver `<script type="text/php">` al final del body — NO
         position:fixed+bottom, ese mecanismo es un bug conocido de dompdf que agrega páginas en
         blanco). Antes de este fix, el encabezado/pie de página eran contenido normal del flujo:
         solo aparecían una vez (el pie quedaba pegado después de la firma en vez de comportarse
         como footer real). --}}

    {{-- SCRUM-783 (rebote Gerencia 2026-08-20): `.meta-block` usaba position:absolute para las 2
         columnas + un min-height fijo adivinado (110px) — los hijos absolutos no aportan altura al
         contenedor, así que .meta-block quedaba SIEMPRE en 110px sin importar cuántas líneas
         tuviera cada columna. La columna izquierda (6 líneas: Cliente/Proyecto/Dirección/
         Contacto/Teléfono/Correo) desbordaba ese alto y se montaba sobre la tabla siguiente
         ("Correo" pisando el header "REF. PÚBLICA"). Fix: display:table/table-cell (mismo patrón
         ya probado en `ventas-diseno/quote-pdf.blade.php`, §6 de SCRUM-766) — el alto del bloque
         crece solo con la columna más alta, sin adivinar un número fijo. El offset visual original
         (columna izquierda arranca más abajo que la derecha, alineada bajo el título) se preserva
         con padding-top en vez de top absoluto. --}}
    {{-- SCRUM-887/SCRUM-893 — unificado con el modelo `servicios/service-quote-pdf.blade.php`:
         logo real vía imagen en vez del ícono de círculos CSS + "Powered by AtlanticERP", encabezado/pie
         reales con el MISMO alto/mecanismo que el modelo (antes tenía uno propio, más angosto, con
         texto de marca distinto). `.doc-header`/`.brand-row`/`.brand-cell`/`.brand-name`/
         `.brand-line` vienen de `pdf.partials.styles` — ver ese archivo. --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .doc-title { font-size: 20px; font-weight: bold; color: #5BA5A0; margin-top: 4px; }

    /* Bloque de datos generales — izq. datos de cliente, der. datos de guía/logística. Alto
       dinámico (display:table), no min-height fijo — ver nota SCRUM-783 arriba del bloque HTML. */
    .meta-block { display: table; width: 100%; margin: 14px 0 18px; border-bottom: 3px solid #5BA5A0; padding-bottom: 12px; }
    {{-- SCRUM-887 (rebote 2026-09-16, 3er rebote): Gerencia pidió que las 2 columnas queden
         alineadas en su parte superior como una sola sección — se quita el padding-top:34px
         que las desalineaba a propósito (offset heredado de SCRUM-783, cuando el mockup pedía
         la columna izquierda a la altura del título en vez de a la altura del logo). --}}
    .meta-left { display: table-cell; width: 52%; vertical-align: top; }
    .meta-right { display: table-cell; width: 48%; vertical-align: top; text-align: left; }
    .meta-line { margin-bottom: 6px; font-size: 10.5px; }
    .meta-line .value { font-weight: bold; }
    .meta-gap { height: 10px; }

    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }

    /* "Datos de entrega" — bloque de firma física para imprimir, líneas en blanco.
       page-break-inside:avoid (SCRUM-395, rebote 2026-08-17) — el bloque completo (título +
       las 2 líneas de firma) pasa entero a la página siguiente si no entra completo en la
       actual, nunca deja la línea de firma sola separada del resto. */
    {{-- SCRUM-887 — convención de label de sección: gris discreto (.block-title), no teal/bold
         llamativo (era el mismo tratamiento visual que un header de tabla, no el de un título de
         sección — ver `pdf.partials.styles`). --}}
    {{-- SCRUM-887 (rebote 2026-09-14) — +2 líneas en blanco (~28px) antes del bloque de firma
         (antes 30px, quedaba junto a la tabla tras quitar los avisos de "sin precios"/estado) y
         +2 líneas en blanco entre "Recibido por" y "Firma y fecha/hora" (antes 16px, mismo
         espaciado que el resto de líneas del bloque). --}}
    {{-- SCRUM-887 (rebote 2026-09-16, 3er rebote) — la regla de abajo nunca se aplicaba:
         `.line:first-child` exige que el elemento sea SIMULTÁNEAMENTE el primer hijo de
         `.delivery-data` y tener la clase `.line`, pero el primer hijo real es `.title`
         ("Datos de entrega"), no el primer `.line` — el selector jamás matcheaba nada, así que
         "Recibido por"/"Firma y fecha/hora" seguían con el margin-bottom base de 16px. Fix:
         `.title + .line` (hermano adyacente inmediato de `.title`), que sí apunta al primer
         `.line` sin depender de que sea el primer hijo del contenedor. --}}
    {{-- SCRUM-887 (rebote 2026-09-20, 4to rebote) — Gerencia confirmó que el espacio entre
         "Recibido por" y "Firma y fecha/hora" (`.title + .line`, abajo) ya está bien, NO tocar.
         El pedido de +2 líneas en blanco del rebote 2026-09-14 nunca aterrizó donde debía: el
         `margin-top: 58px` de `.delivery-data` separa la TABLA del título "Datos de entrega",
         pero entre el título y "Recibido por" solo quedaban 14px — ahí es donde el bloque seguía
         viéndose pegado. Fix: `.title { margin-bottom }` a 28px (2 líneas, misma convención ya
         usada en este archivo), sin tocar `margin-top` de `.delivery-data` ni `.title + .line`. --}}
    .delivery-data { margin-top: 58px; border-top: 3px solid #5BA5A0; padding-top: 10px; page-break-inside: avoid; }
    .delivery-data .title { margin-bottom: 28px; }
    .delivery-data .line { width: 260px; margin-left: auto; text-align: right; margin-bottom: 16px; }
    .delivery-data .title + .line { margin-bottom: 44px; }
    .delivery-data .line .fill { border-bottom: 1px solid #2a2520; height: 18px; }
    .delivery-data .line .caption { color: #9ca3af; font-size: 8.5px; margin-top: 2px; }

</style>
</head>
<body>
    @php
        $primaryContact = $order->pipelineCard?->contacts?->first();
        $guideNumber = $order->order_number.'-G';
        // SCRUM-894 — la Guía de Entrega es de ESTA tarjeta específica, no del pedido original
        // completo: `qty_delivered` ya viene resuelto por `DeliveryService::registerDelivery()`
        // (Packing -> Por despachar, TODOS los ítems de la tarjeta quedan con su `qty_delivered`
        // real seteado, incluido 0 — RN1 de esa transición exige que ningún ítem quede sin
        // registrar) antes de llamar a `generateGuiaEntrega()`, así que nunca hay que adivinar acá
        // con un fallback a `qty_requested` (el bug real: caso #PED-2026-0049, un ítem sin stock
        // quedaba en `qty_delivered=0` en esta tarjeta y la guía igual imprimía la cantidad total
        // pedida). Un ítem con 0 unidades a entregar en ESTA tarjeta no tiene nada que hacer en su
        // guía de entrega — se excluye, mismo criterio que ya usa
        // `CustomerReturnService::orderDetail()` (`qty_delivered > 0` = universo real entregado).
        $deliveredItems = $order->items->filter(fn ($item) => $item->qty_delivered > 0);
    @endphp

    @include('pdf.partials.header')

    <div class="doc-title">GUÍA DE ENTREGA</div>

    <div class="meta-block">
        {{-- Columna izquierda — datos del cliente, a la altura del título. Va primera en el DOM
             a propósito: con display:table-cell el orden visual sigue al orden del DOM (a
             diferencia del position:absolute anterior, que podía invertirlo con left/right). --}}
        <div class="meta-left">
            <div class="meta-line">Cliente: <span class="value">{{ $order->customer_name ?? '—' }}</span></div>
            <div class="meta-line">Proyecto: {{ $order->pipelineCard?->salesProject?->name ?? '—' }}</div>
            <div class="meta-line">Dirección de entrega: {{ $order->delivery_address ?? '—' }}</div>
            <div class="meta-line">Contacto: {{ $primaryContact?->name ?? '—' }}</div>
            <div class="meta-line">Teléfono: {{ $primaryContact?->phone ?? '—' }}</div>
            <div class="meta-line">Correo: {{ $primaryContact?->email ?? '—' }}</div>
        </div>
        {{-- Columna derecha — guía/pedido/logística, a la altura del logo --}}
        <div class="meta-right">
            <div class="meta-line">Guía n°: <span class="value">#{{ $guideNumber }}</span></div>
            <div class="meta-line">Pedido #{{ $order->order_number }}</div>
            <div class="meta-line">Fecha: <span class="value">{{ now()->format('d/m/Y, g:i a') }}</span></div>
            <div class="meta-line">Vendedor: <span class="value">{{ trim(($order->pipelineCard?->owner?->first_name ?? '').' '.($order->pipelineCard?->owner?->last_name ?? '')) ?: '—' }}</span></div>
            <div class="meta-line">Repartidor: <span class="value">{{ trim(($order->courier?->first_name ?? '').' '.($order->courier?->last_name ?? '')) ?: '—' }}</span></div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Ref. pública</th>
                <th>Artículo</th>
                <th>Cantidad</th>
            </tr>
        </thead>
        <tbody>
            @foreach($deliveredItems as $item)
                <tr>
                    <td>{{ $item->catalogProduct?->reference ?? $item->reference_snapshot ?? '—' }}</td>
                    <td>{{ $item->description_snapshot ?? $item->catalogProduct?->name ?? '—' }}</td>
                    <td>{{ $item->qty_delivered }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="delivery-data">
        <div class="title block-title">Datos de entrega</div>
        <div class="line">
            <div class="fill"></div>
            <div class="caption">Recibido por — Nombre y cédula/identificación</div>
        </div>
        <div class="line">
            <div class="fill"></div>
            <div class="caption">Firma y fecha/hora</div>
        </div>
    </div>

    {{-- SCRUM-887/SCRUM-893 — pie de página real completo (dirección + URL + Página X de Y),
         antes solo "Atlantic · Panamá · Powered by AtlanticERP" sin paginación. Mismo mecanismo
         que `ventas-diseno/quote-pdf.blade.php` (SCRUM-766), ver `pdf.partials.footer`. --}}
    @include('pdf.partials.footer')
</body>
</html>
