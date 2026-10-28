<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1126 — varios proveedores por producto, edición de Proveedor/Stock mínimo y Nueva Orden por proveedor. */
class InventoryMultiProviderTest extends FeatureTestCase
{
    private const EMAIL = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        Storage::fake('s3-public');
    }

    /** El request HTTP olvida el tenant al terminar; las aserciones sobre modelos lo restablecen. */
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(array $perms = ['compras.write', 'compras.edit', 'compras.read']): string
    {
        DB::table('atlantic_auth.users')->where('email', self::EMAIL)
            ->update(['extra_permissions' => json_encode($perms)]);

        return $this->loginAs(self::EMAIL, 'Password123!', 'designer');
    }

    private function provider(string $name): Provider
    {
        return Provider::create(['name' => $name, 'currency' => 'USD']);
    }

    /** @param array<string, mixed> $o */
    private function product(array $o = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Artículo', 'description' => 'Artículo',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 10, 'is_active' => true, ...$o,
        ]);
    }

    /** @return array<string, mixed> */
    private function createPayload(array $o = []): array
    {
        return [
            'reference' => 'REF-'.uniqid(), 'factory_reference' => 'FAB-'.uniqid(), 'name' => 'Producto nuevo',
            'description' => 'x', 'brand' => 'M', 'barcode' => '7501234567890', 'price_full' => 30, 'cost' => 15,
            'other_cost' => 2.5, 'reorder_point' => 5, 'category' => 'general', 'rotation' => 'media',
            'link' => 'https://proveedor.example.com/ficha.pdf', 'photo' => UploadedFile::fake()->image('f.jpg', 400, 400),
            ...$o,
        ];
    }

    /** @return list<int> */
    private function pivotIds(CatalogProduct $p): array
    {
        $this->tenant();
        return DB::table(CatalogProduct::providersPivotTable())->where('catalog_product_id', $p->id)
            ->orderBy('provider_id')->pluck('provider_id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_crear_producto_con_varios_proveedores_guarda_pivote_y_principal(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $token = $this->token();

        $res = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->createPayload(['provider_ids' => [$b->id, $a->id]]))
            ->assertStatus(201);
        $this->tenant();

        $res->assertJsonPath('provider_id', $b->id);
        $this->assertSame([$b->id, $a->id], array_column($res->json('providers'), 'id'));
        $this->assertSame([$a->id, $b->id], $this->pivotIds(CatalogProduct::findOrFail($res->json('id'))));
    }

    public function test_crear_producto_con_proveedor_unico_sigue_funcionando_y_llena_pivote(): void
    {
        $a = $this->provider('A');
        $token = $this->token();

        $res = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->createPayload(['provider_id' => $a->id]))
            ->assertStatus(201);
        $this->tenant();

        $this->assertSame([$a->id], $this->pivotIds(CatalogProduct::findOrFail($res->json('id'))));
    }

    public function test_crear_producto_con_proveedor_inexistente_en_la_lista_retorna_422(): void
    {
        $a = $this->provider('A');
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->createPayload(['provider_ids' => [$a->id, 999999]]))
            ->assertStatus(422)->assertJsonValidationErrors(['provider_ids.1']);
    }

    public function test_editar_agrega_y_quita_proveedores_y_persiste_al_consultar(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $c = $this->provider('C');
        $p = $this->product(['provider_id' => $a->id]);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id, $b->id, $c->id]])
            ->assertStatus(200)->assertJsonPath('provider_id', $a->id);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$p->id}")
            ->assertJsonPath('providers.0.id', $a->id)
            ->assertJsonCount(3, 'providers');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$c->id]])
            ->assertStatus(200)->assertJsonPath('provider_id', $c->id);

        $this->assertSame([$c->id], $this->pivotIds($p));
    }

    public function test_editar_proveedor_y_stock_minimo_se_reflejan_en_detalle(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $p = $this->product(['provider_id' => $a->id, 'reorder_point' => 3]);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_id' => $b->id, 'reorder_point' => 12])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/inventory/{$p->id}")
            ->assertJsonPath('provider_id', $b->id)->assertJsonPath('reorder_point', 12)
            ->assertJsonPath('provider_name', 'B');
        $this->assertContains($b->id, $this->pivotIds($p));
    }

    public function test_editar_con_lista_vacia_retorna_422_y_no_toca_la_pivote(): void
    {
        $a = $this->provider('A');
        $p = $this->product(['provider_id' => $a->id]);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['provider_ids']);
        $this->tenant();
        $this->assertSame($a->id, $p->fresh()->provider_id);
    }

    public function test_crear_con_lista_vacia_retorna_422(): void
    {
        $a = $this->provider('A');
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->createPayload(['provider_id' => $a->id, 'provider_ids' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['provider_ids']);
    }

    public function test_editar_con_proveedor_inexistente_en_la_lista_retorna_422(): void
    {
        $a = $this->provider('A');
        $p = $this->product(['provider_id' => $a->id]);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id, 999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['provider_ids.1']);
        $this->assertSame([], $this->pivotIds($p)); // no se tocó la pivote
    }

    /** Decisión: ids repetidos en provider_ids se RECHAZAN (regla `distinct`), no se deduplican en silencio. */
    public function test_editar_con_proveedores_duplicados_en_la_lista_retorna_422(): void
    {
        $a = $this->provider('A');
        $p = $this->product(['provider_id' => $a->id]);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id, $a->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['provider_ids.0', 'provider_ids.1']);
    }

    public function test_cambiar_principal_sin_reenviar_factory_reference_colisionando_retorna_422_y_no_500(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $this->product(['provider_id' => $b->id, 'factory_reference' => 'FX-9']);
        $p = $this->product(['provider_id' => $a->id, 'factory_reference' => 'FX-9']);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors(['factory_reference']);
        $this->tenant();
        $this->assertSame($a->id, CatalogProduct::findOrFail($p->id)->provider_id);
    }

    public function test_asociar_como_secundario_a_proveedor_con_la_misma_factory_reference_retorna_422(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $this->product(['provider_id' => $b->id, 'factory_reference' => 'FX-5']);
        $p = $this->product(['provider_id' => $a->id, 'factory_reference' => 'FX-5']);
        $token = $this->token();

        // el frontend siempre reenvía factory_reference (sin cambio): igual se valida contra el proveedor NUEVO
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id, $b->id], 'factory_reference' => 'FX-5'])
            ->assertStatus(422)->assertJsonValidationErrors(['factory_reference']);
        $this->assertSame([], $this->pivotIds($p));
    }

    public function test_factory_reference_de_producto_secundario_del_proveedor_tambien_colisiona(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $multi = $this->product(['provider_id' => $a->id, 'factory_reference' => 'FX-7']);
        $multi->syncProviders([$a->id, $b->id], $a->id); // B es secundario de $multi
        $other = $this->product(['provider_id' => $b->id, 'factory_reference' => 'FX-8']);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$other->id}", ['factory_reference' => 'FX-7'])
            ->assertStatus(422)->assertJsonValidationErrors(['factory_reference']);

        // crear un producto nuevo para B con esa referencia también falla
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->createPayload(['provider_id' => $b->id, 'factory_reference' => 'FX-7']))
            ->assertStatus(422)->assertJsonValidationErrors(['factory_reference']);
    }

    public function test_editar_otros_campos_no_se_bloquea_por_duplicado_historico_de_factory_reference(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $this->product(['provider_id' => $b->id, 'factory_reference' => 'FX-3']);
        $p = $this->product(['provider_id' => $a->id, 'factory_reference' => 'FX-3']);
        $p->syncProviders([$a->id, $b->id], $a->id); // duplicado histórico ya presente
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id, $b->id], 'factory_reference' => 'FX-3', 'reorder_point' => 4])
            ->assertStatus(200)->assertJsonPath('reorder_point', 4);
    }

    public function test_provider_id_null_sin_lista_limpia_la_pivote(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $p = $this->product(['provider_id' => $a->id]);
        $p->syncProviders([$a->id, $b->id], $a->id);
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_id' => null])
            ->assertStatus(200)->assertJsonPath('provider_id', null)->assertJsonCount(0, 'providers');
        $this->assertSame([], $this->pivotIds($p));
    }

    public function test_editar_requiere_permiso_compras_edit(): void
    {
        $a = $this->provider('A');
        $p = $this->product(['provider_id' => $a->id]);
        $token = $this->token(['compras.read']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$a->id]])
            ->assertStatus(403);
    }

    public function test_nueva_orden_lista_productos_por_cada_proveedor_asociado_y_respeta_cambios(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $c = $this->provider('C');
        $multi = $this->product(['name' => 'Multi', 'provider_id' => $a->id]);
        $multi->syncProviders([$a->id, $b->id], $a->id);
        $soloC = $this->product(['name' => 'SoloC', 'provider_id' => $c->id]);
        $legacy = $this->product(['name' => 'Legacy', 'provider_id' => $b->id]); // sin fila en pivote (otro camino de alta)
        $token = $this->token();

        $names = fn (int $pid): array => collect($this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$pid}")->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Multi'], $names($a->id));
        $this->assertSame(['Legacy', 'Multi'], $names($b->id));
        $this->assertSame(['SoloC'], $names($c->id));

        // quitar B del producto => deja de aparecer para B
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$multi->id}", ['provider_ids' => [$a->id]])->assertOk();
        $this->assertSame(['Legacy'], $names($b->id));
        $this->assertSame(['SoloC'], $names($c->id)); // el cambio en Multi no afecta a otros proveedores
    }

    public function test_cambiar_asociaciones_no_toca_ordenes_historicas(): void
    {
        $a = $this->provider('A');
        $b = $this->provider('B');
        $p = $this->product(['provider_id' => $a->id]);
        $orderCount = PurchaseOrder::count();
        $token = $this->token();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$p->id}", ['provider_ids' => [$b->id]])->assertOk();

        $this->tenant();
        $this->assertSame($orderCount, PurchaseOrder::count());
    }
}
