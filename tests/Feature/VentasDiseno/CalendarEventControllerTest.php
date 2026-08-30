<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CalendarEvent;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-058 — endpoints legacy de `calendar_events` (calendario nativo, decisión de Luis
 * 2026-07-13). Desde 2026-07-21 el frontend ya no los usa (la tarjeta "Mi calendario" lee Outlook
 * real vía OutlookCalendarController, ver CalendarControllerTest) — quedan sin ruteo hacia el
 * frontend pero sin borrar (cambio destructivo de BD requiere confirmación explícita de Luis).
 * Este test sigue vigente porque los endpoints en sí siguen existiendo y funcionando.
 */
class CalendarEventControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoEdit(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $permissions = array_merge(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'], $extra);
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    public function test_crear_evento_propio(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/calendar-events', [
                'title' => 'Visita técnica', 'start_at' => '2026-07-20T10:00:00-05:00',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('title', 'Visita técnica');
        $response->assertJsonPath('owner.name', 'Designer Demo');
    }

    public function test_crear_evento_a_nombre_de_otro_retorna_403_para_disenador(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/calendar-events', [
                'owner_id' => $managerId, 'title' => 'Reunión', 'start_at' => '2026-07-20T10:00:00-05:00',
            ])
            ->assertStatus(403);
    }

    public function test_gerencia_puede_crear_evento_a_nombre_de_otro_vendedor(): void
    {
        $this->grantVentasDisenoEdit('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/calendar-events', [
                'owner_id' => $designerId, 'title' => 'Seguimiento', 'start_at' => '2026-07-20T10:00:00-05:00',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('owner.name', 'Designer Demo');
    }

    public function test_listar_eventos_propios(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        CalendarEvent::create(['owner_id' => $designerId, 'title' => 'Mío', 'start_at' => now()]);
        CalendarEvent::create(['owner_id' => $managerId, 'title' => 'Ajeno', 'start_at' => now()]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar-events')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mío'));
        $this->assertFalse($titles->contains('Ajeno'));
    }

    public function test_editar_evento_ajeno_retorna_403_para_disenador(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $event = CalendarEvent::create(['owner_id' => $managerId, 'title' => 'Ajeno', 'start_at' => now()]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/calendar-events/{$event->id}", [
                'title' => 'Editado', 'start_at' => '2026-07-20T10:00:00-05:00',
            ])
            ->assertStatus(403);
    }

    public function test_borrar_evento_propio(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $event = CalendarEvent::create(['owner_id' => $designerId, 'title' => 'Mío', 'start_at' => now()]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/calendar-events/{$event->id}")
            ->assertStatus(204);
    }
}
