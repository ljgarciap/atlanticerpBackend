{{--
    SCRUM-893 — pie de página REAL (dirección de la empresa + "Página X de Y"), repetido en cada
    página. Calcado 1:1 de `servicios/service-quote-pdf.blade.php` / `ventas-diseno/quote-pdf.blade.php`.

    Mecanismo: `<script type="text/php">` + `$pdf->page_text()` — el ÚNICO mecanismo de dompdf con
    paginación real ({PAGE_NUM}/{PAGE_COUNT}) y sin el bug conocido de páginas en blanco que sí
    tiene `position:fixed; bottom:...` (ver memoria `feedback_dompdf_absolute_position_fixed_
    minheight_overlap` y los comentarios de `bodega/guia-entrega-pdf.blade.php`). El servicio que
    genera el PDF DEBE habilitar `Pdf::setOption('isPhpEnabled', true)` o este bloque nunca corre
    (dompdf lo ignora en silencio, sin error).

    `$footerCompanyText` — texto ya escapado para interpolarse dentro de un string PHP
    doble-comillado (ver `App\Modules\AdminCont\Support\PdfFooterText::forFiscal()` para el
    documento que sí depende de datos fiscales reales, ej. Factura/Arqueo/Caja Chica). Si el
    documento no tiene datos fiscales propios (Compras/Bodega/Servicios/Gerencia), se omite la
    variable y este partial cae al texto público estándar ya aprobado (mismo string que
    `PdfFooterText::DEFAULT_FOOTER` — no se referencia esa clase directamente acá para no acoplar
    un partial genérico a un módulo de negocio puntual (AdminCont), es el mismo texto duplicado a
    propósito).
--}}
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont("DejaVu Sans", "normal");
        $companyText = "{!! $footerCompanyText ?? 'ATLANTIC USA CORP - Calle 57 Este, Times Square Plaza - Obarrio, Panama - www.atlantic.com.pa' !!}";
        $w1 = $fontMetrics->getTextWidth($companyText, $font, 7);
        $pdf->page_text((612 - $w1) / 2, 762, $companyText, $font, 7, array(0.55, 0.55, 0.55));

        $pageText = "{!! $pageLabel ?? 'Pagina {PAGE_NUM} de {PAGE_COUNT}' !!}";
        $w2 = $fontMetrics->getTextWidth("Pagina 00 de 00", $font, 7);
        $pdf->page_text((612 - $w2) / 2, 774, $pageText, $font, 7, array(0.55, 0.55, 0.55));
    }
</script>
