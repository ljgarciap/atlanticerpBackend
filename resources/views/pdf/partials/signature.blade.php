{{--
    SCRUM-893 — bloque de firma, calcado 1:1 de `servicios/service-quote-pdf.blade.php` (`.signature`
    en `pdf.partials.styles`). Parametrizable porque el texto de la etiqueta cambia según el
    documento ("Firma del cliente" vs "Firma del responsable" vs "Firma del proveedor", etc.).

    Variables:
    - $signatureLabel (string, opcional) — default 'Firma del cliente' (mismo default del modelo).
    - $signedName (string|null, opcional) — si viene, se muestra arriba de la etiqueta (nombre real
      de quien firmó, ej. cuando el documento ya fue firmado/aprobado). Omitir o dejar null cuando
      el documento todavía no tiene firma real (deja solo la línea + etiqueta, para firmar a mano).

    Para 2 firmas lado a lado (ej. "responsable y proveedor", SCRUM-886), envolver 2 `@include` de
    este partial en un contenedor `display:table` de 2 columnas — no hay una variante "doble"
    separada, es el mismo bloque repetido.
--}}
<div class="signature">
    @if(!empty($signedName))
        <div class="signed-name">{{ $signedName }}</div>
    @endif
    {{ $signatureLabel ?? 'Firma del cliente' }}
</div>
