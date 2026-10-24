<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-892/SCRUM-893 — unificado con el modelo `servicios/service-quote-pdf.blade.php`:
         logo real + encabezado/pie fijos (antes ninguno de los dos), título con identidad de
         empresa (antes genérico "Reporte de Gerencia" sin marca), fechas/meses en formato legible
         en vez de ISO 8601/"YYYY-MM" técnico. Tipografía DejaVu Sans — Luis aprobó explícitamente
         mantenerla (el modelo real también la usa; el hallazgo original que pedía Arial/Helvetica
         quedó descartado). --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .secondary-header-row { margin-bottom: 10px; }
    .secondary-header-badge { display: block; color: #3D7E7A; font-weight: bold; font-size: 14px; line-height: 17px; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0 16px; }
    h2 { font-size: 12px; color: #3D7E7A; margin: 18px 0 6px 0; }
    .subtitle { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .kpi-grid { width: 100%; }
    .kpi-grid td { border: none; padding: 4px 6px 4px 0; }
    .kpi-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .kpi-value { font-size: 13px; font-weight: bold; }
    .empty { color: #9ca3af; text-align: center; padding: 16px 0; }
</style>
</head>
<body>
    @php
        $desdeLabel = \Carbon\Carbon::parse($filters['desde'])->translatedFormat('d M Y');
        $hastaLabel = \Carbon\Carbon::parse($filters['hasta'])->translatedFormat('d M Y');
    @endphp

    @include('pdf.partials.header')

    <div class="secondary-header-row">
        <div class="secondary-header-badge">ATLANTIC - Reporte de Gerencia</div>
    </div>
    <div class="divider"></div>

    <div class="subtitle">
        {{ $desdeLabel }} — {{ $hastaLabel }}
        · Vendedor/Diseñador: {{ $filters['vendedor'] ?? 'Todos' }}
        · Cliente: {{ $filters['cliente'] ?? 'Todos' }}
        · Generado el {{ $generatedAt->format('d/m/Y H:i') }}
    </div>

    <h2>Variación margen facturado vs. cotizado</h2>
    <table class="kpi-grid">
        <tr>
            <td>
                <div class="kpi-label">Facturado</div>
                <div class="kpi-value">${{ number_format($data['kpis']['variacion_margen']['facturado'] ?? 0, 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">Cotizado</div>
                <div class="kpi-value">${{ number_format($data['kpis']['variacion_margen']['cotizado'] ?? 0, 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">Variación</div>
                <div class="kpi-value">
                    @if (($data['kpis']['variacion_margen']['variacion_pct'] ?? null) !== null)
                        {{ $data['kpis']['variacion_margen']['variacion_pct'] }} pts
                    @else
                        N/A
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <h2>Facturación</h2>
    <table class="kpi-grid">
        <tr>
            <td>
                <div class="kpi-label">Facturado (año)</div>
                <div class="kpi-value">${{ number_format($data['facturacion']['kpis']['facturado_anio'] ?? 0, 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">Facturado (período)</div>
                <div class="kpi-value">${{ number_format($data['facturacion']['kpis']['facturado_mes'] ?? 0, 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">Facturas (período)</div>
                <div class="kpi-value">{{ $data['facturacion']['kpis']['facturas_count'] ?? 0 }}</div>
            </td>
        </tr>
    </table>
    @if (count($data['facturacion']['chart_mensual'] ?? []) === 0)
        <div class="empty">Sin facturación en el rango filtrado.</div>
    @else
        <table>
            <thead><tr><th>Mes</th><th class="numeric">Monto facturado</th></tr></thead>
            <tbody>
                @foreach ($data['facturacion']['chart_mensual'] as $row)
                    <tr>
                        <td>{{ ucfirst(\Carbon\Carbon::createFromFormat('Y-m', $row['mes'])->translatedFormat('M Y')) }}</td>
                        <td class="numeric">${{ number_format($row['monto'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Proyectos</h2>
    <table class="kpi-grid">
        <tr>
            <td>
                <div class="kpi-label">Cerrados (período)</div>
                <div class="kpi-value">{{ $data['proyectos']['kpis']['cerrados_mes_count'] ?? 0 }}</div>
            </td>
            <td>
                <div class="kpi-label">Monto cerrado (período)</div>
                <div class="kpi-value">${{ number_format($data['proyectos']['kpis']['cerrados_mes'] ?? 0, 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">Proyectos activos</div>
                <div class="kpi-value">{{ $data['kpis']['proyectos_activos'] ?? 0 }}</div>
            </td>
        </tr>
    </table>
    @if (count($data['proyectos']['chart_mensual'] ?? []) === 0)
        <div class="empty">Sin proyectos cerrados en el rango filtrado.</div>
    @else
        <table>
            <thead><tr><th>Mes</th><th class="numeric">Monto cerrado</th></tr></thead>
            <tbody>
                @foreach ($data['proyectos']['chart_mensual'] as $row)
                    <tr>
                        <td>{{ ucfirst(\Carbon\Carbon::createFromFormat('Y-m', $row['mes'])->translatedFormat('M Y')) }}</td>
                        <td class="numeric">${{ number_format($row['monto'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
