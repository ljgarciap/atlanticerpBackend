<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-891/SCRUM-893 — unificado con el modelo `servicios/service-quote-pdf.blade.php`:
         encabezado/pie reales fijos (antes contenido normal del flujo, sin paginación real),
         labels de sección en gris (`.block-title`, antes teal/uppercase/bold — mismo tratamiento
         que un header de tabla, no el de un título de sección). Firma real ya existente (técnico +
         cliente, con imagen cuando aplica) se mantiene tal cual — ya cubre el criterio, con más
         detalle que el partial genérico de firma en blanco. --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .sub { color: #6b7280; font-size: 10px; }
    .secondary-header-row { margin-bottom: 14px; }

    .section { margin-bottom: 14px; page-break-inside: avoid; }

    table.print-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.print-table th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 6px; font-size: 9px; text-transform: uppercase; }
    table.print-table td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }

    .empty-note { color: #9ca3af; font-style: italic; }

    {{-- SCRUM-891 (rebote 2026-09-16) — el `border-top` de `.signature-box` (heredado del mismo
         tratamiento que la línea "firme aquí" de la plantilla en blanco,
         `inspection-report-pdf-blank.blade.php`) no tiene sentido acá: este documento ya muestra
         el nombre real (y la imagen de firma cuando aplica), así que la línea aparecía como un
         separador sobrante justo antes del bloque de firmas. Se quita, y se sube el margin-top
         de `.signatures` +2 líneas en blanco (~28px, misma convención que SCRUM-887) para separar
         mejor de "Próximos pasos". --}}
    .signatures { display: table; width: 100%; margin-top: 48px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 24px; }
    .signature-box.signed { padding-top: 4px; }
    .signature-image { max-height: 50px; max-width: 90%; margin-bottom: 4px; }
</style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="secondary-header-row">
        <div class="sub">Informe de Inspección {{ $report->numero }} — Ticket {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="block-title">Fecha de inspección</div>
        <div class="block-value">{{ $report->fecha_inspeccion?->format('d/m/Y') ?? '—' }}</div>

        <div class="block-title">Horario</div>
        <div class="block-value">
            @if($report->hora_inicio || $report->hora_fin)
                {{ $report->hora_inicio ?? '—' }}{{ $report->hora_fin ? ' – '.$report->hora_fin : '' }}
            @else
                —
            @endif
        </div>

        <div class="block-title">Técnico responsable</div>
        <div class="block-value">
            @if($report->internalTechnician)
                {{ $report->internalTechnician->first_name }} {{ $report->internalTechnician->last_name }}
            @else
                —
            @endif
        </div>
    </div>

    <div class="section">
        <div class="block-title">Hallazgos de la inspección</div>
        @if(!empty($report->findings))
            <table class="print-table">
                <thead>
                    <tr><th>Campo</th><th>Valor</th></tr>
                </thead>
                <tbody>
                    @foreach($report->findings as $finding)
                        <tr>
                            <td>{{ $finding['label'] ?? '—' }}</td>
                            <td>{{ $finding['value'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Sin hallazgos registrados</p>
        @endif
    </div>

    <div class="section">
        <div class="block-title">Materiales / insumos utilizados</div>
        @if($report->materials->isNotEmpty())
            <table class="print-table">
                <thead>
                    <tr><th>Material</th><th>Cantidad</th></tr>
                </thead>
                <tbody>
                    @foreach($report->materials as $material)
                        <tr>
                            <td>{{ $material->nombre_material }}</td>
                            <td>{{ $material->cantidad }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Ninguno registrado</p>
        @endif
    </div>

    <div class="section">
        <div class="block-title">Conclusión</div>
        <div class="block-value">{{ $report->conclusion ?: '—' }}</div>

        <div class="block-title">¿Requiere seguimiento?</div>
        <div class="block-value">{{ $report->requiere_seguimiento ? 'Sí' : 'No' }}</div>

        @if($report->requiere_seguimiento)
            <div class="block-title">Próximos pasos</div>
            <div class="block-value">{{ $report->proximos_pasos ?: '—' }}</div>
        @endif
    </div>

    {{-- SCRUM-891 — firma real (nombre + imagen cuando aplica) ya existente, más completa que el
         partial genérico de firma en blanco: se mantiene tal cual, ver comentario del <style>. --}}
    <div class="signatures">
        <div class="signature-box">{{ $report->firma_tecnico_nombre ?: 'Sin firma' }}<br><span class="sub">Técnico responsable</span></div>
        <div class="signature-box {{ $firmaClienteImagenDataUri ? 'signed' : '' }}">
            @if($firmaClienteImagenDataUri)
                <img class="signature-image" src="{{ $firmaClienteImagenDataUri }}" alt="Firma del cliente">
                <br>
            @endif
            {{ $report->firma_cliente_nombre ?: 'Sin firma' }}<br><span class="sub">Cliente</span>
        </div>
    </div>

    @include('pdf.partials.footer')
</body>
</html>
