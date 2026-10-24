<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * SCRUM-824 — extraída de `QuotePdfLayoutEstimator` (SCRUM-801, VentasDiseno) al agregar
 * `ServiceQuotePdfLayoutEstimator` (Servicios) con el mismo mecanismo: ambas plantillas de
 * cotización necesitan el mismo cálculo de "bloque final indivisible, pegado al pie de página",
 * y la aritmética de páginas/wrap de texto es idéntica — solo cambia QUÉ filas/secciones mide
 * cada una, no CÓMO se acumulan contra el presupuesto de una página. Ver el docblock original de
 * `QuotePdfLayoutEstimator` (conservado en la subclase de VentasDiseno) para el diseño completo
 * del mecanismo de seguridad (page-break-inside:avoid como garantía dura, esta clase como
 * estimación cosmética best-effort).
 *
 * Cada subclase concreta implementa la estructura real de SU plantilla (prefijo de página 1, alto
 * del thead, alto de cada fila de la tabla de items, alto del bloque final) — esta clase base solo
 * sabe acumular esas alturas contra el presupuesto de página y decidir spacer/forceNewPage.
 */
abstract class AbstractQuotePdfLayoutEstimator
{
    protected const FONT_FAMILY = 'DejaVu Sans';

    /** CSS "px" → puntos de canvas, dado DPI=96 (default de dompdf, sin config publicada en este proyecto). */
    protected const PX_TO_PT = 0.75;

    /** Paper letter, en puntos — constante física, no depende de DPI. */
    protected const PAGE_H_PT = 792.0;
    protected const PAGE_W_PT = 612.0;

    protected FontMetrics $fontMetrics;

    /** @var string Handle resuelto de la fuente, cacheado — resolver por-llamada era trabajo repetido. */
    protected string $font;

    public function __construct()
    {
        // Instancia descartable solo para acceder a las métricas reales de la fuente empaquetada
        // — mismo patrón ya usado por el `<script type="text/php">` del pie de página.
        $this->fontMetrics = (new Dompdf())->getFontMetrics();

        $font = $this->fontMetrics->getFont(self::FONT_FAMILY, 'normal');
        if ($font === null) {
            // No puede pasar con una fuente empaquetada de dompdf (DejaVu Sans) salvo instalación
            // rota — igual se guarda para que PHPStan (Level 8) no infiera string|null río abajo.
            throw new \RuntimeException('No se pudo resolver la fuente '.self::FONT_FAMILY.' en dompdf.');
        }
        $this->font = $font;
    }

    /**
     * @param array<string, mixed> $quote Misma forma que el `viewData()` del Service correspondiente.
     * @return array{spacerPt: float, forceNewPage: bool}
     */
    public function estimate(array $quote, bool $external): array
    {
        if ($external && $this->hidesBottomBlockWhenExternal()) {
            return ['spacerPt' => 0.0, 'forceNewPage' => false];
        }

        $remainingOnCurrentPage = $this->remainingSpaceAfterItems($quote);

        // SCRUM-986 — bloque indivisible que va entre la tabla de items y el bloque final (en
        // VentasDiseno, Firma|Totales). Si no cabe en lo que queda de la página pasa íntegro a la
        // siguiente, donde consume su propio alto desde el tope. Servicios devuelve 0 (sin cambio).
        $leadingH = $this->leadingBlockHeight($quote);
        if ($leadingH > 0.0) {
            $remainingOnCurrentPage = $leadingH <= $remainingOnCurrentPage
                ? $remainingOnCurrentPage - $leadingH
                : max(0.0, $this->contentHeightPt() - $leadingH);
        }
        $finalBlockH = $this->finalBlockHeight($quote);
        $contentH = $this->contentHeightPt();
        $buffer = $this->bufferPt();

        if ($finalBlockH <= $remainingOnCurrentPage) {
            return [
                'spacerPt' => max(0.0, $remainingOnCurrentPage - $finalBlockH - $buffer),
                'forceNewPage' => false,
            ];
        }

        return [
            'spacerPt' => max(0.0, $contentH - $finalBlockH - $buffer),
            'forceNewPage' => true,
        ];
    }

    /** @param array<string, mixed> $quote */
    private function remainingSpaceAfterItems(array $quote): float
    {
        $contentH = $this->contentHeightPt();
        $theadH = $this->theadRowHeight();
        $cursor = $this->page1PrefixHeight($quote) + $theadH;

        foreach ($this->rowHeights($quote) as $rowH) {
            $cursor = $this->consumeRow($cursor, $rowH, $theadH, $contentH);
        }

        return $contentH - $cursor;
    }

