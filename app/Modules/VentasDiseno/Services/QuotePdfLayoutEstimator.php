<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Shared\Support\AbstractQuotePdfLayoutEstimator;

/**
 * SCRUM-801 (3er rebote, 2026-09-01) — Daniela pidió que Observaciones+Instrucciones de
 * pago+Condiciones+Totales+Firma se comporten como UN bloque indivisible, pegado al pie de la
 * página que lo contiene (con el espacio en blanco sobrante ANTES del bloque, no después).
 *
 * dompdf no expone la posición Y del cursor a mitad de render — no hay forma de preguntarle
 * "cuánto espacio queda en esta página" durante el layout. La única vía es simular nosotros, en
 * PHP y ANTES de renderizar, cuánto va a medir cada fila (misma técnica que ya usa el pie de
 * página de esta plantilla para centrar texto: `FontMetrics::getTextWidth()` sobre una instancia
 * descartable de Dompdf — no es un mecanismo nuevo, es el mismo ya probado en producción).
 *
 * DISEÑO DE SEGURIDAD (por qué esto no puede reintroducir el bug de paginación del 31-ago, cuando
 * un intento con flexbox convirtió 1 página en 2): esta clase decide DOS cosas por separado —
 * (a) si el bloque final entra en el espacio restante de la página actual o necesita una página
 * nueva, y (b) cuántos puntos de espaciador poner antes del bloque para que quede pegado al pie.
 * Un error de estimación en (b) produce, en el peor caso, un espaciador un poco corto o un poco
 * largo — un defecto COSMÉTICO. La garantía dura de "nunca partir el bloque" NO depende de que
 * esta clase acierte: en la vista, el bloque completo vuelve a envolverse en
 * `page-break-inside: avoid`, que dompdf sí respeta de forma nativa y confiable para bloques sin
 * tablas anidadas partibles — así que aunque `estimate()` se equivoque, el bloque no se corte a
 * mitad de página nunca. El sesgo de {@see bufferPt()} es siempre conservador (resta margen, nunca
 * suma) para que un error de estimación empuje hacia "queda en la página actual con un poco menos
 * de aire arriba", no hacia "se desborda".
 *
 * ⚠️ Fragilidad conocida y aceptada: las constantes de abajo (paddings, tamaños de fuente,
 * line-heights) son una copia mental de `quote-pdf.blade.php` — si se toca el CSS de esa vista sin
 * actualizar esta clase, el espaciador se desincroniza (el bloque sigue sin partirse nunca, solo
 * el aire antes de él deja de ser exacto). Mismo tipo de acoplamiento que el pie de página ya
 * acepta al centrar su texto — no hay forma de eliminarlo del todo con el motor de dompdf.
 *
 * SCRUM-986 (2026-10-02) — Totales y Firma salieron de este bloque: ahora forman un bloque
 * indivisible propio (`leadingBlockHeight()`) inmediatamente bajo el último ítem; el bloque final
 * (Observaciones→Condiciones) se mide sin ellos y se ancla al pie DESPUÉS de ese bloque.
 *
 * SCRUM-824 (2026-09-01) — la aritmética genérica de página/wrap/spacer se extrajo a
 * {@see AbstractQuotePdfLayoutEstimator}, reusada también por `ServiceQuotePdfLayoutEstimator`
 * (Servicios) — esta clase solo implementa la estructura real de ESTA plantilla.
 */
final class QuotePdfLayoutEstimator extends AbstractQuotePdfLayoutEstimator
{
    /** Alto medido de una fila de ítem de una línea sin foto — ver itemRowHeight(). */
    private const NO_PHOTO_ROW_PT = 19.25;

    /** Holgura (pt) sumada al alto estimado del bloque Firma|Totales — ver leadingBlockHeight(). */
    private const LEADING_SAFETY_PT = 20.0;

    /** @page { margin: 95px 40px 60px 40px; } */
    protected function marginTopPx(): float
    {
        return 95;
    }

    protected function marginBottomPx(): float
    {
        return 60;
    }

    /**
     * Margen de seguridad conservador — ver docblock de la clase. Calibrado empíricamente contra
     * un render real de dompdf (no un valor arbitrario): con un buffer chico (18pt, ~1.5 líneas),
     * una cotización de tamaño típico que SÍ cabía en 1 página terminaba empujada a 2 — la
     * simulación en PHP subestima el alto real por la acumulación de decenas de aproximaciones
     * (line-heights por defecto, bordes de tabla, redondeo de fuente) en suficiente cantidad como
     * para importar. 100pt (~8 líneas de cuerpo) es el valor mínimo que devolvió a 1 página el
     * caso de referencia usado en el desarrollo — vale como piso, no como techo exacto.
     */
    protected function bufferPt(): float
    {
        return 100.0;
    }

