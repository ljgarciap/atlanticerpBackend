<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Services\OrderStatusService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-783 (rebote Daniela 2026-08-20): la Guía de Entrega ("Imprimir" en Pedidos) mostraba la
 * información montada/superpuesta con la tabla de ítems. Causa raíz: `.meta-block` en
 * `guia-entrega-pdf.blade.php` usaba position:absolute para las 2 columnas + un min-height fijo
 * adivinado (110px) — los hijos absolutos no aportan altura real al contenedor, así que el bloque
 * quedaba siempre en 110px sin importar cuántas líneas tuviera cada columna. Con los 6 campos
 * reales de la columna izquierda (Cliente/Proyecto/Dirección/Contacto/Teléfono/Correo) el bloque
 * desbordaba y "Correo" se montaba sobre el header de la tabla siguiente.
 *
 * PHPUnit no puede medir overlap visual real (eso lo cubrió la verificación con PDF real +
 * pdftoppm durante el fix) — este test es un guardrail estructural: si alguien reintroduce
 * position:absolute + min-height fijo en este bloque, falla acá.
 *
 * `estatus-pedido-pdf.blade.php` (documento "Estatus de Pedido") tenía el mismo patrón frágil,
 * encontrado al corregir SCRUM-783 pero sin rebote propio todavía (el min-height:100px original
 * apenas alcanzaba con 5 líneas + gap) — corregido preventivamente con el mismo mecanismo.
 */
class OrderDocumentPdfLayoutTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    public function test_guia_entrega_no_usa_position_absolute_con_min_height_fijo(): void
    {
        $owner = User::first();
        $order = $this->makeOrderWithPipelineCard($owner->id, [
            'courier_id'        => $owner->id,
            'delivery_address'  => 'Calle de prueba #123',
        ]);
        $order->pipelineCard->contacts()->update([
            'phone' => '6000-9999',
            'email' => 'contacto@example.test',
        ]);

        $html = view('bodega.guia-entrega-pdf', ['order' => $order->fresh([
            'items', 'courier', 'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject',
        ])])->render();

        // Guardrail estructural: el patrón que causó el bug (columnas position:absolute con un
        // alto fijo adivinado en el contenedor) no puede volver a este bloque.
        $this->assertStringNotContainsString('.meta-left { position: absolute', $html);
        $this->assertStringNotContainsString('.meta-right { position: absolute', $html);
        $this->assertStringContainsString('.meta-block { display: table', $html);

        // Orden visual preservado: Cliente sigue apareciendo antes que Guía n° (columna izquierda
        // primero en el DOM, ver nota en el blade sobre por qué display:table-cell exige esto).
        $clientePos = strpos($html, 'Cliente:');
        $guiaPos = strpos($html, 'Guía n');
        $this->assertNotFalse($clientePos);
        $this->assertNotFalse($guiaPos);
        $this->assertLessThan($guiaPos, $clientePos);

        // Los 6 campos de la columna izquierda (el caso real que desbordaba) están todos presentes.
        $this->assertStringContainsString('Correo:', $html);
        $this->assertStringContainsString('contacto@example.test', $html);
    }

    public function test_estatus_pedido_no_usa_position_absolute_con_min_height_fijo(): void
    {
        $owner = User::first();
        $order = $this->makeOrderWithPipelineCard($owner->id);
        $order->pipelineCard->contacts()->update(['phone' => '6000-9999']);

        $payload = app(OrderStatusService::class)->detailPayload($order->fresh());

        $html = view('bodega.estatus-pedido-pdf', ['status' => $payload])->render();

        $this->assertStringNotContainsString('.meta-left { position: absolute', $html);
        $this->assertStringNotContainsString('.meta-right { position: absolute', $html);
        $this->assertStringContainsString('.meta-block { display: table', $html);

        // Orden visual preservado: Proyecto (columna izquierda) antes que N° de pedido (columna
        // derecha) — mismo motivo que el test de arriba: table-cell sigue el orden del DOM.
        $proyectoPos = strpos($html, 'Proyecto:');
        $pedidoPos = strpos($html, 'N° de pedido:');
        $this->assertNotFalse($proyectoPos);
        $this->assertNotFalse($pedidoPos);
        $this->assertLessThan($pedidoPos, $proyectoPos);
    }
}
