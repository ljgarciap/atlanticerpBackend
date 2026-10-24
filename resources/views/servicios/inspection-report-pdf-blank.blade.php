<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-891/SCRUM-893 — mismo criterio que `inspection-report-pdf.blade.php`: encabezado/pie
         reales fijos + labels en gris (`.block-title`, ver `pdf.partials.styles`). --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .sub { color: #6b7280; font-size: 10px; }
    .secondary-header-row { margin-bottom: 14px; }

    .section { margin-bottom: 14px; page-break-inside: avoid; }
    .blank-line { border-bottom: 1px solid #2a2520; height: 26px; margin-bottom: 8px; }

    .empty-note { color: #9ca3af; font-style: italic; }

    .signatures { display: table; width: 100%; margin-top: 28px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 40px; border-top: 1px solid #2a2520; }
</style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="secondary-header-row">
        <div class="sub">Plantilla en blanco — Informe de Inspección — Ticket {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="block-title">Fecha de inspección</div>
        <div class="blank-line"></div>

        <div class="block-title">Horario (inicio – fin)</div>
        <div class="blank-line"></div>

        <div class="block-title">Técnico responsable</div>
        <div class="blank-line"></div>
    </div>

    <div class="section">
        <div class="block-title">Hallazgos de la inspección</div>
        @if(!empty($fields))
            @foreach($fields as $field)
                <div class="block-title">{{ $field['label'] }}</div>
                <div class="blank-line"></div>
            @endforeach
        @else
            <p class="empty-note">Sin campos definidos para este tipo de ticket</p>
        @endif
    </div>

    <div class="section">
        <div class="block-title">Materiales / insumos utilizados</div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>
    </div>

    <div class="section">
        <div class="block-title">Conclusión</div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>

        <div class="block-title">¿Requiere seguimiento? (Sí / No)</div>
        <div class="blank-line"></div>

        <div class="block-title">Próximos pasos</div>
        <div class="blank-line"></div>
    </div>

    <div class="signatures">
        <div class="signature-box">Técnico responsable</div>
        <div class="signature-box">Cliente</div>
    </div>

    @include('pdf.partials.footer')
</body>
</html>