    /** El bloque final nunca se renderiza en la vista externa (política SCRUM-140) — no hay nada que anclar. */
    protected function hidesBottomBlockWhenExternal(): bool
    {
        return true;
    }

    protected function contentWidthPt(): float
    {
        return self::PAGE_W_PT - (2 * 40 * self::PX_TO_PT);
    }

    /** @param array<string, mixed> $quote */
    protected function page1PrefixHeight(array $quote): float
    {
        // .secondary-header-row (badge line-height 17px + margin-bottom 10px) + .divider
        // (border 1px + margin 10px 0) — ver estilos agregados en quote-pdf.blade.php.
        $secondaryHeader = (17 + 10) * self::PX_TO_PT;
        $divider = (1 + 20) * self::PX_TO_PT;

        // .info-row: 2 columnas, la fila mide lo que mida la más alta. Derecha siempre 4 líneas
        // fijas (Proyecto/Cotización/Fecha/Diseñador). Izquierda: Cliente/Subcliente/RUC (3) +
        // N contactos (mínimo 1 línea, "Sin contactos").
        $lineH = 16 * self::PX_TO_PT;
        $rightColH = 4 * $lineH;
        $contactLines = max(1, count($quote['contacts'] ?? []));
        $leftColH = (3 + $contactLines) * $lineH;
        $infoRow = max($leftColH, $rightColH) + (12 * self::PX_TO_PT);

        return $secondaryHeader + $divider + $infoRow;
    }

    protected function theadRowHeight(): float
    {
        // table.items th { padding: 4px 5px; line-height: 10px; }
        return $this->cellBoxHeight(padTopPx: 4, padBottomPx: 4, lineHeightPx: 10);
    }

    /** @param array<string, mixed> $quote */
    protected function rowHeights(array $quote): array
    {
        $rows = [];
        foreach ($quote['parts'] ?? [] as $part) {
            $rows[] = $this->partHeaderRowHeight($part);
            foreach ($part['items'] ?? [] as $item) {
                $rows[] = $this->itemRowHeight($item);
            }
            $rows[] = $this->partTotalRowHeight();
        }

        return $rows;
    }

    /** @param array<string, mixed> $part */
    private function partHeaderRowHeight(array $part): float
    {
        // tr.part-header td { padding: 6px 5px; font-size: 10.5px; } — asumimos 1 línea (el
        // nombre de partida no tiene ancho angosto). Caso extremo de nombre larguísimo no cubierto
        // — documentado como límite conocido, el buffer de la clase lo absorbe parcialmente.
        return $this->cellBoxHeight(padTopPx: 6, padBottomPx: 6, lineHeightPx: 13);
    }

    /** @param array<string, mixed> $item */
    private function itemRowHeight(array $item): float
    {
        $descLines = $this->wrappedLineCount(
            (string) ($item['description'] ?? ''),
            fontSizePx: 10,
            maxWidthPx: $this->descriptionColumnWidthPx(),
        );

        // + 1px: table.items td { border-bottom: 1px solid } (SCRUM-986: no estaba contado y se
        // acumulaba ~0.75pt por fila, desplazando la estimación hacia arriba en tablas largas).
        $textH = (4 + 4 + 1) * self::PX_TO_PT + $descLines * (13 * self::PX_TO_PT);
        // Sin foto la celda igual muestra el placeholder "Sin foto" (8px sobre un strut de
        // 13px, vertical-align middle): pitch medido en un render real de dompdf = 19.25pt por
        // fila de una línea (no 16.5pt como daba solo texto+padding). Con foto: 42px + padding.
        $photoH = empty($item['photo_url']) ? self::NO_PHOTO_ROW_PT : (4 + 4 + 42 + 1) * self::PX_TO_PT;

        return max($textH, $photoH);
    }

    private function partTotalRowHeight(): float
    {
        // tr.part-total td { padding: 4px 5px 10px 5px; font-size: 10.5px; }
        return (4 + 10) * self::PX_TO_PT + 13 * self::PX_TO_PT;
    }

