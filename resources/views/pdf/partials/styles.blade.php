{{--
    SCRUM-893 (Estandarización descargables) — reglas CSS compartidas por los descargables PDF
    del sistema, extraídas 1:1 de `resources/views/servicios/service-quote-pdf.blade.php` (el
    documento modelo, ya validado con el cliente — ver SCRUM-824). Nace de la auditoría SCRUM-853:
    7 documentos habían divergido del modelo cada uno a su manera (logo de texto en vez de imagen,
    "Powered by AtlanticERP", labels teal en vez de gris, sin pie de página real, sin espacio de firma).

    Uso: `@include('pdf.partials.styles')` DENTRO del bloque `<style>...</style>` de cada
    documento (no es un archivo .css independiente — Blade lo inserta como texto plano ahí mismo).
    No declara reglas de `@page` ni `body` — cada documento reserva su propio espacio de
    encabezado/pie según el alto real de SU contenido (ver comentario de `@page` en cada blade),
    así que esa regla se queda declarada en cada documento, nunca acá.

    Combinar siempre con `pdf.partials.header` (mismo `.doc-header`/`.brand-*` de acá) y, cuando el
    documento necesite firma, con `pdf.partials.signature` (mismo `.signature` de acá).
--}}
.doc-header { position: fixed; top: -80px; left: 0; right: 0; height: 70px; }
.doc-header .brand-row { display: table; width: 100%; }
.doc-header .brand-cell { display: table-cell; vertical-align: middle; }
.doc-header .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 10px; }
.doc-header .brand-line { border-bottom: 2px solid #5BA5A0; margin-top: 10px; }

{{-- Convención de labels de sección (SCRUM-853): gris discreto, uppercase, 9px — nunca teal/bold
     llamativo como el header de una tabla. --}}
.block-title { font-size: 9px; line-height: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; margin-top: 12px; }
.block-title:first-child { margin-top: 0; }
.block-value { font-size: 10px; line-height: 13px; white-space: pre-line; }

.signature { width: auto; margin: 48px 0 0 auto; padding-top: 4px; border-top: 1px solid #9ca3af; text-align: center; font-size: 9px; color: #6b7280; white-space: nowrap; }
.signature .signed-name { font-size: 15px; color: #2a2520; margin-bottom: 2px; }
