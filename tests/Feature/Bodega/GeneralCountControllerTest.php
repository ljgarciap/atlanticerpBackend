<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\GeneralCountRequestLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-460→466 (REQ-390→396, Bloque B5 "Conteos generales de inventario"). */
class GeneralCountControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $perms */
    private function grantBodega(string $email, array $perms = ['bodega.read', 'bodega.write']): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Modelo de prueba', 'description' => 'Modelo de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function markUp(string $email = 'management@atlantic.test'): int
    {
        $this->grantBodega($email, ['bodega.approve']);
        $approverId = (int) User::where('email', $email)->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $approverId]);

        return $approverId;
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-460 (REQ-390) — store()
    // ─────────────────────────────────────────────────────────────────

    public function test_store_precarga_solo_productos_con_stock_mayor_a_cero(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $conStock = $this->makeProduct();
        $sinStock = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $conStock->id, 'warehouse_id' => $central->id, 'quantity' => 5]);
        ProductWarehouseStock::create(['catalog_product_id' => $sinStock->id, 'warehouse_id' => $central->id, 'quantity' => 0]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/general-counts', [
            'warehouse_id' => $central->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_productos', 1);

        $this->reMakeCurrent();
        $generalCount = GeneralCountRequest::find($response->json('id'));
        $this->assertSame(GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, $generalCount->estado);
        $this->assertSame(1, $generalCount->lines()->count());
        $this->assertSame($conStock->id, $generalCount->lines()->first()->catalog_product_id);
    }

    public function test_store_sin_bodega_retorna_422(): void
    {
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/general-counts', [])
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-797 (rebote de Gerencia Test 2026-08-27) — store() detecta conflicto por
    // PRODUCTO+bodega, no por bodega completa (revierte la versión del 2026-08-26).
    // ─────────────────────────────────────────────────────────────────

    public function test_store_con_producto_con_diferencia_evaluada_pendiente_retorna_409(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $existing = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $existingLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $existing->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id]);

        $response->assertStatus(409);
        $response->assertJsonPath('duplicates.0.catalog_product_id', $product->id);
        $response->assertJsonPath('duplicates.0.general_count.id', $existing->id);

        $this->reMakeCurrent();
        $this->assertNull($existingLine->fresh()->replaced_by_id);
    }

    public function test_store_con_confirm_replace_marca_solo_la_linea_del_producto_en_conflicto(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productConDiferencia = $this->makeProduct();
        $productSinConflicto = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $productConDiferencia->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $productSinConflicto->id, 'warehouse_id' => $central->id, 'quantity' => 4]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $existing = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $existingLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $existing->id, 'catalog_product_id' => $productConDiferencia->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id, 'confirm_replace' => true]);

        $response->assertStatus(201);
        $newId = $response->json('id');

        $this->reMakeCurrent();
        // La bodega entera SÍ se creó de nuevo (2 productos) — el reemplazo es solo de la línea.
        $newRequest = GeneralCountRequest::find($newId);
        $this->assertSame(2, $newRequest->lines()->count());
        $newLineConDiferencia = $newRequest->lines()->where('catalog_product_id', $productConDiferencia->id)->first();
        $this->assertSame($newLineConDiferencia->id, $existingLine->fresh()->replaced_by_id);
        // La request VIEJA sigue "evaluado" — el mecanismo de reemplazo ya no toca el conteo
        // completo, solo la línea puntual (ver docblock de findConflictingLines()).
        $this->assertSame(GeneralCountRequest::ESTADO_EVALUADO, $existing->fresh()->estado);
    }

    /**
     * SCRUM-800 — regresión: `findConflictingLines()` colapsaba a UNA línea por producto (keyBy)
     * al marcar `replaced_by_id`. Si existían DOS líneas huérfanas sin resolver del mismo
     * producto (de dos conteos viejos distintos, ninguno resuelto por Gerencia todavía), solo una
     * quedaba marcada y la otra seguía bloqueando ajustes/conteos nuevos para siempre.
     */
    public function test_store_con_confirm_replace_marca_todas_las_lineas_huerfanas_del_producto_no_solo_una(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $oldest = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $oldestLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $oldest->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);
        $newer = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);
        $newerLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $newer->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 9, 'diferencia' => -1,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id, 'confirm_replace' => true]);

        $response->assertStatus(201);
        $newId = $response->json('id');

        $this->reMakeCurrent();
        $newestLine = GeneralCountRequest::find($newId)->lines()->where('catalog_product_id', $product->id)->first();

        $this->assertSame($newestLine->id, $oldestLine->fresh()->replaced_by_id, 'la línea más vieja huérfana debe quedar marcada como reemplazada');
        $this->assertSame($newestLine->id, $newerLine->fresh()->replaced_by_id, 'la línea intermedia huérfana debe quedar marcada como reemplazada');
    }

    /**
     * El corazón del rebote: un producto SIN conflicto en la misma bodega nunca debe bloquearse.
     * `productConDiferencia` deliberadamente NO tiene fila de stock (equivale a 0 — queda fuera
     * del `WHERE quantity > 0` de `store()`), así que el conteo nuevo solo incluye
     * `productSinRelacion` y jamás debería toparse con el conflicto ajeno.
     */
    public function test_store_con_producto_distinto_en_la_misma_bodega_no_bloquea(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productConDiferencia = $this->makeProduct();
        $productSinRelacion = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $productSinRelacion->id, 'warehouse_id' => $central->id, 'quantity' => 5]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $existing = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $existing->id, 'catalog_product_id' => $productConDiferencia->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id])
            ->assertStatus(201);
    }

    /** Sin evaluar todavía (diferencia siempre NULL) — nada está en disputa, no bloquea. */
    public function test_store_con_conteo_anterior_sin_evaluar_todavia_no_bloquea(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $existing = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $existing->id, 'catalog_product_id' => $product->id,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id])
            ->assertStatus(201);
    }

    /** Diferencia real pero en OTRA bodega — no cruza. */
    public function test_store_con_producto_con_diferencia_en_otra_bodega_no_bloquea(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $existing = GeneralCountRequest::create([
            'warehouse_id' => $merma->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $existing->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id])
            ->assertStatus(201);
    }

    public function test_store_sin_conflicto_no_pide_confirmacion(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id])
            ->assertStatus(201);
    }

    /** Un conteo `aprobada`/`rechazada` ya no "ocupa" nada — sus líneas quedan fuera del chequeo. */
    public function test_store_con_bodega_de_conteo_ya_resuelto_no_bloquea(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;
        $resuelto = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $resuelto->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/general-counts', ['warehouse_id' => $central->id])
            ->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-462 (REQ-392) — evaluate()
    // ─────────────────────────────────────────────────────────────────

    public function test_evaluate_calcula_diferencia_y_pasa_a_evaluado(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        $line = GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id,
            'catalog_product_id' => $product->id,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/evaluate", [
                'lines' => [['id' => $line->id, 'cantidad_contada' => 7]],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_EVALUADO);
        $response->assertJsonPath('lines.0.cantidad_sistema', 10);
        $response->assertJsonPath('lines.0.cantidad_contada', 7);
        $response->assertJsonPath('lines.0.diferencia', -3);
    }

    /** RN1 (REQ-392) — exige cantidad_contada en TODAS las líneas del conteo. */
    public function test_evaluate_con_lineas_faltantes_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $productA->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $productB->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        $lineA = GeneralCountRequestLine::create(['general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productA->id]);
        GeneralCountRequestLine::create(['general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productB->id]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/evaluate", [
                'lines' => [['id' => $lineA->id, 'cantidad_contada' => 10]],
            ])
            ->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, GeneralCountRequest::find($generalCount->id)->estado);
    }

    /** RN2 (REQ-392) — reevaluar (llamar evaluate() de nuevo) recalcula de cero, sin mezclar valores viejos. */
    public function test_evaluate_dos_veces_recalcula_diferencia_de_cero(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        $line = GeneralCountRequestLine::create(['general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/bodega/general-counts/{$generalCount->id}/evaluate", [
            'lines' => [['id' => $line->id, 'cantidad_contada' => 7]],
        ])->assertStatus(200)->assertJsonPath('lines.0.diferencia', -3);

        // corrección — el usuario se dio cuenta que contó mal, vuelve a evaluar con el valor correcto
        $response = $this->withHeaders($headers)->postJson("/api/bodega/general-counts/{$generalCount->id}/evaluate", [
            'lines' => [['id' => $line->id, 'cantidad_contada' => 10]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_EVALUADO);
        $response->assertJsonPath('lines.0.cantidad_contada', 10);
        $response->assertJsonPath('lines.0.diferencia', 0);
    }

    public function test_evaluate_conteo_ya_enviado_a_aprobacion_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);
        $line = GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/evaluate", [
                'lines' => [['id' => $line->id, 'cantidad_contada' => 10]],
            ])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-463/464 (REQ-393/394) — submit()
    // ─────────────────────────────────────────────────────────────────

    public function test_submit_sin_evaluar_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit")
            ->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION, GeneralCountRequest::find($generalCount->id)->estado);
    }

    public function test_submit_evaluado_pasa_a_pendiente_aprobacion(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit");

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_PENDIENTE_APROBACION);
    }

    /** SCRUM-797 RN8 — `submit()` registra el momento exacto en que se pidió la aprobación,
     * distinto de `fecha` (creación del borrador) — antes no se persistía en ningún lado. */
    public function test_submit_registra_fecha_solicitud_aprobacion(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id,
            'realizado_por' => User::where('email', 'vendedor@atlantic.test')->first()->id,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);

        $this->assertNull($generalCount->fresh()->fecha_solicitud_aprobacion);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('fecha_solicitud_aprobacion'));

        // postJson() es un round-trip HTTP real — spatie/laravel-multitenancy olvida el tenant
        // "actual" al terminar cada request (mismo gotcha ya documentado en
        // AdminContInvoiceControllerTest) — hay que re-establecerlo antes de tocar el modelo de
        // nuevo, o `fresh()` cae al fallback sin schema de `getTable()` y explota con "relation
        // general_count_requests does not exist".
        $this->reMakeCurrent();
        $this->assertNotNull($generalCount->fresh()->fecha_solicitud_aprobacion);
    }

    /**
     * SCRUM-797 (rebote 2026-08-27) — "al enviar a aprobación de Gerencia": si MIENTRAS TANTO otro
     * conteo de la misma bodega ya dejó una línea evaluada con diferencia real sin resolver para
     * el MISMO producto, exige confirmación antes de reemplazar esa línea puntual.
     */
    public function test_submit_con_otro_conteo_con_el_mismo_producto_en_conflicto_retorna_409(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);
        // Otro conteo, mismo warehouse, YA EVALUADO con diferencia real en el MISMO producto —
        // creado/evaluado independientemente mientras este seguía en curso.
        $other = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $otherLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $other->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 6, 'diferencia' => -4,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit");

        $response->assertStatus(409);
        $response->assertJsonPath('duplicates.0.catalog_product_id', $product->id);
        $response->assertJsonPath('duplicates.0.general_count.id', $other->id);

        $this->reMakeCurrent();
        $this->assertSame(GeneralCountRequest::ESTADO_EVALUADO, $generalCount->fresh()->estado);
        $this->assertNull($otherLine->fresh()->replaced_by_id);
    }

    /** Mismo escenario pero con OTRO producto en conflicto — nunca debe bloquear el envío. */
    public function test_submit_con_otro_conteo_con_producto_distinto_no_bloquea(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $otroProducto = $this->makeProduct();
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);
        $other = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $other->id, 'catalog_product_id' => $otroProducto->id,
            'cantidad_sistema' => 5, 'cantidad_contada' => 2, 'diferencia' => -3,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit")
            ->assertStatus(200);
    }

    public function test_submit_con_confirm_replace_marca_solo_la_linea_del_producto_en_conflicto(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $designerId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $newLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);
        $other = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        $otherLine = GeneralCountRequestLine::create([
            'general_count_request_id' => $other->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 6, 'diferencia' => -4,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit", ['confirm_replace' => true]);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_PENDIENTE_APROBACION);

        $this->reMakeCurrent();
        // Solo la línea puntual del producto en conflicto queda reemplazada — el conteo "other"
        // en sí sigue "evaluado" (el mecanismo ya no toca el conteo completo).
        $this->assertSame($newLine->id, $otherLine->fresh()->replaced_by_id);
        $this->assertSame(GeneralCountRequest::ESTADO_EVALUADO, $other->fresh()->estado);
    }

    /**
     * SCRUM-464 (REQ-394) — al enviar, una AdjustmentRequestLine pendiente cruzada (mismo
     * producto+bodega) se elimina; si la AdjustmentRequest contenedora queda sin líneas, se
     * elimina también.
     */
    public function test_submit_elimina_solicitud_de_ajuste_cruzada_y_su_contenedora_si_queda_vacia(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $adjustmentRequest = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $solicitanteId]);
        $adjustmentLine = AdjustmentRequestLine::create([
            'adjustment_request_id' => $adjustmentRequest->id,
            'warehouse_id' => $central->id,
            'tipo' => AdjustmentRequestLine::TIPO_SUMAR,
            'cantidad' => 2,
            'motivo' => 'Sobrante detectado',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 12, 'diferencia' => 2,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertNull(AdjustmentRequestLine::find($adjustmentLine->id));
        $this->assertNull(AdjustmentRequest::find($adjustmentRequest->id));
    }

    /** El cruce respeta warehouse_id — una solicitud pendiente de OTRA bodega no se toca. */
    public function test_submit_no_toca_solicitud_de_ajuste_de_otra_bodega(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $adjustmentRequest = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $solicitanteId]);
        $adjustmentLine = AdjustmentRequestLine::create([
            'adjustment_request_id' => $adjustmentRequest->id,
            'warehouse_id' => $merma->id,
            'tipo' => AdjustmentRequestLine::TIPO_SUMAR,
            'cantidad' => 2,
            'motivo' => 'Sobrante detectado en Merma',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 12, 'diferencia' => 2,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/submit")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertNotNull(AdjustmentRequestLine::find($adjustmentLine->id));
    }

    /** show() (SCRUM-461, REQ-391) — señal de cruce con solicitud pendiente. */
    public function test_show_indica_cruce_con_solicitud_de_ajuste_pendiente(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $adjustmentRequest = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $solicitanteId]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $adjustmentRequest->id,
            'warehouse_id' => $central->id,
            'tipo' => AdjustmentRequestLine::TIPO_SUMAR,
            'cantidad' => 2,
            'motivo' => 'Sobrante detectado',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        GeneralCountRequestLine::create(['general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/general-counts/{$generalCount->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('lines.0.tiene_cruce_pendiente', true);
        $this->assertNotNull($response->json('lines.0.cruce_fecha'));
    }

    public function test_show_sin_cruce_pendiente_devuelve_false(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);
        GeneralCountRequestLine::create(['general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/general-counts/{$generalCount->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('lines.0.tiene_cruce_pendiente', false);
        $this->assertNull($response->json('lines.0.cruce_fecha'));
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-465 (REQ-395) — index(), approve(), reject()
    // ─────────────────────────────────────────────────────────────────

    public function test_index_filtra_por_estado(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $solicitanteId, 'estado' => GeneralCountRequest::ESTADO_APROBADA]);
        GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $solicitanteId, 'estado' => GeneralCountRequest::ESTADO_RECHAZADA]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $all = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/general-counts');
        $all->assertStatus(200);
        $all->assertJsonCount(2, 'data');

        $rejected = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/general-counts?estado=rechazada');
        $rejected->assertJsonCount(1, 'data');
    }

    /**
     * RN3 (REQ-395) — la bandeja expone "Diferencias encontradas" por fila (columna exigida
     * explícitamente por el ticket y poblada con un número real en el mockup, no un placeholder).
     * Hallazgo de Pre-QA 2026-07-25: `index()` nunca calculaba este dato, la celda quedaba fija
     * en "—" en el frontend — corregido con un `withCount` constreñido.
     */
    public function test_index_expone_diferencias_encontradas_por_fila(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productA->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 12, 'diferencia' => 2,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productB->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/general-counts');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.diferencias_encontradas', 1);
        $response->assertJsonPath('data.0.total_productos', 2);
    }

    /**
     * SCRUM-463 (rebote de Gerencia Test 2026-08-14) — `can_approve` es lo que el frontend usa
     * para OCULTAR Aprobar/Rechazar por completo (no solo el candado decorativo de antes).
     */
    public function test_index_expone_can_approve_true_para_mark(): void
    {
        $this->markUp('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/general-counts');

        $response->assertStatus(200);
        $response->assertJsonPath('can_approve', true);
    }

    public function test_index_expone_can_approve_false_para_bodega(): void
    {
        $this->markUp('management@atlantic.test');
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/general-counts');

        $response->assertStatus(200);
        $response->assertJsonPath('can_approve', false);
    }

    public function test_approve_pasa_a_aprobada_y_no_toca_kardex(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);

        $this->markUp();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_APROBADA);

        $this->reMakeCurrent();
        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::where('catalog_product_id', $product->id)->count());
        $this->assertNull(GeneralCountRequest::find($generalCount->id)->aplicado_at);
    }

    public function test_reject_exige_motivo(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);

        $this->markUp();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_reject_con_motivo_pasa_a_rechazada(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);

        $this->markUp();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/reject", ['motivo_rechazo' => 'Diferencias no justificadas']);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_RECHAZADA);
    }

    public function test_approve_no_siendo_aprobador_retorna_403(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);

        $this->markUp('management@atlantic.test');
        // `gerencia@atlantic.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Gerencia (2026-08-22), usar `nivel8@atlantic.test` (Nivel 8) para
        // simular un management genuino, no-aprobador y no-superadmin.
        $this->grantBodega('nivel8@atlantic.test', ['bodega.approve']);

        $token = $this->loginAs('nivel8@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/approve")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(GeneralCountRequest::ESTADO_PENDIENTE_APROBACION, GeneralCountRequest::find($generalCount->id)->estado);
    }

    public function test_approve_conteo_no_pendiente_de_aprobacion_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
        ]);

        $this->markUp();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/approve")
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-466 (REQ-396) — apply()
    // ─────────────────────────────────────────────────────────────────

    public function test_apply_aplica_diferencias_al_kardex(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productSobrante = $this->makeProduct();
        $productFaltante = $this->makeProduct();
        $productSinDiferencia = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        ProductWarehouseStock::create(['catalog_product_id' => $productSobrante->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $productFaltante->id, 'warehouse_id' => $central->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $productSinDiferencia->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $approverId = $this->markUp();
        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA, 'resuelto_por' => $approverId, 'resuelto_at' => now(),
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productSobrante->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 13, 'diferencia' => 3,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productFaltante->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 6, 'diferencia' => -4,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $productSinDiferencia->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0,
        ]);

        $this->grantBodega('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/apply");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('aplicado_at'));

        $this->reMakeCurrent();
        $this->assertSame(13, ProductWarehouseStock::where('catalog_product_id', $productSobrante->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(6, ProductWarehouseStock::where('catalog_product_id', $productFaltante->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $productSinDiferencia->id)->where('warehouse_id', $central->id)->value('quantity'));

        // solo 2 movimientos — la línea con diferencia 0 no genera Kardex.
        $this->assertSame(2, InventoryMovement::where('warehouse_id', $central->id)
            ->whereIn('catalog_product_id', [$productSobrante->id, $productFaltante->id, $productSinDiferencia->id])
            ->count());

        $movement = InventoryMovement::where('catalog_product_id', $productSobrante->id)->first();
        $this->assertSame(InventoryMovement::TIPO_CONTEO, $movement->tipo);
        $this->assertSame('CONTEO_GENERAL', $movement->referencia_entidad_tipo);
        $this->assertSame($generalCount->id, $movement->referencia_entidad_id);
    }

    /** RN (SCRUM-466) — solo se puede aplicar si estado=aprobada. */
    public function test_apply_conteo_no_aprobado_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
        ]);

        $this->grantBodega('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/apply")
            ->assertStatus(422);
    }

    /** Idempotencia real en backend (REQ-396) — aplicar dos veces solo debe generar movimientos una vez. */
    public function test_apply_dos_veces_seguidas_solo_aplica_una_vez(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 13, 'diferencia' => 3,
        ]);

        $this->grantBodega('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/bodega/general-counts/{$generalCount->id}/apply")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/general-counts/{$generalCount->id}/apply")->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(13, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::where('catalog_product_id', $product->id)->count());
    }

    /**
     * SCRUM-466 (rebote de Gerencia Test 2026-08-14) — RN1: "Realizar ajuste" es exclusivo del
     * Líder de Bodega. Gerencia (con `bodega.approve`, el mismo permiso que ya usa para aprobar el
     * conteo) NO debe poder aplicar el ajuste — su rol termina en aprobar/rechazar.
     */
    public function test_apply_siendo_aprobador_retorna_403(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA,
        ]);

        $approverId = $this->markUp();
        $this->grantBodega('management@atlantic.test', ['bodega.approve', 'bodega.write']);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/apply")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertNull(GeneralCountRequest::find($generalCount->id)->aplicado_at);
        $this->assertSame($approverId, ComprasSettings::current()->primary_approver_user_id);
    }

    /**
     * SCRUM-466 (rebote de Gerencia Test 2026-08-14) — otros perfiles de Bodega (Asistentes,
     * Operativos) tampoco deben poder aplicar el ajuste, únicamente el Líder de Bodega.
     */
    public function test_apply_siendo_asistente_bodega_retorna_403(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_APROBADA,
        ]);

        $this->grantBodega('asistentebodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('asistentebodega@test.com', 'asistentebodega@test.com', 'asistente_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/apply")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertNull(GeneralCountRequest::find($generalCount->id)->aplicado_at);
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-462 (REQ-392) — continueDraft()/destroy()
    // ─────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeGeneralCount(array $overrides = []): GeneralCountRequest
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $solicitanteId = User::where('email', 'vendedor@atlantic.test')->first()->id;

        return GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $solicitanteId,
            'estado' => GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION,
            ...$overrides,
        ]);
    }

    public function test_continue_en_pendiente_evaluacion_devuelve_el_detalle(): void
    {
        $generalCount = $this->makeGeneralCount();
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/continue")
            ->assertStatus(200);
        $response->assertJsonPath('id', $generalCount->id);
        $response->assertJsonPath('estado', GeneralCountRequest::ESTADO_PENDIENTE_EVALUACION);
    }

    public function test_continue_en_evaluado_devuelve_las_diferencias_ya_calculadas(): void
    {
        $product = $this->makeProduct();
        $generalCount = $this->makeGeneralCount(['estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2,
        ]);
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/continue")
            ->assertStatus(200);
        $response->assertJsonPath('lines.0.cantidad_contada', 8);
        $response->assertJsonPath('lines.0.diferencia', -2);
    }

    public function test_continue_ya_enviado_a_aprobacion_retorna_422(): void
    {
        $generalCount = $this->makeGeneralCount(['estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]);
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/general-counts/{$generalCount->id}/continue")
            ->assertStatus(422);
    }

    public function test_eliminar_borrador_en_pendiente_evaluacion(): void
    {
        $generalCount = $this->makeGeneralCount();
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/bodega/general-counts/{$generalCount->id}")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertNull(GeneralCountRequest::find($generalCount->id));
    }

    public function test_eliminar_borrador_evaluado_borra_tambien_sus_lineas(): void
    {
        $product = $this->makeProduct();
        $generalCount = $this->makeGeneralCount(['estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $line = GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0,
        ]);
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/bodega/general-counts/{$generalCount->id}")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertNull(GeneralCountRequestLine::find($line->id));
    }

    public function test_eliminar_conteo_ya_enviado_a_aprobacion_retorna_422_y_no_lo_borra(): void
    {
        $generalCount = $this->makeGeneralCount(['estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]);
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/bodega/general-counts/{$generalCount->id}")
            ->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertNotNull(GeneralCountRequest::find($generalCount->id));
    }

    public function test_eliminar_conteo_aprobado_retorna_422(): void
    {
        $generalCount = $this->makeGeneralCount(['estado' => GeneralCountRequest::ESTADO_APROBADA]);
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/bodega/general-counts/{$generalCount->id}")
            ->assertStatus(422);
    }
}
