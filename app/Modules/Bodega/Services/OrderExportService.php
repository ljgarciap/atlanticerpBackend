<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * SCRUM-390 (REQ-320, RN1: disponible tanto en la Hoja de Picking como en la tabla de detalle de
 * artículos del modal "Ver detalle") — export a `.xlsx` server-side con `phpoffice/phpspreadsheet`,
 * ya dependencia del proyecto (hoy usado solo para LEER en
 * `App\Modules\Compras\Services\InvoiceSpreadsheetExtractor` — acá es la dirección inversa,
 * ESCRIBIR un archivo para descargar; no hay un service de export previo en el codebase que
 * replicar 1:1, la estructura Spreadsheet -> hoja -> filas -> `Writer\Xlsx` sigue las convenciones
 * propias del paquete).
 *
 * Ambos métodos leen exactamente los mismos datos que ya expone su endpoint JSON gemelo —
 * `pickingSheetXlsx()` == `OrderPickingController::pickingSheet()`, `itemDetailXlsx()` ==
 * los `items` de `OrderBoardService::detail()` — el export nunca es una fuente de verdad
 * independiente, solo una representación distinta de la misma data.
 */
final class OrderExportService
{
    /** REQ-320 — mismos datos que `OrderPickingController::pickingSheet()`, orden por ubicación (REQ-315). */
    public function pickingSheetXlsx(Order $order): string
    {
        $order->loadMissing('items.catalogProduct');
        $items = $order->items->sortBy(fn (OrderItem $i) => $i->location_snapshot ?? '')->values();

        $headers = ['Referencia', 'Descripción', 'Ubicación', 'Cant. solicitada', 'Cant. recolectada'];
        $rows    = array_values($items->map(function (OrderItem $i): array {
            /** @var CatalogProduct|null $product */
            $product = $i->catalogProduct;

            return [
                // SCRUM-237/240 — `description_snapshot` sigue siendo el nombre visible que se
                // capturó al crear la línea (columna propia de OrderItem, sin cambios); el
                // fallback al producto EN VIVO pasa de `description` (ahora vacía tras el split
                // name/description) a `name`, o esta columna quedaría en blanco para cualquier
                // producto todavía vinculado.
                $product !== null ? $product->reference : $i->reference_snapshot,
                $product !== null ? $product->name : $i->description_snapshot,
                $i->location_snapshot,
                $i->qty_requested,
                $i->qty_picked,
            ];
        })->all());

        return $this->buildXlsx("Picking {$order->order_number}", $headers, $rows);
    }

    /** REQ-320 — mismos datos que los `items` de `OrderBoardService::detail()` (modal "Ver detalle"). */
    public function itemDetailXlsx(Order $order): string
    {
        $order->loadMissing('items.catalogProduct');

        $headers = ['Referencia', 'Descripción', 'Ubicación', 'Solicitado', 'Reservado', 'Recolectado', 'Entregado'];
        $rows    = array_values($order->items->map(function (OrderItem $i): array {
            /** @var CatalogProduct|null $product */
            $product = $i->catalogProduct;

            return [
                // SCRUM-237/240 — `description_snapshot` sigue siendo el nombre visible que se
                // capturó al crear la línea (columna propia de OrderItem, sin cambios); el
                // fallback al producto EN VIVO pasa de `description` (ahora vacía tras el split
                // name/description) a `name`, o esta columna quedaría en blanco para cualquier
                // producto todavía vinculado.
                $product !== null ? $product->reference : $i->reference_snapshot,
                $product !== null ? $product->name : $i->description_snapshot,
                $i->location_snapshot,
                $i->qty_requested,
                $i->qty_reserved,
                $i->qty_picked,
                $i->qty_delivered,
            ];
        })->all());

        return $this->buildXlsx("Detalle {$order->order_number}", $headers, $rows);
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    private function buildXlsx(string $sheetTitle, array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // Nombres de hoja de Excel: máx 31 caracteres, sin : \ / ? * [ ] — sanitizado defensivo,
        // `order_number` es texto libre en la práctica (formato `PED-2026-####` hoy, sin validación
        // que impida otros caracteres a futuro).
        $safeTitle = (string) preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $sheetTitle);
        $sheet->setTitle(substr($safeTitle, 0, 31));

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'order_export_');
        if ($tmpPath === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal para el export a Excel.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tmpPath);
            $bytes = file_get_contents($tmpPath);

            if ($bytes === false) {
                throw new \RuntimeException('No se pudo leer el archivo Excel generado.');
            }

            return $bytes;
        } finally {
            @unlink($tmpPath);
        }
    }
}
