<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-454/457/458/459 (REQ-384/387/388/389, Bloque B4) — Reubicación entre bodegas. */
class RelocationRequestControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    /** @param list<string> $perms */
    private function grantBodega(string $email, array $perms = ['bodega.read', 'bodega.write']): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Candelabro de prueba', 'description' => 'Candelabro de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_store_crea_solicitud_pendiente(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 3,
            'motivo' => 'Reorganización de espacio',
        ]);

        $response->assertStatus(201);

        $this->reMakeCurrent();
        $this->assertSame(1, RelocationRequest::where('estado', RelocationRequest::ESTADO_PENDIENTE)->count());
    }

    /** RN1 (REQ-387) — la bodega destino nunca puede ser la misma que la de origen. */
    public function test_store_con_bodega_destino_igual_a_origen_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $central->id,
            'cantidad' => 3,
            'motivo' => 'Reorganización',
        ])->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    }

    /**
     * Visual Review 2026-07-24 (CRÍTICO) — el mockup nunca ofrece "Reubicar" en bodegas modo
     * "pendiente" (sin ubicación física confirmada, ej. Zona Libre); el frontend oculta el botón
     * ahí, pero el backend también debe rechazarlo por si alguien llama el endpoint directo.
     */
    public function test_store_desde_bodega_modo_pendiente_retorna_422(): void
    {
        $product = $this->makeProduct();
        $zonaLibre = Warehouse::where('name', 'Bodega Zona Libre')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $zonaLibre->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 3,
            'motivo' => 'Reorganización',
        ])->assertStatus(422);
    }

    /** Mejora SCRUM-752 RN1 (Daniela Amaya 2026-08-13) — Zona Libre tampoco puede ser DESTINO. */
    public function test_store_hacia_bodega_modo_pendiente_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $zonaLibre = Warehouse::where('name', 'Bodega Zona Libre')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $zonaLibre->id,
            'cantidad' => 3,
            'motivo' => 'Reorganización',
        ])->assertStatus(422);
    }

    /** Mejora SCRUM-752 RN4/RN5 — tope real de unidades en la bodega de origen, no el stock total. */
    public function test_store_con_cantidad_mayor_a_la_disponible_en_origen_retorna_422_con_maximo(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 100]); // stock GLOBAL alto a propósito
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        // Solo 5 unidades REALES en la bodega de origen, pese a que el stock global es 100.
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 8,
            'motivo' => 'Reorganización',
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Estás intentando mover más unidades de las que hay en esta bodega. La cantidad máxima es de 5 unidades.');

        $this->reMakeCurrent();
        $this->assertSame(0, RelocationRequest::count());
    }

    /** Mejora SCRUM-752 RN4/RN5 — el límite exacto (igual a lo disponible) sí se acepta. */
    public function test_store_con_cantidad_exacta_a_la_disponible_en_origen_crea_solicitud(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 5,
            'motivo' => 'Reorganización',
        ])->assertStatus(201);
    }

    /** RN2 (REQ-387) — cantidad, bodega destino y motivo son obligatorios. */
    public function test_store_sin_cantidad_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'motivo' => 'Reorganización',
        ])->assertStatus(422)->assertJsonValidationErrors('cantidad');
    }

    public function test_store_sin_motivo_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('motivo');
    }

    public function test_store_sin_bodega_destino_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'cantidad' => 3,
            'motivo' => 'Reorganización',
        ])->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    }

    /** REQ-388 — aprobar ajusta el inventario automáticamente en ambas bodegas. */
    public function test_aprobar_ajusta_inventario_en_origen_y_destino(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $merma->id, 'quantity' => 2]);

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('estado', RelocationRequest::ESTADO_APROBADA);

        $this->reMakeCurrent();
        $this->assertSame(6, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(6, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $merma->id)->value('quantity'));

        $movements = InventoryMovement::where('catalog_product_id', $product->id)->orderBy('warehouse_id')->get();
        $this->assertSame(2, $movements->count());
        foreach ($movements as $movement) {
            $this->assertSame(InventoryMovement::TIPO_REUBICACION, $movement->tipo);
        }
        $originMovement = $movements->firstWhere('warehouse_id', $central->id);
        $destinationMovement = $movements->firstWhere('warehouse_id', $merma->id);
        $this->assertSame(-4, $originMovement->cantidad);
        $this->assertSame(4, $destinationMovement->cantidad);
    }

    public function test_rechazar_no_toca_el_inventario(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/reject", ['motivo_rechazo' => 'No corresponde']);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', RelocationRequest::ESTADO_RECHAZADA);

        $this->reMakeCurrent();
        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::where('catalog_product_id', $product->id)->count());
    }

    /** RN1 (REQ-389) — no se puede rechazar sin escribir motivo. */
    public function test_rechazar_sin_motivo_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_aprobar_no_siendo_mark_retorna_403(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        // `gerencia@illuminations.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Mark (2026-08-22), usar `multi@illuminations.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $this->grantBodega('multi@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('multi@illuminations.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/approve")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(RelocationRequest::ESTADO_PENDIENTE, RelocationRequest::find($relocationRequest->id)->estado);
    }

    /**
     * Mismo hallazgo de concurrencia que `AdjustmentRequestControllerTest` (Pre-QA 2026-07-21,
     * CRÍTICO en el módulo hermano): dos approve seguidos sobre la misma solicitud solo deben
     * aplicar el movimiento una vez — el lock+chequeo de estado viven dentro de la transacción.
     */
    public function test_aprobar_dos_veces_seguidas_solo_aplica_la_reubicacion_una_vez(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/bodega/relocations/{$relocationRequest->id}/approve")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/relocations/{$relocationRequest->id}/approve")->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(6, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(4, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $merma->id)->value('quantity'));
        $this->assertSame(2, InventoryMovement::where('catalog_product_id', $product->id)->count());
    }

    public function test_index_filtra_por_estado(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $solicitanteId = User::where('email', 'designer@illuminations.test')->first()->id;

        RelocationRequest::create([
            'catalog_product_id' => $product->id, 'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id, 'cantidad' => 2, 'motivo' => 'A',
            'solicitado_por' => $solicitanteId, 'estado' => RelocationRequest::ESTADO_APROBADA,
        ]);
        RelocationRequest::create([
            'catalog_product_id' => $product->id, 'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id, 'cantidad' => 1, 'motivo' => 'B',
            'solicitado_por' => $solicitanteId, 'estado' => RelocationRequest::ESTADO_RECHAZADA,
        ]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $all = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/relocations');
        $all->assertStatus(200);
        $all->assertJsonCount(2, 'data');

        $rejected = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/relocations?estado=rechazada');
        $rejected->assertJsonCount(1, 'data');
        $rejected->assertJsonPath('data.0.motivo', 'B');
    }

    /** RN2 (REQ-389) — una fila resuelta ya no tiene acciones — el backend debe seguir devolviendo 422 al intentar. */
    public function test_rechazar_solicitud_ya_resuelta_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_APROBADA,
        ]);

        $this->grantBodega('management@illuminations.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/reject", ['motivo_rechazo' => 'Tarde'])
            ->assertStatus(422);
    }

    /**
     * SCRUM-458 (rebote de Daniela Amaya 2026-08-13) — Daniela reportó los botones Aprobar/
     * Rechazar visibles para el Líder de Bodega (Esteban Cardenas) en la bandeja de
     * "Solicitudes de reubicación". Pre-QA/QA ya habían confirmado 403 real en el backend contra
     * usuarios de Gerencia distintos de Mark (`test_aprobar_no_siendo_mark_retorna_403` arriba) y
     * contra un usuario de Bodega genérico (QA 2026-07-26, Esteban vía UI real) — este test cierra
     * el mismo caso con el actor EXACTO que nombra el rebote (`lider_bodega`, no "management" ni
     * "bodega genérico"), incluso concediéndole `bodega.approve` de más (defensa en profundidad:
     * el gate real es `resolveMarkAccess()` por `mark_approver_user_id`, no el permiso de ruta).
     */
    public function test_aprobar_siendo_lider_bodega_retorna_403(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);
        $this->grantBodega('almacen@illuminations.com.pa', ['bodega.read', 'bodega.write', 'bodega.approve']);

        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/approve")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(RelocationRequest::ESTADO_PENDIENTE, RelocationRequest::find($relocationRequest->id)->estado);
    }

    /** Mismo caso que arriba, para reject() — motivo obligatorio nunca llega a evaluarse porque el gate corta antes. */
    public function test_rechazar_siendo_lider_bodega_retorna_403(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => $central->id,
            'destination_warehouse_id' => $merma->id,
            'cantidad' => 4,
            'motivo' => 'Reorganización',
            'solicitado_por' => User::where('email', 'designer@illuminations.test')->first()->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);
        $this->grantBodega('almacen@illuminations.com.pa', ['bodega.read', 'bodega.write', 'bodega.approve']);

        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/relocations/{$relocationRequest->id}/reject", ['motivo_rechazo' => 'No corresponde'])
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(RelocationRequest::ESTADO_PENDIENTE, RelocationRequest::find($relocationRequest->id)->estado);
    }
}
