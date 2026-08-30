<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// Cotización-B (REQ-035) — partidas.
class QuotePartControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    public function test_crear_partida_sin_nombre_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", [])
            ->assertStatus(422);
    }

    public function test_crear_y_renombrar_partida(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida 1'])
            ->assertStatus(201);
        $partId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$partId}", ['name' => 'Partida Renombrada'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Partida Renombrada');
    }

    public function test_borrar_partida_en_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $managerId = $this->userIdByEmail('management@illuminations.test');
        $quote = Quote::create(['owner_id' => $managerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}")
            ->assertStatus(403);
    }

    // ── SCRUM-734 (RN2.2, Senior Review retroactivo 2026-08-06) ─────────────────

    public function test_crear_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create(['owner_id' => $designerId, 'folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts", ['name' => 'Partida Nueva'])
            ->assertStatus(422);
    }

    public function test_renombrar_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $quote->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}", ['name' => 'Intento de Renombrar'])
            ->assertStatus(422);

        $this->assertStringContainsString('no se puede editar directamente', (string) $response->json('message'));
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame('Partida 1', $part->fresh()?->name);
    }

    public function test_borrar_partida_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $quote->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}")
            ->assertStatus(422);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertNotNull($part->fresh());
    }
}