    /**
     * Avanza el cursor una fila; si no entra en lo que queda de la página actual, la fila pasa a
     * una página nueva (que arranca con el thead repetido). Caso borde real (descripción de ítem
     * pathológicamente larga, encontrado en desarrollo de SCRUM-801): si la fila SOLA mide más que
     * una página completa, resetear a `theadH + rowH` da un cursor inválido (> contentH) — se usa
     * aritmética modular sobre el espacio útil por página para encontrar dónde queda el cursor en
     * la ÚLTIMA página que esa fila ocupa, sin importar cuántas páginas consuma ella sola.
     */
    private function consumeRow(float $cursor, float $rowH, float $theadH, float $contentH): float
    {
        if ($cursor + $rowH <= $contentH) {
            return $cursor + $rowH;
        }

        $usablePerFreshPage = $contentH - $theadH;
        $cursorOnLastPage = $rowH > 0 ? fmod($rowH, $usablePerFreshPage) : 0.0;
        if ($cursorOnLastPage === 0.0 && $rowH > 0) {
            $cursorOnLastPage = $usablePerFreshPage;
        }

        return $theadH + $cursorOnLastPage;
    }

    protected function cellBoxHeight(int $padTopPx, int $padBottomPx, int $lineHeightPx): float
    {
        return ($padTopPx + $padBottomPx + $lineHeightPx) * self::PX_TO_PT;
    }

    /**
     * Cuenta cuántas líneas ocupará $text si se envuelve a $maxWidthPx (ambos en unidades CSS
     * px), midiendo con la fuente real empaquetada de dompdf. Procesa párrafo por párrafo (split
     * por \n real, `white-space: pre-line` en los bloques de texto libre) para no perder los
     * saltos explícitos que ya trae el texto de Observaciones/Condiciones.
     */
    protected function wrappedLineCount(string $text, int $fontSizePx, float $maxWidthPx): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $fontSizePt = $fontSizePx * self::PX_TO_PT;
        $maxWidthPt = $maxWidthPx * self::PX_TO_PT;

        $totalLines = 0;

        foreach (explode("\n", $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $totalLines += 1;
                continue;
            }

            $lines = 1;
            $currentLineWidth = 0.0;

            $words = preg_split('/\s+/', $paragraph) ?: [];
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                $wordWidth = $this->fontMetrics->getTextWidth($word.' ', $this->font, $fontSizePt);

                if ($currentLineWidth > 0 && $currentLineWidth + $wordWidth > $maxWidthPt) {
                    $lines++;
                    $currentLineWidth = $wordWidth;
                    continue;
                }

                $currentLineWidth += $wordWidth;
            }

            $totalLines += $lines;
        }

        return $totalLines;
    }

    /** @page margin-top + margin-bottom en px CSS — cada subclase define su propio @page real. */
    abstract protected function marginTopPx(): float;

    abstract protected function marginBottomPx(): float;

    protected function contentHeightPt(): float
    {
        return self::PAGE_H_PT - ($this->marginTopPx() + $this->marginBottomPx()) * self::PX_TO_PT;
    }

    /** Margen de seguridad conservador de la estimación — ver docblock de la clase. */
    abstract protected function bufferPt(): float;

    /** true si la vista externa nunca renderiza el bloque final (política SCRUM-140 en VentasDiseno; Servicios no tiene vista externa, devuelve false). */
    abstract protected function hidesBottomBlockWhenExternal(): bool;

    /** @param array<string, mixed> $quote */
    abstract protected function page1PrefixHeight(array $quote): float;

    abstract protected function theadRowHeight(): float;

    /**
     * Lista de alturas de fila de la tabla de items, EN ORDEN — incluye cualquier estructura
     * propia de la plantilla (ej. filas de encabezado/total de partida en VentasDiseno); en
     * Servicios (tabla plana, sin agrupamiento) es simplemente una altura por item.
     *
     * @param array<string, mixed> $quote
     * @return list<float>
     */
    abstract protected function rowHeights(array $quote): array;

    /**
     * Alto de un bloque indivisible ubicado entre la tabla de items y el bloque final. Default 0:
     * las plantillas que no lo tienen (Servicios) no cambian de comportamiento.
     *
     * @param array<string, mixed> $quote
     */
    protected function leadingBlockHeight(array $quote): float
    {
        return 0.0;
    }

    /** @param array<string, mixed> $quote */
    abstract protected function finalBlockHeight(array $quote): float;
}
