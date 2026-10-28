<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Services\QuoteItemPhotoService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

// Cotización-B (REQ-035) — partidas.
class QuotePartControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-public');
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    public function test_crear_partida_sin_nombre_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", [])
            ->assertStatus(422);
    }

    public function test_crear_y_renombrar_partida(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida 1'])
            ->assertStatus(201);
        $partId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$partId}", ['name' => 'Partida Renombrada'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Partida Renombrada');
    }

    /**
     * SCRUM-953 — regresión: `Quote::parts()` no tenía `orderBy('position')`, así que el orden de
     * lectura dependía del plan de consulta de Postgres, no del orden de creación. Nombres a
     * propósito fuera de orden alfabético (Z, A, B) para que un ordenamiento alfabético accidental
     * también haga fallar este test.
     */
    public function test_partidas_conservan_el_orden_de_creacion(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        foreach (['Partida Z', 'Partida A', 'Partida B'] as $name) {
            $this->withHeaders($headers)
                ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => $name])
                ->assertStatus(201);
        }

        $show = $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200);
        $names = array_column($show->json('parts'), 'name');
        $this->assertSame(['Partida Z', 'Partida A', 'Partida B'], $names);
    }

    /** SCRUM-953 — una partida duplicada nunca se agrupa junto a la original, queda al final. */
    public function test_partida_duplicada_queda_al_final_no_junto_a_la_original(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $partAId = $this->withHeaders($headers)
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida A'])
            ->assertStatus(201)->json('id');
        $this->withHeaders($headers)
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida B'])
            ->assertStatus(201);
        $this->withHeaders($headers)
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$partAId}/duplicate")
            ->assertStatus(201);

        $show = $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200);
        $names = array_column($show->json('parts'), 'name');
        $this->assertSame(['Partida A', 'Partida B', 'Partida A (copia)'], $names);
    }

    public function test_borrar_partida_en_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $quote = Quote::create(['owner_id' => $managerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}")
            ->assertStatus(403);
    }

    // ── SCRUM-734 (RN2.2, Senior Review retroactivo 2026-08-06) ─────────────────

    public function test_crear_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId, 'folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida Nueva'])
            ->assertStatus(422);
    }

    public function test_renombrar_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $quote->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}", ['name' => 'Intento de Renombrar'])
            ->assertStatus(422);

        $this->assertStringContainsString('no se puede editar directamente', (string) $response->json('message'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('Partida 1', $part->fresh()?->name);
    }

    // ── SCRUM-849.7 — duplicar partida ──────────────────────────────────────────

    public function test_duplicar_partida_copia_items_e_independiza_la_original(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create([
            'is_custom' => true, 'reference' => 'REF-1', 'description' => 'Producto A',
            'quantity' => 2, 'unit_price' => 100,
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('name', 'Partida 1 (copia)');
        $copyId = $response->json('id');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $copy = $quote->fresh()->parts()->with('items')->find($copyId);
        $this->assertNotSame($part->id, $copy->id);
        $this->assertCount(1, $copy->items);
        $this->assertSame('REF-1', $copy->items->first()->reference);
        $this->assertSame(100.0, (float) $copy->items->first()->unit_price);

        // La original sigue teniendo su único ítem — duplicar no la modificó.
        $this->assertCount(1, $part->fresh()->items);
    }

    public function test_duplicar_partida_copia_la_foto_del_item_a_una_key_propia(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $item = $part->items()->create([
            'is_custom' => true, 'reference' => 'REF-1', 'description' => 'Producto A',
            'quantity' => 1, 'unit_price' => 100,
        ]);
        app(QuoteItemPhotoService::class)->uploadPhoto($item, UploadedFile::fake()->image('foto.jpg', 400, 400));
        $originalKey = $item->fresh()->photo_key;

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $copyItem = $quote->fresh()->parts()->with('items')->find($response->json('id'))->items->first();
        $this->assertNotNull($copyItem->photo_key);
        $this->assertNotSame($originalKey, $copyItem->photo_key);
        Storage::disk('s3-public')->assertExists($copyItem->photo_key);
        // Rebote de Gerencia (2026-09-11) — root cause real fue que copyPhotoTo() usaba
        // Storage::disk()->copy(), que en el S3 real (no en este fake) intenta leer el ACL del
        // archivo origen y fallaba en silencio contra la policy IAM de la app, dejando photo_key
        // en BD apuntando a una key que nunca se creó en S3 (ver docblock de copyPhotoTo()). Este
        // fake no reproduce ese fallo de permisos (el driver local no tiene ACL/visibility), así
        // que la única guarda real contra una regresión es comparar los bytes: si copyPhotoTo()
        // alguna vez vuelve a un mecanismo que puede "tener éxito" sin escribir contenido real,
        // este assert lo detecta.
        $this->assertSame(
            Storage::disk('s3-public')->get($originalKey),
            Storage::disk('s3-public')->get($copyItem->photo_key),
        );

        // Reemplazar la foto del original NO debe romper la de la copia (key independiente).
        app(QuoteItemPhotoService::class)->uploadPhoto($item->fresh(), UploadedFile::fake()->image('nueva.png', 400, 400));
        Storage::disk('s3-public')->assertMissing($originalKey);
        Storage::disk('s3-public')->assertExists($copyItem->photo_key);
    }

    /**
     * Senior Review (2026-09-11, rebote SCRUM-849.7) — hallazgo MEDIO: el try/catch de
     * `QuotePartController::duplicate()` alrededor de `copyPhotoTo()` (agregado para que un fallo
     * de S3/foto no tumbe la duplicación completa de la partida) no tenía ningún test que forzara
     * esa rama de falla — ni siquiera el spec de Playwright, que solo repetía el camino feliz pese
     * a su nombre. `QuoteItemPhotoService` es `final` (no se puede mockear con Mockery para
     * bindearlo en el container) — se fuerza el fallo REAL borrando la key de `photo_key` del
     * fake de Storage antes de duplicar, para que `copyPhotoTo()` dispare exactamente el
     * `RuntimeException` real de "Source photo not found" (mismo código que corre en producción,
     * no un doble mockeado) y confirmar que la partida se duplica igual (productos/referencias/
     * cantidades, lo crítico del ticket) con el ítem afectado sin foto en vez de un 500.
     */
    public function test_duplicar_partida_si_falla_la_copia_de_foto_no_tumba_la_duplicacion(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $item = $part->items()->create([
            'is_custom' => true, 'reference' => 'REF-1', 'description' => 'Producto A',
            'quantity' => 1, 'unit_price' => 100,
        ]);
        app(QuoteItemPhotoService::class)->uploadPhoto($item, UploadedFile::fake()->image('foto.jpg', 400, 400));
        // Dato corrupto forzado: photo_key sigue en BD pero el archivo ya no existe en S3 — el
        // mismo escenario real que dispararía "Source photo not found" en copyPhotoTo().
        Storage::disk('s3-public')->delete($item->fresh()->photo_key);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $copyItem = $quote->fresh()->parts()->with('items')->find($response->json('id'))->items->first();
        $this->assertNotNull($copyItem, 'la partida y su ítem deben duplicarse igual pese al fallo de foto.');
        $this->assertSame('REF-1', $copyItem->reference);
        $this->assertNull($copyItem->photo_key, 'sin foto en vez de un 500 — el fallo de copyPhotoTo() no debe tumbar la duplicación.');
    }

    public function test_duplicar_partida_en_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $quote = Quote::create(['owner_id' => $managerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(403);
    }

    public function test_duplicar_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $quote->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(422);
    }

    public function test_borrar_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $quote->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}")
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNotNull($part->fresh());
    }

    // ── SCRUM-1161 — reordenar partidas y productos por drag and drop ───────────

    /** @return array{0: Quote, 1: array<string, string>} */
    private function quoteWithToken(string $owner = 'vendedor@atlantic.test'): array
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $quote = Quote::create(['owner_id' => $this->userIdByEmail($owner)]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        // El login suelta el tenant actual; estos tests siguen creando partidas/ítems después.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return [$quote, $this->authHeaders($token)];
    }

    /** @return list<string> */
    private function partNames(Quote $quote, array $headers): array
    {
        $show = $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200);

        return array_column($show->json('parts'), 'name');
    }

    public function test_reordenar_partidas_persiste_y_se_refleja_al_reabrir(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $a = $quote->parts()->create(['name' => 'A', 'position' => 0]);
        $b = $quote->parts()->create(['name' => 'B', 'position' => 1]);
        $c = $quote->parts()->create(['name' => 'C', 'position' => 2]);

        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/reorder", ['part_ids' => [$c->id, $a->id, $b->id]])
            ->assertStatus(200)
            ->assertJsonPath('part_ids', [$c->id, $a->id, $b->id]);

        $this->assertSame(['C', 'A', 'B'], $this->partNames($quote, $headers));

        // La partida creada después sigue entrando al final (la lógica de creación no cambia).
        $this->withHeaders($headers)->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'D'])->assertStatus(201);
        $this->assertSame(['C', 'A', 'B', 'D'], $this->partNames($quote, $headers));
    }

    public function test_reordenar_partidas_con_lista_distinta_o_ajena_retorna_422(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $a = $quote->parts()->create(['name' => 'A', 'position' => 0]);
        $b = $quote->parts()->create(['name' => 'B', 'position' => 1]);
        $otra = Quote::create(['owner_id' => $this->userIdByEmail('vendedor@atlantic.test')])->parts()->create(['name' => 'X', 'position' => 0]);

        $url = "/api/ventas-diseno/quotes/{$quote->id}/parts/reorder";
        foreach ([[$a->id], [$a->id, $b->id, $otra->id], [$a->id, $otra->id], [$a->id, $a->id], [], ['x' => $a->id, 'y' => $b->id]] as $ids) {
            $this->withHeaders($headers)->putJson($url, ['part_ids' => $ids])->assertStatus(422);
        }
        $this->assertSame(['A', 'B'], $this->partNames($quote, $headers));
    }

    public function test_reordenar_partidas_en_cotizacion_ajena_o_confirmada_retorna_403_o_422(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $ajena = Quote::create(['owner_id' => $this->userIdByEmail('management@atlantic.test')]);
        $p = $ajena->parts()->create(['name' => 'A', 'position' => 0]);
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$ajena->id}/parts/reorder", ['part_ids' => [$p->id]])
            ->assertStatus(403);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $a = $quote->parts()->create(['name' => 'A', 'position' => 0]);
        $b = $quote->parts()->create(['name' => 'B', 'position' => 1]);
        $quote->update(['folio' => 'COT-2026-0098', 'confirmed_at' => now()]);
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/reorder", ['part_ids' => [$b->id, $a->id]])
            ->assertStatus(422);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame([0, 1], [$a->fresh()->position, $b->fresh()->position]);
    }

    /** @return list<string> */
    private function itemRefs(Quote $quote, array $headers, int $partId): array
    {
        $show = $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200);
        $part = collect($show->json('parts'))->firstWhere('id', $partId);

        return array_column($part['items'], 'reference');
    }

    public function test_reordenar_productos_de_la_partida_persiste_y_el_nuevo_entra_al_final(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $part = $quote->parts()->create(['name' => 'P', 'position' => 0]);
        $items = [];
        foreach (['R1', 'R2', 'R3', 'R4', 'R5'] as $ref) {
            $items[$ref] = $part->items()->create(['is_custom' => true, 'reference' => $ref, 'description' => $ref, 'quantity' => 1, 'unit_price' => 10]);
        }
        $this->assertSame(['R1', 'R2', 'R3', 'R4', 'R5'], $this->itemRefs($quote, $headers, $part->id));

        // El 5 pasa a la posición 1.
        $order = [$items['R5']->id, $items['R1']->id, $items['R2']->id, $items['R3']->id, $items['R4']->id];
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items/reorder", ['item_ids' => $order])
            ->assertStatus(200);
        $this->assertSame(['R5', 'R1', 'R2', 'R3', 'R4'], $this->itemRefs($quote, $headers, $part->id));

        // Editar la cantidad no mueve el ítem y uno nuevo entra al final (orden de ingreso intacto).
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items/{$items['R1']->id}", [
                'is_custom' => true, 'reference' => 'R1', 'description' => 'R1', 'quantity' => 9, 'unit_price' => 10,
            ])
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items", [
                'is_custom' => true, 'reference' => 'R6', 'description' => 'R6', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);
        $this->assertSame(['R5', 'R1', 'R2', 'R3', 'R4', 'R6'], $this->itemRefs($quote, $headers, $part->id));
    }

    public function test_reordenar_productos_no_acepta_items_de_otra_partida(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $p1 = $quote->parts()->create(['name' => 'P1', 'position' => 0]);
        $p2 = $quote->parts()->create(['name' => 'P2', 'position' => 1]);
        $i1 = $p1->items()->create(['is_custom' => true, 'reference' => 'A', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1]);
        $i2 = $p1->items()->create(['is_custom' => true, 'reference' => 'B', 'description' => 'B', 'quantity' => 1, 'unit_price' => 1]);
        $x = $p2->items()->create(['is_custom' => true, 'reference' => 'X', 'description' => 'X', 'quantity' => 1, 'unit_price' => 1]);

        $url = "/api/ventas-diseno/quotes/{$quote->id}/parts/{$p1->id}/items/reorder";
        foreach ([[$i2->id, $x->id], [$i1->id, $i2->id, $x->id], [$i1->id], [$i1->id, $i1->id], ['x' => $i1->id, 'y' => $i2->id]] as $ids) {
            $this->withHeaders($headers)->putJson($url, ['item_ids' => $ids])->assertStatus(422);
        }
        $this->assertSame(['A', 'B'], $this->itemRefs($quote, $headers, $p1->id));
        $this->assertSame(['X'], $this->itemRefs($quote, $headers, $p2->id));
    }

    public function test_reordenar_productos_en_cotizacion_ajena_o_confirmada(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $ajena = Quote::create(['owner_id' => $this->userIdByEmail('management@atlantic.test')]);
        $pa = $ajena->parts()->create(['name' => 'P', 'position' => 0]);
        $ia = $pa->items()->create(['is_custom' => true, 'reference' => 'A', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1]);
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$ajena->id}/parts/{$pa->id}/items/reorder", ['item_ids' => [$ia->id]])
            ->assertStatus(403);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $p = $quote->parts()->create(['name' => 'P', 'position' => 0]);
        $i1 = $p->items()->create(['is_custom' => true, 'reference' => 'A', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1]);
        $i2 = $p->items()->create(['is_custom' => true, 'reference' => 'B', 'description' => 'B', 'quantity' => 1, 'unit_price' => 1]);
        $quote->update(['folio' => 'COT-2026-0097', 'confirmed_at' => now()]);
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$p->id}/items/reorder", ['item_ids' => [$i2->id, $i1->id]])
            ->assertStatus(422);
    }

    public function test_duplicar_partida_conserva_el_orden_manual_de_los_productos(): void
    {
        [$quote, $headers] = $this->quoteWithToken();
        $part = $quote->parts()->create(['name' => 'P', 'position' => 0]);
        $a = $part->items()->create(['is_custom' => true, 'reference' => 'A', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1]);
        $b = $part->items()->create(['is_custom' => true, 'reference' => 'B', 'description' => 'B', 'quantity' => 1, 'unit_price' => 1]);
        $this->withHeaders($headers)
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items/reorder", ['item_ids' => [$b->id, $a->id]])
            ->assertStatus(200);

        $copyId = $this->withHeaders($headers)
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/duplicate")
            ->assertStatus(201)->json('id');

        $this->assertSame(['B', 'A'], $this->itemRefs($quote, $headers, $copyId));
    }
}
