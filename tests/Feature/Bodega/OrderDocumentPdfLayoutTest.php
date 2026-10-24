<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Services\OrderDocumentService;
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

    /**
     * SCRUM-894 — caso real #PED-2026-0049: tarjeta con entrega parcial donde BARRA-COC-601 quedó
     * en `qty_delivered=0` (sin stock en Picking/Packing), mientras otro producto de la misma
     * tarjeta sí tenía 1 unidad a entregar. La guía de ESTA tarjeta debía reflejar solo lo
     * realmente a entregar en ella, nunca la cantidad total pedida originalmente
     * (`qty_requested`) — antes de este fix, el fallback `qty_delivered > 0 ? ... : qty_requested`
     * hacía que BARRA-COC-601 apareciera con cantidad 1 en la guía impresa, igual que los demás
     * productos, pese a estar en 0.
     */
    public function test_guia_entrega_no_usa_qty_requested_como_fallback_para_item_sin_stock(): void
    {
        $owner = User::first();
        $order = $this->makeOrderWithPipelineCard($owner->id, [], [
            'reference_snapshot'   => 'BARRA-COC-601',
            'description_snapshot' => 'Barra de cocina 601',
            'qty_requested'        => 1,
            'qty_picked'           => 0,
            'qty_delivered'        => 0,
        ]);
        $order->items()->create([
            'reference_snapshot'   => 'OTRO-PRODUCTO-002',
            'description_snapshot' => 'Otro producto de la misma tarjeta',
            'qty_requested'        => 1,
            'qty_picked'           => 1,
            'qty_delivered'        => 1,
        ]);

        $html = view('bodega.guia-entrega-pdf', ['order' => $order->fresh([
            'items', 'courier', 'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject',
        ])])->render();

        // El producto sin stock en ESTA tarjeta nunca aparece en la tabla de la guía.
        $this->assertStringNotContainsString('BARRA-COC-601', $html);

        // El producto que sí tenía 1 unidad a entregar en esta tarjeta sí aparece, con su cantidad real.
        $this->assertStringContainsString('OTRO-PRODUCTO-002', $html);
    }

    /**
     * SCRUM-894 (rebote Daniela, 2026-09-13) — el fix anterior (test de arriba) solo probaba
     * `generateGuiaEntrega()` con un `$order->fresh([...])` recién cargado, así que nunca
     * reprodujo el bug real: `DeliveryService::registerDelivery()` carga `items` ANTES de
     * actualizar `qty_delivered` de cada item vía instancias de Eloquent separadas
     * (`$order->items()->lockForUpdate()->findOrFail($id)->update(...)`), lo que NUNCA refresca
     * la colección `items` ya cacheada en `$order` desde antes — y `generateGuiaEntrega()` usaba
     * `loadMissing()`, que no vuelve a consultar una relación que Eloquent ya considera cargada.
     * Resultado real: la guía se generaba con los `qty_delivered` viejos (0 para todo item recién
     * llegado de Packing) → documento descargado sin ningún producto, exactamente lo reportado.
     *
     * Este test reproduce esa secuencia exacta de object-lifecycle (loadMissing ANTES de la
     * actualización externa, igual que la línea `$locked->loadMissing('items')` en
     * `DeliveryService::registerDelivery()`) para blindar contra que alguien vuelva a cambiar
     * `load()` por `loadMissing()` en `OrderDocumentService::generateGuiaEntrega()`.
     */
    public function test_generar_guia_entrega_refresca_items_aunque_ya_estuvieran_cargados_stale_en_el_order(): void
    {
        $owner = User::first();
        $order = $this->makeOrderWithPipelineCard($owner->id, [], [
            'reference_snapshot'   => 'COLG-COC-602',
            'description_snapshot' => 'Colgante cocina 602',
            'qty_requested'        => 10,
            'qty_picked'           => 10,
            'qty_delivered'        => 0,
        ]);
        $item1 = $order->items->first();
        $item2 = $order->items()->create([
            'reference_snapshot'   => 'BARRA-COC-601',
            'description_snapshot' => 'Barra cocina 601',
            'qty_requested'        => 10,
            'qty_picked'           => 0,
            'qty_delivered'        => 0,
        ]);

        // Snapshot STALE — mismo punto del flujo real que `$locked->loadMissing('items')` en
        // `DeliveryService::registerDelivery()`, ANTES de que el "loop de entrega" actualice cada
        // item vía una instancia separada.
        $order->loadMissing('items');

        // Simula el loop de `registerDelivery()`: cada item se actualiza vía una instancia
        // DISTINTA de Eloquent, nunca la ya cacheada en `$order->items`.
        OrderItem::whereKey($item1->id)->update(['qty_delivered' => 10]);
        OrderItem::whereKey($item2->id)->update(['qty_delivered' => 0]);

        app(OrderDocumentService::class)->generateGuiaEntrega($order, $owner);

        // La relación `items` de este MISMO objeto `$order` (el que se le pasó al servicio) debe
        // reflejar los valores frescos tras generar el documento, no el snapshot stale de arriba.
        $this->assertSame(10, $order->items->firstWhere('id', $item1->id)?->qty_delivered);
        $this->assertSame(0, $order->items->firstWhere('id', $item2->id)?->qty_delivered);
    }

    /**
     * SCRUM-887 (3er rebote, 2026-09-16) — `.delivery-data .line:first-child` nunca aplicaba: el
     * primer hijo real de `.delivery-data` es `.title` ("Datos de entrega"), no el primer `.line`,
     * así que el selector `:first-child` (que exige AMBAS condiciones a la vez) jamás matcheaba —
     * "Recibido por" y "Firma y fecha/hora" seguían con el margin-bottom base de 16px pese a que
     * el fix anterior decía haber aplicado 44px. Fix real: `.title + .line` (hermano adyacente).
     * Segundo hallazgo del mismo rebote: las 2 columnas del encabezado (`.meta-left`/`.meta-right`)
     * quedaban desalineadas a propósito desde SCRUM-783 (offset con padding-top:34px) — ahora
     * deben alinearse en su parte superior como una sola sección.
     */
    public function test_guia_entrega_espaciado_de_firma_y_alineacion_de_encabezado(): void
    {
        $owner = User::first();
        $order = $this->makeOrderWithPipelineCard($owner->id, [
            'courier_id' => $owner->id,
        ]);

        $html = view('bodega.guia-entrega-pdf', ['order' => $order->fresh([
            'items', 'courier', 'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject',
        ])])->render();

        // Guardrail estructural: el selector roto no puede volver, y el reemplazo (que sí
        // matchea) debe estar presente con el valor de espaciado correcto.
        $this->assertStringNotContainsString('.delivery-data .line:first-child', $html);
        $this->assertStringContainsString('.delivery-data .title + .line { margin-bottom: 44px; }', $html);

        // Las columnas del encabezado ya no llevan el offset que las desalineaba.
        $this->assertStringNotContainsString('padding-top: 34px', $html);
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
