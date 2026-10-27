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
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-428/429/430/446/447/448/449/450 — Solicitud de ajuste. */
class AdjustmentRequestControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

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

    public function test_store_crea_una_linea_por_bodega_en_estado_pendiente(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [
                ['warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE, 'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg')],
                ['warehouse_id' => $merma->id, 'tipo' => 'Restar', 'cantidad' => 2, 'motivo' => AdjustmentRequestLine::MOTIVO_PRODUCTO_DANADO_ROTO, 'evidencia' => UploadedFile::fake()->create('foto2.jpg', 50, 'image/jpeg')],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'lines');

        $this->reMakeCurrent();
        $this->assertSame(2, AdjustmentRequestLine::where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)->count());
    }

    /** SCRUM-428 (rebote de Gerencia Test 2026-08-13) — descripcion/responsable persisten y se exponen. */
    public function test_store_persiste_descripcion_y_responsable(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_OTRO_ESPECIFICAR,
                'descripcion' => 'Se encontró una caja adicional sin registrar',
                'responsable' => 'Tecnico Servicios Test 2',
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ])->assertStatus(201);

        $this->reMakeCurrent();
        $line = AdjustmentRequestLine::first();
        $this->assertSame('Se encontró una caja adicional sin registrar', $line->descripcion);
        $this->assertSame('Tecnico Servicios Test 2', $line->responsable);
    }

    /** SCRUM-428 — Motivo deja de aceptar texto libre, solo las 6 opciones fijas. */
    public function test_store_con_motivo_fuera_del_catalogo_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => 'Un texto libre inventado',
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.0.motivo']);
    }

    // ─────────────────────────────────────────────────────────────────
    // SCRUM-797 RN5→RN7 — store() detecta duplicados (mismo tipo + cruzado con conteo general)
    // ─────────────────────────────────────────────────────────────────

    public function test_store_con_ajuste_pendiente_ya_existente_mismo_producto_bodega_retorna_409(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ])->assertStatus(201);

        $response = $this->withHeaders($headers)->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Restar', 'cantidad' => 2,
                'motivo' => AdjustmentRequestLine::MOTIVO_PRODUCTO_DANADO_ROTO,
                'evidencia' => UploadedFile::fake()->create('foto2.jpg', 50, 'image/jpeg'),
            ]],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('duplicates.0.warehouse_id', $central->id);
        $this->assertNotNull($response->json('duplicates.0.adjustment.id'));

        // Pre-QA (2026-08-26) — hallazgo real: sin confirmar, la línea original NUNCA debe
        // tocarse. El chequeo temprano (sin lock) ya lo garantiza en el camino normal, pero el
        // chequeo real de verdad vive DENTRO de la transacción (ver `store()`) — si alguna vez se
        // saltea el chequeo temprano (2 requests concurrentes, verificado en vivo con curl, no
        // reproducible de forma determinística en PHPUnit), la transacción debe seguir abortando
        // antes de reemplazar nada en silencio.
        $this->reMakeCurrent();
        $this->assertSame(AdjustmentRequestLine::ESTADO_PENDIENTE, AdjustmentRequestLine::where(
            'warehouse_id', $central->id,
        )->whereHas('request', fn ($q) => $q->where('catalog_product_id', $product->id))->sole()->estado);
    }

    public function test_store_con_confirm_replace_marca_la_linea_anterior_como_reemplazada(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $first = $this->withHeaders($headers)->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ])->assertStatus(201);
        $firstLineId = $first->json('lines.0');

        $second = $this->withHeaders($headers)->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'confirm_replace' => true,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Restar', 'cantidad' => 2,
                'motivo' => AdjustmentRequestLine::MOTIVO_PRODUCTO_DANADO_ROTO,
                'evidencia' => UploadedFile::fake()->create('foto2.jpg', 50, 'image/jpeg'),
            ]],
        ]);

        $second->assertStatus(201);
        $secondLineId = $second->json('lines.0');

        $this->reMakeCurrent();
        $firstLine = AdjustmentRequestLine::find($firstLineId);
        $this->assertSame(AdjustmentRequestLine::ESTADO_REEMPLAZADA, $firstLine->estado);
        $this->assertSame($secondLineId, $firstLine->replaced_by_id);
        $this->assertSame(AdjustmentRequestLine::ESTADO_PENDIENTE, AdjustmentRequestLine::find($secondLineId)->estado);
    }

    /**
     * Colisión CRUZADA (SCRUM-797, rebote 2026-08-27) — solo bloquea si ESE producto puntual ya
     * tiene una línea evaluada con diferencia real sin resolver en el conteo general, nunca por
     * "la bodega tiene cualquier conteo sin resolver" (versión revertida del 2026-08-26). Solo
     * advierte, nunca "reemplaza" la línea del conteo general por un ajuste puntual.
     */
    public function test_store_con_conteo_general_con_diferencia_en_el_mismo_producto_retorna_409(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $designerId = User::where('email', 'designer@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $product->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('duplicates.0.general_count.id', $generalCount->id);

        // Confirmar crea la línea de ajuste sin tocar el conteo general — es solo un aviso.
        $confirmed = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'confirm_replace' => true,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto3.jpg', 50, 'image/jpeg'),
            ]],
        ]);
        $confirmed->assertStatus(201);

        $this->reMakeCurrent();
        $this->assertSame(GeneralCountRequest::ESTADO_EVALUADO, $generalCount->fresh()->estado);
        $this->assertNull($generalCount->fresh()->replaced_by_id);
    }

    /** Producto DISTINTO en la misma bodega — el conflicto del otro producto nunca cruza. */
    public function test_store_con_conteo_general_con_diferencia_en_producto_distinto_no_bloquea(): void
    {
        $product = $this->makeProduct();
        $otroProducto = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $designerId = User::where('email', 'designer@atlantic.test')->first()->id;

        $generalCount = GeneralCountRequest::create([
            'warehouse_id' => $central->id, 'realizado_por' => $designerId,
            'estado' => GeneralCountRequest::ESTADO_EVALUADO,
        ]);
        GeneralCountRequestLine::create([
            'general_count_request_id' => $generalCount->id, 'catalog_product_id' => $otroProducto->id,
            'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3,
        ]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ])->assertStatus(201);
    }

    public function test_store_sin_duplicados_no_pide_confirmacion(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [[
                'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5,
                'motivo' => AdjustmentRequestLine::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
                'evidencia' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ]],
        ])->assertStatus(201);
    }

    public function test_store_sin_evidencia_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [['warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo']],
        ])->assertStatus(422);
    }

    public function test_aprobar_ajusta_el_inventario_automaticamente(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo fisico',
            'evidencia_storage_key' => 'bodega/test.jpg', 'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('estado', AdjustmentRequestLine::ESTADO_APROBADA);

        $this->reMakeCurrent();
        $this->assertSame(15, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $movement = InventoryMovement::where('catalog_product_id', $product->id)->first();
        $this->assertSame(InventoryMovement::TIPO_AJUSTE, $movement->tipo);
        $this->assertSame(5, $movement->cantidad);
    }

    public function test_rechazar_no_toca_el_inventario(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo fisico',
            'evidencia_storage_key' => 'bodega/test.jpg', 'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/reject", ['motivo_rechazo' => 'No corresponde']);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', AdjustmentRequestLine::ESTADO_RECHAZADA);

        $this->reMakeCurrent();
        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::where('catalog_product_id', $product->id)->count());
    }

    public function test_rechazar_sin_motivo_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo fisico',
            'evidencia_storage_key' => 'bodega/test.jpg', 'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_aprobar_no_siendo_aprobador_retorna_403(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo fisico',
            'evidencia_storage_key' => 'bodega/test.jpg', 'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        // `gerencia@atlantic.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Gerencia (2026-08-22), usar `multi@atlantic.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $this->grantBodega('multi@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/approve")
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(AdjustmentRequestLine::ESTADO_PENDIENTE, AdjustmentRequestLine::find($line->id)->estado);
    }

    public function test_aprobar_solicitud_ya_resuelta_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo fisico',
            'evidencia_storage_key' => 'bodega/test.jpg', 'estado' => AdjustmentRequestLine::ESTADO_APROBADA,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/approve")
            ->assertStatus(422);
    }

    public function test_index_filtra_por_estado_y_una_fila_por_linea(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $adjustmentRequest = AdjustmentRequest::create([
            'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
        ]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $adjustmentRequest->id, 'warehouse_id' => $central->id,
            'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'A', 'evidencia_storage_key' => 'x.jpg',
            'estado' => AdjustmentRequestLine::ESTADO_APROBADA,
        ]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $adjustmentRequest->id, 'warehouse_id' => $merma->id,
            'tipo' => 'Restar', 'cantidad' => 2, 'motivo' => 'B', 'evidencia_storage_key' => 'y.jpg',
            'estado' => AdjustmentRequestLine::ESTADO_RECHAZADA,
        ]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $all = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/adjustment-requests');
        $all->assertStatus(200);
        $all->assertJsonCount(2, 'data');

        $rejected = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/adjustment-requests?estado=Rechazada');
        $rejected->assertJsonCount(1, 'data');
        $rejected->assertJsonPath('data.0.motivo', 'B');
    }

    /**
     * SCRUM-429 (rebote de Gerencia Test 2026-08-13) — `can_approve` es lo que el frontend usa
     * para OCULTAR Aprobar/Rechazar por completo (no solo el candado decorativo de antes).
     */
    public function test_index_expone_can_approve_true_para_mark(): void
    {
        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/adjustment-requests');

        $response->assertStatus(200);
        $response->assertJsonPath('can_approve', true);
    }

    public function test_index_expone_can_approve_false_para_bodega(): void
    {
        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);
        $this->grantBodega('designer@atlantic.test');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/adjustment-requests');

        $response->assertStatus(200);
        $response->assertJsonPath('can_approve', false);
    }

    public function test_search_products_encuentra_por_referencia(): void
    {
        $this->makeProduct(['reference' => 'CRISTAL-IMPERIAL']);
        $this->makeProduct(['reference' => 'BAUHAUS']);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/adjustment-requests/products/search?search=CRISTAL');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reference', 'CRISTAL-IMPERIAL');
    }

    public function test_search_products_vacio_sin_termino(): void
    {
        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/adjustment-requests/products/search')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    /** Hallazgo de Pre-QA 2026-07-21 (MEDIO): el backend aceptaba warehouse_id repetido en la misma solicitud. */
    public function test_store_con_bodega_repetida_retorna_422(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $product->id,
            'lines' => [
                ['warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'A', 'evidencia' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg')],
                ['warehouse_id' => $central->id, 'tipo' => 'Restar', 'cantidad' => 1, 'motivo' => 'B', 'evidencia' => UploadedFile::fake()->create('b.jpg', 50, 'image/jpeg')],
            ],
        ])->assertStatus(422);
    }

    /**
     * SCRUM-428 RN1 — cantidad actual por cada bodega OPERATIVA para el selector del modal (7 físicas originales +
     * "Reserva Servicios" desde Grupo D parte 2, REQ-291/SCRUM-361 = 8). SCRUM-990: "Bodega Zona Libre" es de solo
     * consulta (catálogo de Lafayette, no inventario de Atlantic) y ya no aparece: quedan 7.
     */
    public function test_product_warehouse_stock_expone_cantidad_por_bodega(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 12]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/adjustment-requests/products/{$product->id}/warehouse-stock");

        $response->assertStatus(200);
        $response->assertJsonCount(7, 'warehouses');
        $this->assertNull(collect($response->json('warehouses'))->firstWhere('name', 'Bodega Zona Libre'), 'Zona Libre (solo consulta) no aparece en el desglose de stock');
        $centralRow = collect($response->json('warehouses'))->firstWhere('warehouse_id', $central->id);
        $this->assertSame(12, $centralRow['quantity']);
        $otherRow = collect($response->json('warehouses'))->firstWhere('name', 'Merma');
        $this->assertSame(0, $otherRow['quantity']);
    }

    /** SCRUM-428 RN5 — unidades comprometidas (por_servir) para la advertencia de "Restar". */
    public function test_product_warehouse_stock_expone_por_servir(): void
    {
        $product = $this->makeProduct();
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $salesProject = SalesProject::create(['name' => 'Proyecto Demo']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            'total_amount' => 40,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 4, 'unit_cost' => 10, 'subtotal' => 40, 'sales_project_id' => $salesProject->id,
        ]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/adjustment-requests/products/{$product->id}/warehouse-stock");

        $response->assertStatus(200);
        $response->assertJsonPath('por_servir', 4);
    }

    /** SCRUM-807 (REQ-632, Epic SCRUM-788) — lectura sensible: stock por bodega antes de ajustar. */
    public function test_product_warehouse_stock_registra_bodega_adjustment_warehouse_stock_viewed(): void
    {
        $product = $this->makeProduct();

        $this->grantBodega('designer@atlantic.test');
        $userId = User::where('email', 'designer@atlantic.test')->firstOrFail()->id;
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/adjustment-requests/products/{$product->id}/warehouse-stock")
            ->assertStatus(200);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::BODEGA_ADJUSTMENT_WAREHOUSE_STOCK_VIEWED)
            ->where('user_id', $userId)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=bodega.adjustment_request.warehouse_stock_viewed');
        $this->assertSame('catalog_product', $log->entity_type);
        $this->assertSame((string) $product->id, $log->entity_id);
    }

    /**
     * Hallazgo de Pre-QA 2026-07-21 (CRÍTICO): 5 approve concurrentes sobre la misma línea
     * aplicaban el ajuste 3 veces. No es reproducible en un solo proceso PHPUnit sincrónico
     * (el lock solo importa entre conexiones/procesos distintos, aquí todo corre en la misma
     * transacción de test) -- este test cubre el caso secuencial (ya cubierto por
     * test_aprobar_solicitud_ya_resuelta_retorna_422) y confirma que el resultado de la
     * transacción refleja el estado correcto. La verificación real de concurrencia se hizo
     * con requests HTTP concurrentes contra el servidor real corriendo (Pre-QA), no acá.
     */
    public function test_aprobar_dos_veces_seguidas_solo_aplica_el_ajuste_una_vez(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 10]);

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id, 'solicitado_por' => User::where('email', 'designer@atlantic.test')->first()->id,
            ])->id,
            'warehouse_id' => $central->id, 'tipo' => 'Sumar', 'cantidad' => 5, 'motivo' => 'Conteo',
            'evidencia_storage_key' => 'x.jpg', 'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $this->grantBodega('management@atlantic.test', ['bodega.approve']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/approve")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/adjustment-requests/lines/{$line->id}/approve")->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(15, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::where('catalog_product_id', $product->id)->count());
    }
}
