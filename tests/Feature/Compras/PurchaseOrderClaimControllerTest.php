<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderClaim;
use App\Modules\Compras\Models\PurchaseOrderClaimLine;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-257→261 (REQ-194→198) — Garantías y Reclamos. */
class PurchaseOrderClaimControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @return array{order: PurchaseOrder, line: PurchaseOrderLine} */
    private function makeOrderWithLine(int $quantity = 5): array
    {
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD']);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo decorativo E27 Ámbar', 'description' => 'Bombillo decorativo E27 Ámbar',
            'price_full' => 20, 'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'total_amount' => $quantity * 10,
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => $quantity, 'unit_cost' => 10, 'subtotal' => $quantity * 10,
        ]);

        return ['order' => $order, 'line' => $line];
    }

    public function test_crear_reclamo_no_puede_exceder_lo_pedido(): void
    {
        // REQ-197 Escenario 2 — orden pidió 250, se intenta declarar 300.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $fixture = $this->makeOrderWithLine(250);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 300],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_crear_reclamo_no_puede_repetir_producto(): void
    {
        // REQ-197 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 2],
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 3],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_crear_reclamo_valido(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'description' => 'Llegaron rotos',
                // SCRUM-260 (hallazgo de Daniela Amaya) — deja de ser texto libre, ahora valida
                // contra PurchaseOrderClaim::RESOLUTION_TYPES.
                'expected_resolution' => PurchaseOrderClaim::RESOLUTION_REPOSICION,
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 4],
                ],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('status', PurchaseOrderClaim::STATUS_EN_REVISION);
        $response->assertJsonPath('total_amount', 40); // 4 * 10
        $response->assertJsonPath('service_tickets', []);
        $this->assertMatchesRegularExpression('/^R-\d+$/', $response->json('code'));
    }

    /**
     * SCRUM-260 (hallazgo de Daniela Amaya 2026-08-10) — "Tipo de resolución" deja de ser texto
     * libre; cualquier valor fuera de PurchaseOrderClaim::RESOLUTION_TYPES es 422.
     */
    public function test_expected_resolution_ya_no_acepta_texto_libre(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'expected_resolution' => 'Reposición inmediata por favor',
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expected_resolution']);
    }

    /** SCRUM-260 — las 5 opciones fijas son válidas, una por una. */
    public function test_expected_resolution_acepta_las_5_opciones_fijas(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        foreach (PurchaseOrderClaim::RESOLUTION_TYPES as $resolution) {
            // Un ciclo completo de request HTTP (postJson) suelta el tenant "current" al
            // terminar (mismo comportamiento que producción, InitializeTenancy es por-request) —
            // hay que re-establecerlo antes de la siguiente fixture de este mismo test, igual que
            // hace setUp() para la primera vez.
            Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
            $fixture = $this->makeOrderWithLine(10);

            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/compras/claims', [
                    'purchase_order_id' => $fixture['order']->id,
                    'expected_resolution' => $resolution,
                    'lines' => [
                        ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 1],
                    ],
                ])
                ->assertStatus(201)
                ->assertJsonPath('expected_resolution', $resolution);
        }
    }

    /**
     * Hallazgo de Senior Review (2026-07-17): la creación de un reclamo es un recurso de alto
     * nivel (`/compras/claims`, igual que /providers, /orders, /liquidation-agencies) — debe
     * usar `compras.write` como el resto, no `compras.edit` (eso es para mutar un recurso ya
     * existente). Corregido en routes/compras.php; este test fija el comportamiento correcto.
     */
    public function test_crear_reclamo_sin_permiso_write_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 1],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_bandera_de_atencion_tras_el_umbral_de_dias(): void
    {
        // REQ-194 Escenario 1 — el ejemplo literal del ticket usa 7 días; acá sembramos el
        // reclamo ya con 8 días de antigüedad en 'en_revision' para no depender de mockear now().
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now()->subDays(8),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $claim->id);
        $this->assertTrue($row['needs_attention']);

        // SCRUM-257 (hallazgo de Daniela Amaya) — "Atención" ya no es un KPI (los 4 nuevos son
        // Total/Reportados-En revisión/Resueltos este mes/Monto en disputa), sigue existiendo
        // como bandera por fila y como chip de filtro.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $chipResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims?chip=atencion')
            ->assertStatus(200);
        $this->assertCount(1, $chipResponse->json('data'));
        $this->assertSame($claim->id, $chipResponse->json('data.0.id'));
    }

    public function test_cambiar_estado_a_resuelto_quita_la_bandera(): void
    {
        // REQ-195 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now()->subDays(8),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$claim->id}/status", ['status' => PurchaseOrderClaim::STATUS_RESUELTO])
            ->assertStatus(200)
            ->assertJsonPath('needs_attention', false);
    }

    public function test_subir_foto_de_evidencia(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/claims/{$claim->id}/photos", [
                'photo' => UploadedFile::fake()->create('evidencia.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $this->assertNotEmpty($response->json('url'));
    }

    /**
     * SCRUM-259 (REQ-196, hallazgo de Daniela Amaya 2026-08-10) — "Tipo de resolución" editable
     * desde el detalle, no solo al crear el reclamo.
     */
    public function test_actualizar_tipo_de_resolucion_desde_el_detalle(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(), 'expected_resolution' => PurchaseOrderClaim::RESOLUTION_REPOSICION,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$claim->id}/resolution", [
                'expected_resolution' => PurchaseOrderClaim::RESOLUTION_NEGADO,
            ])
            ->assertStatus(200)
            ->assertJsonPath('expected_resolution', PurchaseOrderClaim::RESOLUTION_NEGADO);

        // El ciclo de request HTTP suelta el tenant "current" al terminar (InitializeTenancy es
        // por-request, mismo comportamiento que producción) — hay que re-establecerlo antes de
        // volver a tocar el modelo directamente desde el test.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(PurchaseOrderClaim::RESOLUTION_NEGADO, $claim->fresh()->expected_resolution);
    }

    public function test_actualizar_tipo_de_resolucion_rechaza_valor_fuera_del_catalogo(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$claim->id}/resolution", ['expected_resolution' => 'lo que sea'])
            ->assertStatus(422);
    }

    public function test_actualizar_tipo_de_resolucion_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$claim->id}/resolution", [
                'expected_resolution' => PurchaseOrderClaim::RESOLUTION_NEGADO,
            ])
            ->assertStatus(403);
    }

    /** SCRUM-259 (hallazgo de Daniela Amaya) — "Descargar PDF del reclamo", descarga real (Content-Disposition: attachment), no vista inline. */
    public function test_pdf_del_reclamo_se_descarga_como_adjunto(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);
        PurchaseOrderClaimLine::create([
            'claim_id' => $claim->id, 'purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 2,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/compras/claims/{$claim->id}/pdf");

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_detalle_incluye_acceso_a_la_orden(): void
    {
        // REQ-196 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/claims/{$claim->id}")
            ->assertStatus(200);

        $response->assertJsonPath('purchase_order_id', $fixture['order']->id);
    }

    public function test_detalle_incluye_historial_de_estados(): void
    {
        // Hallazgo de QA (SCRUM-259, 2026-07-18): "historial de estados" es parte explícita de
        // la ficha completa que pide el ticket — no existía ningún registro más allá del último
        // status_changed_at. La creación cuenta como el primer evento del historial.
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/claims', [
                'purchase_order_id' => $fixture['order']->id,
                'lines' => [
                    ['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 4],
                ],
            ])
            ->assertStatus(201);
        $claimId = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$claimId}/status", ['status' => PurchaseOrderClaim::STATUS_RESUELTO])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/claims/{$claimId}")
            ->assertStatus(200);

        $history = $response->json('status_history');
        $this->assertCount(2, $history);
        $this->assertNull($history[0]['from_status']);
        $this->assertSame(PurchaseOrderClaim::STATUS_EN_REVISION, $history[0]['to_status']);
        $this->assertSame(PurchaseOrderClaim::STATUS_EN_REVISION, $history[1]['from_status']);
        $this->assertSame(PurchaseOrderClaim::STATUS_RESUELTO, $history[1]['to_status']);
    }

    public function test_umbral_de_atencion_es_configurable(): void
    {
        ComprasSettings::current()->update(['claim_attention_days' => 2]);
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $claim = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture['order']->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now()->subDays(3),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $claim->id);
        $this->assertTrue($row['needs_attention']);
    }

    /**
     * SCRUM-257 (hallazgo de Daniela Amaya 2026-08-09) — los 4 KPIs nuevos (Total/Reportados-En
     * revisión/Resueltos este mes/Monto en disputa), calculados sobre el universo COMPLETO de
     * reclamos, no sobre el resultado ya filtrado.
     */
    public function test_kpis_del_encabezado(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);

        // Todas las fixtures ANTES de loginAs() — loginAs() hace una request HTTP real
        // (POST /api/auth/login) que suelta el tenant "current" al terminar (mismo patrón que
        // AuthenticateJwt::finally), así que todo lo que toque el modelo directamente va antes,
        // igual que el resto de los tests de esta clase (ver makeOrderWithLine + loginAs en los
        // tests ya existentes).
        $fixture1 = $this->makeOrderWithLine(10);
        $fixture2 = $this->makeOrderWithLine(10);
        $fixture3 = $this->makeOrderWithLine(10);
        // 1 resuelto hace 2 meses, sembrado directo (no vía API) — no debe contar en "este mes".
        $claim3 = PurchaseOrderClaim::create([
            'purchase_order_id' => $fixture3['order']->id, 'status' => PurchaseOrderClaim::STATUS_RESUELTO,
            'status_changed_at' => now()->subMonths(2), 'resolved_at' => now()->subMonths(2),
        ]);
        PurchaseOrderClaimLine::create([
            'claim_id' => $claim3->id, 'purchase_order_line_id' => $fixture3['line']->id, 'affected_quantity' => 3,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // 1 en revisión.
        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixture1['order']->id,
            'lines' => [['purchase_order_line_id' => $fixture1['line']->id, 'affected_quantity' => 1]],
        ])->assertStatus(201);

        // 1 resuelto este mes.
        $created2 = $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixture2['order']->id,
            'lines' => [['purchase_order_line_id' => $fixture2['line']->id, 'affected_quantity' => 2]],
        ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/claims/{$created2->json('id')}/status", ['status' => PurchaseOrderClaim::STATUS_RESUELTO])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims')
            ->assertStatus(200);

        $this->assertSame(3, $response->json('kpis.total'));
        $this->assertSame(1, $response->json('kpis.reportados_en_revision'));
        $this->assertSame(1, $response->json('kpis.resueltos_este_mes'));
        // 1*10 + 2*10 + 3*10 = 60 (unit_cost 10 en makeOrderWithLine).
        // json_encode() de PHP escribe 60.0 como el entero 60 cuando no tiene parte decimal.
        $this->assertEquals(60.0, $response->json('kpis.disputed_amount'));
    }

    /** SCRUM-257 — filtro de proveedor. */
    public function test_filtro_por_proveedor(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);

        // Todas las fixtures ANTES de loginAs() — ver nota en test_kpis_del_encabezado.
        $fixtureA = $this->makeOrderWithLine(10);
        $fixtureB = $this->makeOrderWithLine(10);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $claimA = $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixtureA['order']->id,
            'lines' => [['purchase_order_line_id' => $fixtureA['line']->id, 'affected_quantity' => 1]],
        ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixtureB['order']->id,
            'lines' => [['purchase_order_line_id' => $fixtureB['line']->id, 'affected_quantity' => 1]],
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims?provider_id=' . $fixtureA['order']->provider_id)
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$claimA], $ids);
    }

    /** SCRUM-257 — filtro de tipo de resolución + búsqueda de texto libre, combinados. */
    public function test_filtro_por_tipo_de_resolucion_y_busqueda_texto_combinados(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $claimId = $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixture['order']->id,
            'expected_resolution' => PurchaseOrderClaim::RESOLUTION_NEGADO,
            'lines' => [['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 1]],
        ])->assertStatus(201)->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims?' . http_build_query([
                'expected_resolution' => PurchaseOrderClaim::RESOLUTION_NEGADO,
                'search'              => 'Ámbar',
            ]))
            ->assertStatus(200);
        $this->assertSame([$claimId], collect($response->json('data'))->pluck('id')->all());

        $emptyResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims?expected_resolution=' . PurchaseOrderClaim::RESOLUTION_REPOSICION)
            ->assertStatus(200);
        $this->assertSame([], collect($emptyResponse->json('data'))->pluck('id')->all());
    }

    /** SCRUM-258 — columna "Producto", derivada de las líneas del reclamo. */
    public function test_columna_producto_en_el_listado(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $fixture = $this->makeOrderWithLine(10);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $claimId = $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/claims', [
            'purchase_order_id' => $fixture['order']->id,
            'lines' => [['purchase_order_line_id' => $fixture['line']->id, 'affected_quantity' => 1]],
        ])->assertStatus(201)->json('id');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/claims')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $claimId);
        $this->assertSame('Bombillo decorativo E27 Ámbar', $row['product']);
        $this->assertNull($row['service_ticket']);
    }
}
