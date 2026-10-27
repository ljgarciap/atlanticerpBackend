<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 (pedido de Luis, 2026-09-25) — `GET .../fe-preview` deja ver el JSON que se armaría
 * para el PAC sin certificar nada, para validar la estructura sobre todo en `pac_ambiente:
 * apagado`. Nunca llama a MSL (no hay `Http::fake()` en estos tests — si llegara a llamar de
 * verdad, fallarían por conexión real).
 */
final class FiscalDocumentPreviewTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    public function test_preview_de_factura_devuelve_el_payload_msl_sin_llamar_a_nadie(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        // La factura se crea sin proveedor fiscal (base de pruebas en `none`); la vista previa se pide ya con MSL en pruebas.
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();

        $response = $this->api($this->token(), 'GET', "/api/admin-contab/invoices/{$root->id}/fe-preview")
            ->assertStatus(200);

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('payload.ambiente', 'pruebas');
        $response->assertJsonPath('payload.serie', 'ILU');
        $response->assertJsonPath('payload.items.0.codigo', fn ($v) => $v !== null);
        $this->assertCount(1, $response->json('payload.pagos')); // sin cobros -> saldo completo a crédito
        $response->assertJsonPath('payload.pagos.0.tipo', '01'); // crédito
    }

    /** Batch endurecimiento FE/MSL — el ambiente mostrado refleja `pac_ambiente`, sin llamar a nadie. */
    public function test_preview_muestra_el_ambiente_segun_pac_ambiente(): void
    {
        Http::fake();
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl'])->save();

        foreach (['apagado' => 'sin envío', 'testing' => 'pruebas', 'production' => 'produccion'] as $pacAmbiente => $esperado) {
            AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => $pacAmbiente])->save();

            $this->api($this->token(), 'GET', "/api/admin-contab/invoices/{$root->id}/fe-preview")
                ->assertStatus(200)
                ->assertJsonPath('payload.ambiente', $esperado);
        }

        Http::assertNothingSent();
    }

    /** Fase 6b — sin proveedor fiscal (`none`) el documento es interno: la vista previa lo dice con «sin envío». */
    public function test_preview_sin_proveedor_fiscal_dice_sin_envio(): void
    {
        Http::fake();
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'none', 'pac_ambiente' => 'production'])->save();

        $this->api($this->token(), 'GET', "/api/admin-contab/invoices/{$root->id}/fe-preview")
            ->assertStatus(200)
            ->assertJsonPath('payload.ambiente', 'sin envío');

        Http::assertNothingSent();
    }

    public function test_preview_de_factura_inexistente_devuelve_404(): void
    {
        $this->api($this->token(), 'GET', '/api/admin-contab/invoices/999999/fe-preview')
            ->assertStatus(404);
    }

    public function test_preview_de_nota_de_credito_sin_factura_de_origen_certificada_devuelve_error_explicito(): void
    {
        $ownerId = $this->ownerId();
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        // Sin cufe — nunca certificada (caso normal en pac_ambiente: apagado).

        $note = AdminContCreditNote::create([
            'numero' => 'NC-PREVIEW-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test preview', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $ownerId, 'fecha' => now()->toDateString(),
        ]);

        $response = $this->api($this->token(), 'GET', "/api/admin-contab/notas-credito/{$note->id}/fe-preview")
            ->assertStatus(200);

        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error', fn ($v) => str_contains((string) $v, 'nunca fue certificada'));
    }

    public function test_preview_de_nota_de_credito_con_factura_certificada_devuelve_el_payload(): void
    {
        $ownerId = $this->ownerId();
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $factura->forceFill(['serie' => 'ILU', 'cufe' => 'FE0120000...preview'])->save();

        $note = AdminContCreditNote::create([
            'numero' => 'NC-PREVIEW2-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test preview', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $ownerId, 'fecha' => now()->toDateString(),
        ]);

        $response = $this->api($this->token(), 'GET', "/api/admin-contab/notas-credito/{$note->id}/fe-preview")
            ->assertStatus(200);

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('payload.referencia.cufe', 'FE0120000...preview');
        $response->assertJsonPath('payload.items.0.codigo', $note->numero);
    }
}
