<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-411 (REQ-341, rebote de Daniela Amaya 2026-08-12) — rediseño completo contra el
         mockup: ícono + ATLANTIC + "Powered by AtlanticERP" como marca (antes solo texto, sin
         ícono, con "Estatus de Pedido" como subtítulo — RN1), título grande "ESTATUS DE PEDIDO"
         como encabezado principal en vez del "Pedido {numero}" secundario que usaba antes (RN2),
         datos generales en 2 columnas por posición absoluta en vez de la cuadrícula de 3 columnas
         anterior (RN3/RN4), y pie de página institucional que antes no existía (RN5). Paleta
         VERDE institucional, la misma de siempre (#5BA5A0/#E3F0EE/#3D7E7A) — el ejemplo de
         negocio que trajo el cliente estaba en rojo, decisión explícita de no replicarlo. --}}
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }

    /* Marca (ícono + ATLANTIC + Powered by AtlanticERP) */
    .brand-row { position: relative; height: 30px; margin-bottom: 4px; }
    .brand-icon { position: absolute; left: 0; top: 2px; width: 22px; height: 22px; }
    .brand-icon span { position: absolute; width: 14px; height: 14px; border-radius: 50%; opacity: 0.85; }
    .brand-icon .c1 { background: #5BA5A0; left: 0; top: 4px; }
    .brand-icon .c2 { background: #9fc54d; left: 8px; top: 0; }
    .brand-icon .c3 { background: #3D7E7A; left: 8px; top: 8px; }
    .brand-text { margin-left: 28px; }
    .brand-name { font-size: 18px; font-weight: bold; color: #5BA5A0; line-height: 1.1; }
    .brand-sub { color: #6b7280; font-size: 9px; }

    /* Título principal del documento */
    .doc-title { font-size: 20px; font-weight: bold; color: #5BA5A0; margin-top: 14px; }

    /* Bloque de datos generales — 2 columnas. Alto dinámico (display:table), no min-height fijo:
       SCRUM-783 (2026-08-20) encontró el mismo min-height adivinado montando texto sobre la tabla
       en guia-entrega-pdf.blade.php — acá el min-height:100px original nunca llegó a romperse de
       verdad (5 líneas + gap ≈ 95-100px, apenas alcanzaba), pero es el mismo patrón frágil: una
       línea más, o un valor largo que envuelva a 2 líneas, lo desborda igual. Mismo fix acá antes
       de que rebote como el 783. */
    .meta-block { display: table; width: 100%; margin: 14px 0 18px; border-bottom: 3px solid #5BA5A0; padding-bottom: 12px; }
    .meta-left { display: table-cell; width: 48%; vertical-align: top; padding-top: 34px; }
    .meta-right { display: table-cell; width: 48%; vertical-align: top; text-align: left; }
    .meta-line { margin-bottom: 6px; font-size: 10.5px; }
    .meta-line .value { font-weight: bold; }
    .meta-gap { height: 10px; }

    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }

    /* Pie de página institucional — mismo texto/estilo a reusar en Guía de Entrega/Factura/
       Formulario de devolución cuando se retome ese trabajo (ver nota de alcance del ticket). */
    .doc-footer { margin-top: 28px; text-align: center; color: #9ca3af; font-size: 8.5px; }
</style>
</head>
<body>
    <div class="brand-row">
        <div class="brand-icon">
            <span class="c1"></span><span class="c2"></span><span class="c3"></span>
        </div>
        <div class="brand-text">
            <div class="brand-name">ATLANTIC</div>
            <div class="brand-sub">Powered by AtlanticERP</div>
        </div>
    </div>

    <div class="doc-title">ESTATUS DE PEDIDO</div>

    <div class="meta-block">
        {{-- Columna izquierda — inicia a la altura del título "ESTATUS DE PEDIDO" (RN3/Escenario 4).
             Va primera en el DOM a propósito: con display:table-cell el orden visual sigue al
             orden del DOM (a diferencia del position:absolute anterior, que podía invertirlo con
             left/right). --}}
        <div class="meta-left">
            <div class="meta-line">Proyecto: <span class="value">{{ $status['proyecto'] ?? '—' }}</span></div>
            <div class="meta-line">Diseñador: <span class="value">{{ $status['disenador'] ?? '—' }}</span></div>
            <div class="meta-line">Fecha de Estatus: <span class="value">{{ $status['fecha_estatus'] }}</span></div>
        </div>
        {{-- Columna derecha — inicia a la altura del logo (RN3/Escenario 4) --}}
        <div class="meta-right">
            <div class="meta-line">N° de pedido: <span class="value">{{ $status['order_number'] }}</span></div>
            <div class="meta-line">N° de cotización: <span class="value">{{ $status['quote_number'] ?? '—' }}</span></div>
            <div class="meta-gap"></div>
            <div class="meta-line">Cliente: <span class="value">{{ $status['cliente'] ?? '—' }}</span></div>
            <div class="meta-line">Contacto: <span class="value">{{ $status['contacto'] ?? '—' }}</span></div>
            <div class="meta-line">Teléfono: <span class="value">{{ $status['telefono'] ?? '—' }}</span></div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Ref. pública</th>
                <th>Artículo</th>
                <th>Solicitada</th>
                <th>Entregado</th>
                <th>Pendiente</th>
            </tr>
        </thead>
        <tbody>
            @foreach($status['items'] as $item)
                <tr>
                    <td>{{ $item['reference'] ?? '—' }}</td>
                    <td>{{ $item['description'] ?? '—' }}</td>
                    <td>{{ $item['solicitada'] }}</td>
                    <td>{{ $item['entregado'] }}</td>
                    <td>{{ $item['pendiente'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="doc-footer">Atlantic · Panamá · Powered by AtlanticERP</div>
</body>
</html>
