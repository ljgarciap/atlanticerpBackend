<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Shared\Support\AbstractQuotePdfLayoutEstimator;

/**
 * SCRUM-824 — bloque final indivisible, pegado al pie de página, para la cotización de Servicios
 * (mismo comportamiento ya implementado en VentasDiseno\Services\QuotePdfLayoutEstimator para
 * SCRUM-801 — ver el docblock de esa clase y de {@see AbstractQuotePdfLayoutEstimator} para el
 * diseño completo del mecanismo y sus garantías de seguridad). Esta clase solo mide la estructura
 * real de `service-quote-pdf.blade.php`, que difiere de la de VentasDiseno en:
 * - Sin agrupamiento por partidas — la tabla de items es plana, 1 fila por ítem.
 * - Columna izquierda de Cliente/Proyecto siempre 3 líneas fijas (Cliente/Contacto/Dirección,
 *   sin lista variable de contactos como en VentasDiseno).
 * - "Descuento" siempre se muestra como fila (con "—" si es 0%), nunca condicional — comportamiento
 *   ya existente de Servicios que el ticket no pidió cambiar, solo el estilo visual.
 */
final class ServiceQuotePdfLayoutEstimator extends AbstractQuotePdfLayoutEstimator
{
    /** @page { margin: 95px 40px 60px 40px; } — idéntico a VentasDiseno (SCRUM-824 §1). */
    protected function marginTopPx(): float
    {
        return 95;
    }

    protected function marginBottomPx(): float
    {
        return 60;
    }

    /** Mismo valor calibrado que QuotePdfLayoutEstimator — ver ese docblock para el porqué. */
    protected function bufferPt(): float
    {
        return 100.0;
    }

    /** Servicios no tiene vista externa — el bloque final siempre se renderiza. */
    protected function hidesBottomBlockWhenExternal(): bool
    {
        return false;
    }

    protected function contentWidthPt(): float
    {
        return self::PAGE_W_PT - (2 * 40 * self::PX_TO_PT);
    }

    /** @param array<string, mixed> $quote */
    protected function page1PrefixHeight(array $quote): float
    {
        // .secondary-header-badge (line-height 17px) + .secondary-header-sub (line-height 13px,
        // margin-top 2px) + margin-bottom 10px del row + .divider (border 1px + margin 10px 0).
        $secondaryHeader = (17 + 2 + 13 + 10) * self::PX_TO_PT;
        $divider = (1 + 20) * self::PX_TO_PT;

        // .info-row: columna izquierda SIEMPRE 3 líneas fijas (Cliente/Contacto/Dirección — sin
        // lista variable de contactos como VentasDiseno), derecha 1 línea (Proyecto asociado).
        $lineH = 16 * self::PX_TO_PT;
        $infoRow = max(3 * $lineH, 1 * $lineH) + (12 * self::PX_TO_PT);

        return $secondaryHeader + $divider + $infoRow;
    }

    protected function theadRowHeight(): float
    {
        return $this->cellBoxHeight(padTopPx: 4, padBottomPx: 4, lineHeightPx: 10);
    }

    /** @param array<string, mixed> $quote */
    protected function rowHeights(array $quote): array
    {
        $rows = [];
        foreach ($quote['items'] ?? [] as $item) {
            $descLines = $this->wrappedLineCount(
                (string) ($item['description'] ?? ''),
                fontSizePx: 10,
                maxWidthPx: $this->descriptionColumnWidthPx(),
            );
            $rows[] = (4 + 4) * self::PX_TO_PT + max(1, $descLines) * (13 * self::PX_TO_PT);
        }

        return $rows;
    }

    /** @param array<string, mixed> $quote */
    protected function finalBlockHeight(array $quote): float
    {
        $h = 24 * self::PX_TO_PT; // .bottom-top margin-top

        $h += $this->blockTitleHeight(firstChild: true);
        $obsLines = $this->wrappedLineCount((string) ($quote['observations'] ?? ''), 10, $this->contentWidthPt() / self::PX_TO_PT);
        $h += max(1, $obsLines) * (13 * self::PX_TO_PT);

        $h += $this->blockTitleHeight(firstChild: false);
        $h += 1 * (13 * self::PX_TO_PT); // "Cheque a nombre de: ATLANTIC USA CORP"
        $achLines = $this->wrappedLineCount(
            'Pagos ACH: Cuenta 03-01-01-074455-4, cuenta corriente, Banco General',
            10,
            $this->contentWidthPt() / self::PX_TO_PT,
        );
        $h += max(1, $achLines) * (13 * self::PX_TO_PT);

        $h += 12 * self::PX_TO_PT; // .bottom-condiciones-row margin-top

        $condicionesColWidthPx = ($this->contentWidthPt() / self::PX_TO_PT / 2) - 16;
        $condLines = $this->wrappedLineCount((string) ($quote['conditions_text'] ?? ''), 10, $condicionesColWidthPx);
        $condicionesColH = $this->blockTitleHeight(firstChild: true) + max(1, $condLines) * (13 * self::PX_TO_PT);

        // Descuento SIEMPRE es una fila (con "—" si 0%) — a diferencia de VentasDiseno, acá no es
        // condicional (ver docblock de la clase). 3 filas fijas (Subtotal/Descuento/ITBMS) + grand-total.
        $totalsH = 3 * ((2 + 2) * self::PX_TO_PT + 14 * self::PX_TO_PT)
            + ((4 * self::PX_TO_PT) + (1 * self::PX_TO_PT) + 14 * self::PX_TO_PT);

        $signed = (bool) ($quote['firmada'] ?? false);
        $signatureH = (48 * self::PX_TO_PT) + (4 * self::PX_TO_PT) + (1 * self::PX_TO_PT)
            + ($signed ? (15 * 1.2 * self::PX_TO_PT) : 0.0)
            + (9 * 1.2 * self::PX_TO_PT);

        $totalsFirmaColH = $totalsH + $signatureH;

        $h += max($condicionesColH, $totalsFirmaColH);

        return $h;
    }

    private function blockTitleHeight(bool $firstChild): float
    {
        $marginTop = $firstChild ? 0 : 12;

        return ($marginTop + 11 + 3) * self::PX_TO_PT;
    }

    private function descriptionColumnWidthPx(): float
    {
        // col DESCRIPCIÓN: 52% del ancho de contenido (colgroup de service-quote-pdf.blade.php),
        // menos el padding horizontal de la celda (5px * 2).
        $contentWidthPx = $this->contentWidthPt() / self::PX_TO_PT;

        return ($contentWidthPx * 0.52) - 10;
    }
}
