{{--
    SCRUM-893 — encabezado real de marca (logo + ATLANTIC + línea teal), repetido en cada
    página vía `position:fixed` (ver `.doc-header` en `pdf.partials.styles`). Calcado 1:1 de
    `servicios/service-quote-pdf.blade.php`. `$logoDataUri` viene de `App\Shared\Support\PdfLogo`
    — dompdf no renderiza `<svg>` inline en este entorno (ver docblock de esa clase).

    `$logoDataUri ?? ''` — no todos los tests que renderizan estos blades directamente (sin pasar
    por el *PdfService) pasan esta variable; nunca debe romper el render por eso.

    El @page de cada documento debe reservar `margin-top: 95px` (70px de alto del header + 25px de
    aire) para que este bloque fijo nunca se superponga con el contenido — ver el comentario de
    `@page` en cada blade que lo usa.
--}}
<div class="doc-header">
    <div class="brand-row">
        <div class="brand-cell" style="width: 40px;">
            <img src="{{ $logoDataUri ?? '' }}" width="36" height="36" alt="Atlantic">
        </div>
        <div class="brand-cell brand-name">ATLANTIC</div>
    </div>
    <div class="brand-line"></div>
</div>
