<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceFolioCounter;
use App\Modules\AdminCont\Services\AdminContInvoiceFolioService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 13 (2.5) — el folio de factura se reconcilia con las facturas que ya existen: una factura insertada fuera del servicio (importación,
 * restauración de un respaldo) ya no deja el contador atrás ni hace chocar el siguiente alta con la unicidad del número.
 */
class AdminContInvoiceFolioReconciliacionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContInvoiceFolioCounter::updateOrCreate(['id' => 1], ['last_number' => 0]);
    }

    private function facturaConNumero(string $numero): void
    {
        $master = MasterClient::firstOrCreate(['name' => 'Cliente folios']);
        AdminContInvoice::create(['numero' => $numero, 'order_id' => random_int(100000, 999999), 'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 10]);
    }

    private function siguiente(): string
    {
        return DB::transaction(fn (): string => app(AdminContInvoiceFolioService::class)->next());
    }

    public function test_una_factura_insertada_fuera_del_servicio_no_hace_chocar_el_siguiente_folio(): void
    {
        $this->facturaConNumero('F-0050');   // importada: el contador sigue en 0

        $this->assertSame('F-0051', $this->siguiente(), 'toma el mayor entre el contador y lo existente');
        $this->assertSame('F-0052', $this->siguiente());
        $this->assertSame(52, (int) AdminContInvoiceFolioCounter::where('id', 1)->value('last_number'));
    }

    public function test_si_el_contador_va_adelante_de_las_facturas_se_respeta_el_contador(): void
    {
        $this->facturaConNumero('F-0005');
        AdminContInvoiceFolioCounter::updateOrCreate(['id' => 1], ['last_number' => 100]);

        $this->assertSame('F-0101', $this->siguiente());
    }

    public function test_numeros_que_no_son_folios_de_factura_no_cuentan(): void
    {
        $this->facturaConNumero('NC-9999');
        $this->facturaConNumero('F-ABC');

        $this->assertSame('F-0001', $this->siguiente());
    }
}
