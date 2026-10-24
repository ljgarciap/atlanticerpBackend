<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\ReceptorFiscalSnapshot;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use DateTimeImmutable;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1140 (T1) — receptor congelado en `admin_contab.invoices` y su DTO `ReceptorFiscalSnapshot`
 * (docs/specs/receptor-tipo-documento-al-facturar.md). Cubre las columnas nuevas, el round-trip modelo <-> DTO y que una
 * factura sin congelar (anterior al cambio) devuelva NULL para que el builder siga leyendo el cliente en vivo.
 */
class AdminContInvoiceReceptorSnapshotTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeInvoice(array $overrides = []): AdminContInvoice
    {
        $order = Order::create([
            'order_number'       => 'PED-2026-'.self::uniqueTestNumber(),
            'order_type'         => Order::ORDER_TYPE_PEDIDO,
            'stage'              => Order::STAGE_POR_DESPACHAR,
            'customer_name'      => 'Cliente receptor congelado',
            'sequence_in_family' => 1,
            'total_in_family'    => 1,
        ]);

        return AdminContInvoice::create([
            'numero'         => 'FAC-1140-'.self::uniqueTestNumber(),
            'order_id'       => $order->id,
            'cliente'        => 'Cliente receptor congelado',
            'monto'          => 100,
            'saldo_aplicado' => 0,
            ...$overrides,
        ])->fresh();
    }

    public function test_factura_anterior_al_cambio_no_tiene_receptor_congelado(): void
    {
        $invoice = $this->makeInvoice();

        $this->assertNull($invoice->receptor_tipo);
        $this->assertNull($invoice->receptor_ruc);
        $this->assertNull($invoice->receptor_dv);
        $this->assertNull($invoice->receptor_nombre);
        $this->assertNull($invoice->receptor_validado_at);
        $this->assertNull(ReceptorFiscalSnapshot::desdeFactura($invoice), 'NULL => el builder lee el cliente en vivo.');
    }

    public function test_contribuyente_validado_se_guarda_y_se_lee_de_vuelta(): void
    {
        $validadoAt = new DateTimeImmutable('2026-10-14 10:30:00');
        $snapshot   = ReceptorFiscalSnapshot::contribuyente('155704849-2-2021', '32', 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $validadoAt);

        $invoice = $this->makeInvoice($snapshot->comoAtributosDeFactura());

        $this->assertSame('01', $invoice->receptor_tipo);
        $this->assertSame('155704849-2-2021', $invoice->receptor_ruc);
        $this->assertSame('32', $invoice->receptor_dv);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $invoice->receptor_nombre);
        $this->assertNotNull($invoice->receptor_validado_at);

        $leido = ReceptorFiscalSnapshot::desdeFactura($invoice);
        $this->assertNotNull($leido);
        $this->assertSame('01', $leido->tipo);
        $this->assertSame('155704849-2-2021', $leido->ruc);
        $this->assertSame('32', $leido->dv);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $leido->nombre);
        $this->assertSame($validadoAt->format('Y-m-d H:i:s'), $leido->validadoAt?->format('Y-m-d H:i:s'));
        $this->assertFalse($leido->esConsumidorFinal());
    }

    public function test_cedula_sin_dv_guarda_dv_nulo(): void
    {
        $invoice = $this->makeInvoice(ReceptorFiscalSnapshot::contribuyente('8-123-456', null, 'JUAN PEREZ')->comoAtributosDeFactura());

        $this->assertSame('8-123-456', $invoice->receptor_ruc);
        $this->assertNull($invoice->receptor_dv);
        $this->assertNull($invoice->receptor_validado_at);
    }

    public function test_dv_vacio_se_normaliza_a_nulo(): void
    {
        $this->assertNull(ReceptorFiscalSnapshot::contribuyente('8-123-456', '  ', 'JUAN PEREZ')->dv);
    }

    public function test_consumidor_final_se_guarda_sin_documento_ni_validacion(): void
    {
        $invoice = $this->makeInvoice(ReceptorFiscalSnapshot::consumidorFinal()->comoAtributosDeFactura());

        $this->assertSame('02', $invoice->receptor_tipo);
        $this->assertNull($invoice->receptor_ruc);
        $this->assertNull($invoice->receptor_dv);
        $this->assertSame('CONSUMIDOR FINAL', $invoice->receptor_nombre);
        $this->assertNull($invoice->receptor_validado_at);

        $leido = ReceptorFiscalSnapshot::desdeFactura($invoice);
        $this->assertNotNull($leido);
        $this->assertTrue($leido->esConsumidorFinal());
    }

    public function test_to_fiscal_party_arma_el_receptor_que_viaja_al_pac(): void
    {
        $party = ReceptorFiscalSnapshot::contribuyente('155704849-2-2021', '32', 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA')
            ->toFiscalParty('compras@cliente.com', 'Calle 50');

        $this->assertSame('01', $party->tipo);
        $this->assertSame('155704849-2-2021', $party->ruc);
        $this->assertSame('32', $party->dv);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $party->nombre);
        $this->assertSame('compras@cliente.com', $party->email);
        $this->assertSame('Calle 50', $party->direccion);

        $cf = ReceptorFiscalSnapshot::consumidorFinal()->toFiscalParty('compras@cliente.com', 'Calle 50');
        $this->assertSame('02', $cf->tipo);
        $this->assertNull($cf->ruc);
        $this->assertNull($cf->dv);
        $this->assertNull($cf->email, 'Consumidor final no lleva email ni dirección del cliente.');
    }

    public function test_nombres_con_caracteres_especiales_hacen_round_trip(): void
    {
        foreach (['ACME & HIJOS, S.A.', 'PEÑA "EL GRANDE" Ñ, S.A.', str_repeat('RAZON SOCIAL LARGA ', 12)] as $nombre) {
            $invoice = $this->makeInvoice(ReceptorFiscalSnapshot::contribuyente('155704849-2-2021', '32', $nombre)->comoAtributosDeFactura());

            $this->assertSame($nombre, $invoice->receptor_nombre);
        }
    }
}
