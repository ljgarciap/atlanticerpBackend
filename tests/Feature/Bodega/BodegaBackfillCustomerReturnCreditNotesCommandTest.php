<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-865 punto 2 — cubre `bodega:backfill-customer-return-credit-notes`: rellena
 * `customer_returns.credit_note_id` para devoluciones finalizadas ANTES de que
 * `finalizeForCreditNote()` empezara a persistirlo (rebote de marly.rangel 2026-09-09).
 */
class BodegaBackfillCustomerReturnCreditNotesCommandTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    /** `$this->artisan()` corre el comando, que hace Tenant::forgetCurrent() al salir — hay que
     *  reactivar el tenant antes de cualquier query directa (search_path) en las aserciones. */
    private function backfill(string $extra = ''): void
    {
        $this->artisan(trim('bodega:backfill-customer-return-credit-notes '.$extra))->assertSuccessful();
        $this->makeCurrentTenant();
    }

    private function makeFinalizedReturn(): CustomerReturn
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO]);

        return CustomerReturn::create([
            'return_number' => 'DEV-2026-'.static::uniqueTestNumber(),
            'order_id'      => $order->id,
            'status'        => CustomerReturn::STATUS_FINALIZADO,
            'finalized_at'  => now(),
            'contact_name'  => 'Persona que devuelve',
            'contact_phone' => '6000-0000',
            'created_by'    => $this->userIdByEmail('designer@atlantic.test'),
            'credit_note_id' => null,
        ]);
    }

    private function makeCreditNoteFor(CustomerReturn $return, string $estado = AdminContCreditNote::ESTADO_APLICADA): AdminContCreditNote
    {
        $master  = MasterClient::create(['name' => 'Cliente NC '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => (int) static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => 'Cliente NC', 'monto' => 100, 'saldo_aplicado' => 0,
        ]);

        return AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => 'Cliente NC',
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $invoice->id,
            'monto' => 100, 'subtotal' => 100, 'itbms' => 0, 'motivo' => 'Devolución de prueba',
            'estado' => $estado, 'registrado_por' => $this->userIdByEmail('contabilidad@test.com'),
            'fecha' => now()->toDateString(), 'devolucion_bodega_id' => $return->id,
        ]);
    }

    public function test_backfillea_una_devolucion_finalizada_con_su_nota_de_credito(): void
    {
        $return = $this->makeFinalizedReturn();
        $note   = $this->makeCreditNoteFor($return);

        $this->backfill();

        $this->assertSame($note->id, $return->fresh()->credit_note_id);
    }

    public function test_dry_run_no_escribe(): void
    {
        $return = $this->makeFinalizedReturn();
        $this->makeCreditNoteFor($return);

        $this->backfill('--dry-run');

        $this->assertNull($return->fresh()->credit_note_id);
    }

    public function test_no_pisa_un_credit_note_id_ya_seteado(): void
    {
        $return = $this->makeFinalizedReturn();
        $note   = $this->makeCreditNoteFor($return);
        $return->update(['credit_note_id' => 999999]);

        $this->backfill();

        $this->assertSame(999999, $return->fresh()->credit_note_id);
        $this->assertNotSame($note->id, $return->fresh()->credit_note_id);
    }

    public function test_ignora_una_nota_de_credito_rechazada(): void
    {
        $return = $this->makeFinalizedReturn();
        $this->makeCreditNoteFor($return, AdminContCreditNote::ESTADO_RECHAZADA);

        $this->backfill();

        $this->assertNull($return->fresh()->credit_note_id);
    }

    public function test_no_toca_devoluciones_que_no_estan_finalizadas(): void
    {
        $return = $this->makeFinalizedReturn();
        $this->makeCreditNoteFor($return);
        $return->update(['status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO]);

        $this->backfill();

        $this->assertNull($return->fresh()->credit_note_id);
    }
}
