<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-390 (REQ-320 RN1) — export a Excel de la Hoja de Picking y de la tabla de detalle. */
class OrderExportControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('almacen@atlantic.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    /** @return array<int, array<int, mixed>> filas leídas de la hoja activa, header incluido */
    private function readXlsxRows(string $bytes): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tmpPath, $bytes);

        try {
            $sheet = IOFactory::createReader('Xlsx')->load($tmpPath)->getActiveSheet();

            return $sheet->toArray();
        } finally {
            @unlink($tmpPath);
        }
    }

    public function test_export_de_hoja_de_picking_devuelve_xlsx_con_los_items(): void
    {
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING],
            ['qty_requested' => 5, 'qty_picked' => 3, 'location_snapshot' => 'A-01', 'reference_snapshot' => 'REF-X'],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->get("/api/bodega/orders/{$order->id}/picking-sheet/export");

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        $rows = $this->readXlsxRows($response->streamedContent());
        $this->assertSame(['Referencia', 'Descripción', 'Ubicación', 'Cant. solicitada', 'Cant. recolectada'], $rows[0]);
        $this->assertSame('REF-X', $rows[1][0]);
        $this->assertSame(5, (int) $rows[1][3]);
        $this->assertSame(3, (int) $rows[1][4]);
    }

    public function test_export_de_detalle_de_items_devuelve_xlsx_con_todas_las_cantidades(): void
    {
        $order = $this->makeOrder([], [
            'qty_requested' => 8, 'qty_reserved' => 6, 'qty_picked' => 4, 'qty_delivered' => 2,
            'reference_snapshot' => 'REF-DET',
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->get("/api/bodega/orders/{$order->id}/items/export");

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));

        $rows = $this->readXlsxRows($response->streamedContent());
        $this->assertSame(['Referencia', 'Descripción', 'Ubicación', 'Solicitado', 'Reservado', 'Recolectado', 'Entregado'], $rows[0]);
        $this->assertSame('REF-DET', $rows[1][0]);
        $this->assertSame([8, 6, 4, 2], array_map('intval', array_slice($rows[1], 3)));
    }

    public function test_export_de_pedido_inexistente_retorna_404_en_ambos_endpoints(): void
    {
        $headers = $this->authHeadersForBodegaReader();

        $this->withHeaders($headers)->get('/api/bodega/orders/999999/picking-sheet/export')->assertStatus(404);
        $this->withHeaders($headers)->get('/api/bodega/orders/999999/items/export')->assertStatus(404);
    }

    public function test_export_sin_permiso_bodega_read_retorna_403(): void
    {
        $order = $this->makeOrder();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/bodega/orders/{$order->id}/picking-sheet/export")
            ->assertStatus(403);
    }
}