    /**
     * SCRUM-986 — bloque Firma|Totales, inmediatamente tras la tabla de items. Mide el mayor de
     * las dos columnas (arrancan a la misma altura) + .sig-totals-block { margin-top: 12px }.
     *
     * @param array<string, mixed> $quote
     */
    protected function leadingBlockHeight(array $quote): float
    {
        $totalsRows = 2 + (($quote['discount_totals_amount'] ?? 0) > 0 ? 1 : 0); // Subtotal+ITBMS(+Descuento) + grand-total aparte
        $totalsH = $totalsRows * ((2 + 2) * self::PX_TO_PT + 14 * self::PX_TO_PT)
            + ((4 * self::PX_TO_PT) + (1 * self::PX_TO_PT) + 14 * self::PX_TO_PT); // fila grand-total, borde+padding extra

        // .signature { margin-top: 28px; padding-top: 4px; border-top: 1px; font-size: 9px }
        $signed = ($quote['document_status'] ?? null) === 'approved';
        $signatureH = (28 * self::PX_TO_PT) + (4 * self::PX_TO_PT) + (1 * self::PX_TO_PT)
            + ($signed ? (15 * 1.2 * self::PX_TO_PT) : 0.0)
            + (9 * 1.2 * self::PX_TO_PT);

        // + LEADING_SAFETY_PT: sesgo conservador. Si la estimación dice "cabe" y en realidad no, el
        // bloque salta solo a una página nueva y el bloque final (forzado a página nueva por la
        // estimación) queda en una 3ra página casi vacía; con margen el error cae del lado inocuo.
        return (12 * self::PX_TO_PT) + max($totalsH, $signatureH) + self::LEADING_SAFETY_PT;
    }

    /**
     * Bloque final SIN Totales/Firma (SCRUM-986): Observaciones + Instrucciones de pago +
     * Condiciones.
     *
     * @param array<string, mixed> $quote
     */
    protected function finalBlockHeight(array $quote): float
    {
        // .bottom-top { margin-top: 24px; }
        $h = 24 * self::PX_TO_PT;

        // Observaciones — .block-title:first-child (sin margin-top) + .block-value
        $h += $this->blockTitleHeight(firstChild: true);
        $obsLines = $this->wrappedLineCount((string) ($quote['observations_preview'] ?? ''), 10, $this->contentWidthPt() / self::PX_TO_PT);
        $h += max(1, $obsLines) * (13 * self::PX_TO_PT);

        // Instrucciones de pago — 2 líneas fijas, pero la 2da (cuenta ACH) puede envolver.
        $h += $this->blockTitleHeight(firstChild: false);
        $h += 1 * (13 * self::PX_TO_PT); // "Cheque a nombre de: ATLANTIC USA CORP"
        $achLines = $this->wrappedLineCount(
            'Pagos ACH: Cuenta 03-01-01-074455-4, cuenta corriente, Banco General',
            10,
            $this->contentWidthPt() / self::PX_TO_PT,
        );
        $h += max(1, $achLines) * (13 * self::PX_TO_PT);

        // .bottom-condiciones-row { margin-top: 12px; } — columna Condiciones al 50% - padding-right
        $h += 12 * self::PX_TO_PT;
        $condicionesColWidthPx = ($this->contentWidthPt() / self::PX_TO_PT / 2) - 16;
        $condLines = $this->wrappedLineCount((string) ($quote['conditions_text'] ?? ''), 10, $condicionesColWidthPx);
        $h += $this->blockTitleHeight(firstChild: true) + max(1, $condLines) * (13 * self::PX_TO_PT);

        return $h;
    }

    private function blockTitleHeight(bool $firstChild): float
    {
        // .block-title { font-size: 9px; margin-bottom: 3px; margin-top: 12px; line-height: 11px; }
        // .block-title:first-child { margin-top: 0; }
        $marginTop = $firstChild ? 0 : 12;

        return ($marginTop + 11 + 3) * self::PX_TO_PT;
    }

    private function descriptionColumnWidthPx(): float
    {
        // table.items col DESCRIPCIÓN: 33% interna (58% externa, pero el bloque final nunca se
        // renderiza en la vista externa — ver estimate()). Ancho de contenido en px CSS, menos
        // el padding horizontal de la celda (5px * 2).
        $contentWidthPx = $this->contentWidthPt() / self::PX_TO_PT;

        return ($contentWidthPx * 0.33) - 10;
    }
}
