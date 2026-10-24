<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-924 (rebote Gerencia 2026-09-20) — el pie de página era un <div> normal al final del
         flujo del documento (solo aparecía una vez, pegado al último contenido, en vez de repetirse
         en la parte inferior de CADA página) — nunca adoptó el mecanismo de footer real que el
         resto de PDFs del proyecto ya estandarizó (SCRUM-893, ver `pdf.partials.footer.blade.php`).
         `margin-bottom` reserva el espacio para que el contenido normal nunca choque con el texto
         que `page_text()` dibuja más abajo en el body — sin tocar el resto del margen/layout
         (`.header` de este documento no es fijo/repetido como en esos otros PDFs, así que no hace
         falta reservar espacio arriba). --}}
    @page { margin-bottom: 60px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    /* REQ-228 (SCRUM-781) — mismo isólogo embebido que claim-sheet-pdf/inspection-report-pdf/
       service-quote-pdf.blade.php (dompdf no renderiza <svg> inline, ver PdfLogo::dataUri()). */
    .brand-row { display: table; width: 100%; }
    .brand-cell { display: table-cell; vertical-align: middle; }
    .brand { font-size: 18px; font-weight: bold; color: #5BA5A0; padding-left: 8px; }
    .sub { color: #6b7280; font-size: 10px; }

    .section { margin-bottom: 14px; page-break-inside: avoid; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #3D7E7A; margin-bottom: 2px; }
    .value { font-size: 11px; margin-bottom: 8px; }

    table.print-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.print-table th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 6px; font-size: 9px; text-transform: uppercase; }
    table.print-table td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }

    .empty-note { color: #9ca3af; font-style: italic; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 28px;">
                <img src="{{ $logoDataUri }}" width="26" height="26" alt="Atlantic">
            </div>
            <div class="brand-cell brand">ATLANTIC</div>
        </div>
        <div class="sub">Ticket de Servicio {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="label">Cliente</div>
        <div class="value">
            @if($detail['cliente_master'] === $detail['subcliente'])
                {{ $detail['cliente_master'] ?? '—' }}
            @else
                {{ $detail['cliente_master'] ?? '—' }} — {{ $detail['subcliente'] ?? '—' }}
            @endif
        </div>

        <div class="label">Email de contacto</div>
        <div class="value">{{ $detail['email'] ?? '—' }}</div>

        <div class="label">Proyecto asociado</div>
        <div class="value">{{ $detail['proyecto'] ?? 'Sin proyecto asociado' }}</div>

        <div class="label">Contacto</div>
        <div class="value">{{ $detail['contacto'] ?? '—' }}{{ $detail['telefono'] ? ' · '.$detail['telefono'] : '' }}</div>

        <div class="label">Dirección completa</div>
        <div class="value">{{ $detail['direccion'] ?? '—' }}</div>

        <div class="label">Fecha y hora de servicio</div>
        <div class="value">{{ $fechaServicio }}</div>

        <div class="label">Horario de trabajo / visita</div>
        <div class="value">{{ $horario }}</div>
    </div>

    <div class="section">
        <div class="label">Requerimientos especiales</div>
        @php $requisitos = $detail['requerimientos_especiales'] ?? ['catalog' => [], 'otros' => []]; @endphp
        @if(empty($requisitos['catalog']) && empty($requisitos['otros']))
            <div class="value">Ninguno aplica</div>
        @else
            <div class="value">
                {{ implode(', ', array_merge(array_column($requisitos['catalog'], 'label'), $requisitos['otros'])) }}
            </div>
        @endif
    </div>

    <div class="section">
        <div class="label">Productos reclamados / afectados</div>
        @if(!empty($detail['productos']))
            <table class="print-table">
                <thead>
                    <tr><th>Marca</th><th>Referencia</th><th>Descripción</th><th>Cantidad</th></tr>
                </thead>
                <tbody>
                    @foreach($detail['productos'] as $producto)
                        <tr>
                            <td>{{ $producto['marca'] ?? '—' }}</td>
                            <td>{{ $producto['referencia'] ?? '—' }}</td>
                            <td>{{ $producto['descripcion'] ?? '—' }}</td>
                            <td>{{ $producto['cantidad_reclamada'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No aplica para este tipo de ticket</p>
        @endif
    </div>

    <div class="section">
        <div class="label">Informe de inspección</div>
        <div class="value">{{ $detail['inspection_report_status'] === 'not_applicable' ? 'No aplica para este tipo de ticket' : ($detail['inspection_report_status'] === 'completed' ? 'Completado — ver documento de Informe de Inspección para el detalle y la firma del responsable.' : 'Aún no se ha realizado la inspección.') }}</div>
    </div>

    <div class="section">
        <div class="label">Observaciones</div>
        <div class="value">{{ $detail['observaciones'] ?: '—' }}</div>
    </div>

    <div class="section">
        <div class="label">Adjuntos</div>
        {{-- SCRUM-924 — `$detail['adjuntos']` es un array de objetos (id/nombre_archivo/size_bytes/
             mime_type/created_at, ver `TicketService::detail()`), nunca una lista plana de
             strings: `implode()` directo sobre el array producía "Array to string conversion"
             (excepción real, no solo warning, vía el manejador de errores de Laravel) para
             CUALQUIER ticket con al menos un adjunto — reproducido con GAR-2026-0003. Mismo
             patrón que ya se usa arriba para "Requerimientos especiales"
             (`array_column(..., 'label')`). --}}
        <div class="value">{{ !empty($detail['adjuntos']) ? implode(', ', array_column($detail['adjuntos'], 'nombre_archivo')) : 'Sin adjuntos' }}</div>
    </div>

    {{-- SCRUM-924 — pie de página REAL vía `page_text()` (mismo mecanismo/estilo que
         `pdf.partials.footer.blade.php`, ver docblock de @page arriba), repetido en cada página.
         Texto propio de este documento (no el genérico de dirección de la empresa del partial
         compartido) — se mantiene igual, solo cambia CÓMO se dibuja, partido en 2 líneas para que
         entre dentro del ancho imprimible de Carta (612pt) a este tamaño de letra. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $line1 = "Atlantic · Panamá";
            $line2 = "Nota: el diagnóstico y la firma del responsable se registran en el documento de Informe de Inspección, no en este ticket.";
            $w1 = $fontMetrics->getTextWidth($line1, $font, 7);
            $w2 = $fontMetrics->getTextWidth($line2, $font, 7);
            $pdf->page_text((612 - $w1) / 2, 758, $line1, $font, 7, array(0.55, 0.55, 0.55));
            $pdf->page_text((612 - $w2) / 2, 770, $line2, $font, 7, array(0.55, 0.55, 0.55));
        }
    </script>
</body>
</html>
