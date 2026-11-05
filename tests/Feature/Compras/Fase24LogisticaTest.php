<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Fase 24 — baja lógica de documentos, formato del contenedor, historial de envío y costos ocultos para Operaciones. */
class Fase24LogisticaTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grant(string $email, array $perms): void
    {
        DB::table('atlantic_auth.users')->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    private function order(): PurchaseOrder
    {
        $p = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);

        return PurchaseOrder::create(['provider_id' => $p->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(), 'total_amount' => 500]);
    }

    /** @return array<string, string> */
    private function headers(string $email = 'vendedor@atlantic.test'): array
    {
        return $this->authHeaders($this->loginAs($email, 'Password123!', 'designer'));
    }

    private function subir(PurchaseOrder $o, string $cat = PurchaseOrderDocument::CATEGORY_BL): int
    {
        return (int) $this->withHeaders($this->headers())
            ->postJson("/api/compras/orders/{$o->id}/documents", ['category' => $cat, 'file' => UploadedFile::fake()->create('d.pdf', 50, 'application/pdf')])
            ->assertStatus(201)->json('id');
    }

    public function test_baja_de_documento_exige_motivo_y_lo_saca_del_checklist_conservando_el_archivo(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $o = $this->order();
        $id = $this->subir($o);

        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$o->id}/documents/{$id}", ['motivo' => 'corto'])->assertStatus(422);
        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$o->id}/documents/{$id}", ['motivo' => 'Se subió el archivo equivocado'])->assertStatus(200);

        $this->withHeaders($this->headers())->getJson("/api/compras/orders/{$o->id}/documents")->assertJsonCount(0, 'data');
        $this->reMake();
        $fila = DB::table('atlantic_compras.purchase_order_documents')->where('id', $id)->first();
        $this->assertNotNull($fila->deleted_at, 'es baja lógica: la fila se conserva');
        $this->assertSame('Se subió el archivo equivocado', $fila->delete_reason);
        $this->assertNotNull($fila->deleted_by);
        Storage::disk('s3-private')->assertExists($fila->storage_key);
        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$o->id}/documents/{$id}", ['motivo' => 'Se subió el archivo equivocado'])->assertStatus(404);
    }

    public function test_baja_de_documento_solo_con_compras_edit_y_id_ajeno_da_404(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.edit']);
        $o = $this->order();
        $otra = $this->order();
        $id = $this->subir($o);
        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$otra->id}/documents/{$id}", ['motivo' => 'Motivo de prueba largo'])->assertStatus(404);

        $this->grant('vendedor@atlantic.test', ['compras.read']);
        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$o->id}/documents/{$id}", ['motivo' => 'Motivo de prueba largo'])->assertStatus(403);
    }

    public function test_confirmacion_del_proveedor_no_se_quita_si_la_orden_tiene_pagos(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.edit']);
        $o = $this->order();
        $id = PurchaseOrderDocument::create([
            'purchase_order_id' => $o->id, 'category' => PurchaseOrderDocument::CATEGORY_CONFIRMACION_PROVEEDOR,
            'original_filename' => 'c.pdf', 'storage_key' => 'x/c.pdf', 'size_bytes' => 10, 'mime_type' => 'application/pdf',
        ])->id;
        \App\Modules\Compras\Models\PurchaseOrderPayment::create(['purchase_order_id' => $o->id, 'amount' => 10, 'payment_proof_storage_key' => 'compras/payments/p.pdf']);

        $this->withHeaders($this->headers())->deleteJson("/api/compras/orders/{$o->id}/documents/{$id}", ['motivo' => 'Motivo de prueba largo'])->assertStatus(422);
    }

    public function test_contenedor_se_normaliza_y_se_rechaza_si_no_cumple_iso_6346(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.edit']);
        $o = $this->order();
        $url = "/api/compras/orders/{$o->id}/shipping-info";

        $this->withHeaders($this->headers())->patchJson($url, ['container_number' => 'abc123'])->assertStatus(422)->assertJsonValidationErrors('container_number');
        $this->withHeaders($this->headers())->patchJson($url, ['carrier' => '12345'])->assertStatus(422)->assertJsonValidationErrors('carrier');
        $this->withHeaders($this->headers())->patchJson($url, ['carrier' => 'M'])->assertStatus(422);
        $this->withHeaders($this->headers())->patchJson($url, ['container_number' => 'msku-123 4567', 'carrier' => 'Maersk'])
            ->assertStatus(200)->assertJsonPath('container_number', 'MSKU1234567');
        $this->withHeaders($this->headers())->patchJson($url, ['container_number' => ''])->assertStatus(200)->assertJsonPath('container_number', null);
    }

    public function test_historial_de_envio_muestra_quien_y_cuando_sin_montos(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $o = $this->order();
        $url = "/api/compras/orders/{$o->id}/shipping-info";
        $this->withHeaders($this->headers())->patchJson($url, ['container_number' => 'MSKU1234567'])->assertStatus(200);
        $this->reMake();
        $this->withHeaders($this->headers())->patchJson($url, ['container_number' => 'TGHU7654321', 'carrier' => 'Maersk'])->assertStatus(200);
        $this->reMake();

        $r = $this->withHeaders($this->headers())->getJson("/api/compras/orders/{$o->id}/shipping-history")->assertStatus(200);
        $campos = collect($r->json('data'));
        $cambio = $campos->first(fn ($c) => $c['field'] === 'container_number' && $c['new'] === 'TGHU7654321');
        $this->assertSame('MSKU1234567', $cambio['old']);
        $this->assertNotEmpty($cambio['changed_by']);
        $this->assertNotEmpty($cambio['changed_at']);
        $this->assertStringNotContainsString('total_amount', json_encode($r->json()));
        $this->withHeaders($this->headers())->getJson('/api/compras/orders/99999999/shipping-history')->assertStatus(404);
    }

    public function test_operaciones_ve_la_orden_sin_montos_como_el_vendedor(): void
    {
        $this->grant('vendedor@atlantic.test', ['compras.limited.view']);
        $o = $this->order();
        $r = $this->withHeaders($this->headers())->getJson("/api/compras/orders/{$o->id}")->assertStatus(200);
        $this->assertNull($r->json('total_amount'));

        $this->grant('vendedor@atlantic.test', ['compras.limited.view', 'compras.read']);
        $this->reMake();
        $r = $this->withHeaders($this->headers())->getJson("/api/compras/orders/{$o->id}")->assertStatus(200);
        $this->assertEquals(500, $r->json('total_amount'));
    }

    private function reMake(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }
}
