<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-889/SCRUM-893 — encabezado/labels unificados con el modelo
         `servicios/service-quote-pdf.blade.php` (`pdf.partials.styles`/`pdf.partials.header`) —
         mismo criterio de @page que account-statement-pdf.blade.php: espacio arriba para el
         encabezado fijo y abajo para el pie real (page_text(), bloque <script> al final). --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    h1 { font-size: 14px; color: #3D7E7A; margin: 0 0 2px; }
    .subtitle { font-size: 9.5px; color: #6b7280; margin-bottom: 10px; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0 14px; }

    .section-label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #3D7E7A; letter-spacing: .5px; margin-bottom: 4px; }
    .resumen-cols { display: table; width: 100%; margin-bottom: 14px; }
    .resumen-col { display: table-cell; width: 50%; vertical-align: top; }
    .resumen-line { font-size: 10px; color: #2a2520; line-height: 15px; }
    .resumen-line .k { color: #6b7280; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.rows th { background: #3D7E7A; color: #ffffff; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; }
    table.rows th.numeric, table.rows td.numeric { text-align: right; }
    table.rows td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 9.5px; vertical-align: top; }
    .empty { color: #9ca3af; padding: 6px 0; font-size: 9.5px; }

    {{-- Sección de totales, después de la tabla — SIN recuadro (ajuste PM Review SCRUM-878),
         mismo ancho/márgenes que table.rows, valores alineados a la derecha por línea. --}}
    .totales-box { width: 100%; padding: 4px 0 0; }
    .totales-box .line { display: table; width: 100%; font-size: 10px; padding: 2px 0; }
    .totales-box .line .lbl { display: table-cell; color: #6b7280; }
    .totales-box .line .val { display: table-cell; text-align: right; }
    .totales-box .line.neg .lbl, .totales-box .line.neg .val { color: #b91c1c; }
    .totales-box .line.neto { border-top: 1px solid #d9d2c2; margin-top: 4px; padding-top: 5px; }
    .totales-box .line.neto .lbl, .totales-box .line.neto .val { font-weight: bold; color: #3D7E7A; font-size: 11px; }

    h2 { font-size: 11px; color: #3D7E7A; margin: 20px 0 6px; }
    .estado-ok { color: #3D7E7A; }
    .estado-pendiente { color: #92600f; }
</style>
</head>
<body>
    @include('pdf.partials.header')

    <h1>Resumen del Arqueo — Arqueo N° {{ $numero ?? $numero_preview ?? '—' }}</h1>
    <div class="subtitle">Tesorería · Arqueo de Caja · Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    <div class="divider"></div>

    <div class="resumen-cols">
        <div class="resumen-col">
            <div class="section-label">Resumen</div>
            <div class="resumen-line"><span class="k">Fecha:</span> {{ \Carbon\Carbon::parse($fecha)->format('d/m/Y') }}</div>
            <div class="resumen-line">
                <span class="k">Estado:</span>
                @if ($cerrado_por)
                    Realizado por {{ $cerrado_por }}@if ($cerrado_at) a las {{ \Carbon\Carbon::parse($cerrado_at)->format('H:i') }}@endif
                @else
                    Arqueo aún no cerrado
                @endif
            </div>
            <div class="resumen-line">
                @if ($aprobado_por)
                    <span class="k">Aprobado por Gerencia —</span> {{ $aprobado_por }}@if ($aprobado_at) el {{ \Carbon\Carbon::parse($aprobado_at)->format('d/m/Y') }}@endif
                @elseif ($cerrado_por)
                    <span class="estado-pendiente">Pendiente de aprobación de Gerencia (Mark)</span>
                @endif
            </div>
        </div>
        <div class="resumen-col">
            <div class="section-label">Conciliación</div>
            @php
                $retencionesTotal = count($retenciones ?? []);
                $retencionesConConstancia = collect($retenciones ?? [])->filter(fn ($r) => ($r['constancia'] ?? null) !== null)->count();
            @endphp
            <div class="resumen-line">
                <span class="k">Retenciones con constancia:</span> {{ $retencionesConConstancia }} de {{ $retencionesTotal }}
            </div>
        </div>
    </div>

    @if (!empty($observacion_general))
        <div class="section-label">Observación general</div>
        <div class="resumen-line" style="margin-bottom: 14px;">{{ $observacion_general }}</div>
    @endif

    <div class="section-label">Movimientos del día</div>
    @php
        $movimientos = [];
        foreach ($cobros ?? [] as $c) {
            $movimientos[] = [
                'concepto'    => $c['concepto'],
                'cliente'     => $c['cliente'] ?? null,
                'medio'       => $metodoLabels[$c['metodo_pago']] ?? $c['metodo_pago'],
                'observacion' => $c['observacion'] ?? null,
                'monto'       => $c['monto'],
            ];
        }
        foreach ($notas_credito ?? [] as $n) {
            $movimientos[] = [
                'concepto'    => $n['concepto'],
                'cliente'     => $n['cliente'] ?? null,
                'medio'       => '—',
                'observacion' => $n['observacion'] ?? null,
                'monto'       => $n['monto'],
            ];
        }
        // SCRUM-879 — comisiones pagadas el día, como salida real más dentro de la misma tabla.
        foreach ($comisiones ?? [] as $c) {
            $movimientos[] = [
                'concepto'    => $c['concepto'],
                'cliente'     => null,
                'medio'       => '—',
                'observacion' => $c['observacion'] ?? null,
                'monto'       => $c['monto'],
            ];
        }
        // SCRUM-1168 — anulaciones de cobros y devoluciones de dinero al cliente del día (salida real de caja).
        foreach ($reversos ?? [] as $r) {
            $movimientos[] = [
                'concepto'    => $r['concepto'],
                'cliente'     => $r['cliente'] ?? null,
                'medio'       => '—',
                'observacion' => $r['observacion'] ?? null,
                'monto'       => $r['monto'],
            ];
        }
    @endphp
    @if (empty($movimientos))
        <div class="empty">Sin movimientos este día.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Cliente</th>
                    <th>Medio</th>
                    <th>Observaciones</th>
                    <th class="numeric">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movimientos as $m)
                    <tr>
                        <td>{{ $m['concepto'] }}</td>
                        <td>{{ $m['cliente'] ?: '—' }}</td>
                        <td>{{ $m['medio'] }}</td>
                        <td>{{ $m['observacion'] ?: '—' }}</td>
                        <td class="numeric">${{ number_format($m['monto'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="totales-box">
        <div class="line"><span class="lbl">Total cobrado</span><span class="val">${{ number_format($total_cobrado, 2) }}</span></div>
        <div class="line neg"><span class="lbl">Notas crédito y devoluciones</span><span class="val">-${{ number_format($total_notas_credito, 2) }}</span></div>
        <div class="line neg"><span class="lbl">Comisiones pagadas</span><span class="val">-${{ number_format($total_comisiones, 2) }}</span></div>
        @if (($total_reversos ?? 0) > 0)
            <div class="line neg"><span class="lbl">Anulaciones y devoluciones al cliente</span><span class="val">-${{ number_format($total_reversos, 2) }}</span></div>
        @endif
        <div class="line neto"><span class="lbl">Total neto del día</span><span class="val">${{ number_format($total_neto, 2) }}</span></div>
    </div>

    {{-- SCRUM-934 — indicadores del día, informativos (no suman al total neto): valor al momento
         exacto de generar el arqueo, se reinician cada día. --}}
    <div class="section-label" style="margin-top: 14px;">Indicadores del día</div>
    <div class="totales-box">
        <div class="line"><span class="lbl">Total facturado en el día</span><span class="val">${{ number_format($total_facturado_dia ?? 0, 2) }}</span></div>
        <div class="line"><span class="lbl">Total pasado a pedido en el día</span><span class="val">${{ number_format($total_pasado_a_pedido_dia ?? 0, 2) }}</span></div>
    </div>

    @if (!empty($retenciones))
        <h2>Detalle de retenciones</h2>
        <table class="rows">
            <thead><tr><th>Cliente</th><th>Motivo</th><th class="numeric">Monto</th><th>Constancia</th></tr></thead>
            <tbody>
                @foreach ($retenciones as $r)
                    <tr>
                        <td>{{ $r['cliente'] ?? '—' }}</td>
                        <td>{{ $r['motivo'] ?? '—' }}</td>
                        <td class="numeric">${{ number_format($r['monto'], 2) }}</td>
                        <td class="{{ ($r['constancia'] ?? null) ? 'estado-ok' : 'estado-pendiente' }}">
                            {{ ($r['constancia'] ?? null) ? 'Adjunta — '.$r['constancia']['nombre_archivo'] : 'Pendiente' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- SCRUM-878/893 — pie de página REAL y fijo (marca + dirección + numeración), estándar del
         sistema, NO "Documento generado por AtlanticERP". Mismo mecanismo que account-statement-pdf,
         ahora como partial compartido (`pdf.partials.footer`). --}}
    @include('pdf.partials.footer')
</body>
</html>
