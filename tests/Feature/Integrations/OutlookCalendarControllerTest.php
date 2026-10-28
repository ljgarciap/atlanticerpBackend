<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-66/177 — endpoint compartido de solo lectura contra Microsoft Graph (app-only). Cubre
 * ambos módulos que lo usan (ventas_diseno vía /ventas-diseno/calendar, compras vía
 * /compras/calendar) porque comparten el mismo controller — el módulo solo cambia el gate de
 * team-view (canViewTeamV2).
 */
class OutlookCalendarControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** Devuelve un evento cuyo título delata qué email pidió AtlanticERP — así se puede verificar a quién se consultó. */
    private function fakeGraphEchoingRequestedEmail(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*' => function ($request) {
                preg_match('#/users/([^/]+)/calendarView#', $request->url(), $matches);
                $email = urldecode($matches[1] ?? 'desconocido');

                return Http::response(['value' => [[
                    'id' => 'evt-1',
                    'subject' => "Evento de {$email}",
                    'start' => ['dateTime' => now()->toIso8601String()],
                    'end' => ['dateTime' => now()->addHour()->toIso8601String()],
                    'isAllDay' => false,
                    'location' => ['displayName' => 'Oficina'],
                    'organizer' => ['emailAddress' => ['name' => 'Organizador']],
                ]]], 200);
            },
        ]);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/calendar')->assertStatus(401);
    }

    public function test_usuario_sin_team_view_siempre_ve_su_propio_calendario_aunque_pida_otro_owner(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/calendar?owner_id={$managerId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de vendedor@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de management@atlantic.test'));
    }

    public function test_gerencia_con_scope_team_y_sin_owner_id_ve_el_calendario_de_todo_el_equipo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar?scope=team')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        // vendedor@atlantic.test es el único ROLE_DESIGNER seedeado — el "equipo" real de
        // Ventas & Diseño en este fixture. Si esto vuelve a devolver solo el propio de Gerencia,
        // es la regresión que encontró Senior Review el 2026-07-21.
        $this->assertTrue($titles->contains('Evento de vendedor@atlantic.test'));
    }

    /**
     * Visual Review CRÍTICO (SCRUM-368, 2026-07-28): la vista "Calendario del equipo" traía
     * `owner_email` de cada evento pero ningún consumidor lo resolvía a un nombre — RN2/Escenario 2
     * exige que cada evento combinado indique a quién pertenece.
     */
    public function test_scope_team_resuelve_owner_name_a_partir_del_owner_email(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar?scope=team')
            ->assertStatus(200);

        $designerEvent = collect($response->json('data'))
            ->firstWhere('owner_email', 'vendedor@atlantic.test');

        $this->assertNotNull($designerEvent);
        $this->assertNotNull($designerEvent['owner_name']);
    }

    public function test_gerencia_sin_scope_team_ve_solo_su_propio_calendario_aunque_pueda_ver_equipo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de vendedor@atlantic.test'));
    }

    /**
     * Hallazgo CRÍTICO de Pre-QA (2026-07-21, subagente real): con team-view en un módulo,
     * `owner_id` se honraba para CUALQUIER usuario del tenant, no solo los del equipo real de ese
     * módulo — confirmado en vivo trayendo el calendario real de un superadmin (no diseñador) vía
     * la cuenta demo de Gerencia. `nivel2@atlantic.test` no es ROLE_DESIGNER (no está en
     * el equipo de ventas_diseno) — mismo tipo de víctima que se probó en vivo.
     */
    public function test_owner_id_de_alguien_fuera_del_equipo_no_filtra_su_calendario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $outsiderId = $this->userIdByEmail('nivel2@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/calendar?owner_id={$outsiderId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertFalse($titles->contains('Evento de nivel2@atlantic.test'));
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
    }

    public function test_gerencia_puede_pedir_el_calendario_de_un_vendedor_puntual(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/calendar?owner_id={$designerId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de vendedor@atlantic.test'));
    }

    public function test_compras_usa_el_mismo_endpoint_gateado_por_su_propio_modulo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', 'vendedor@atlantic.test')
            ->update(['extra_permissions' => json_encode(['compras.read'])]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/calendar')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de vendedor@atlantic.test'));
    }

    public function test_servicios_usa_el_mismo_endpoint_gateado_por_su_propio_modulo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', 'vendedor@atlantic.test')
            ->update(['extra_permissions' => json_encode(['servicios.read'])]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/calendar')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de vendedor@atlantic.test'));
    }

    /**
     * Hallazgo CRÍTICO de Pre-QA (2026-08-14, SCRUM-272): `liderservicios@test.com`
     * (lider_servicios, cuenta real) tiene `servicios.view_team=true` en su JWT — necesario para
     * "Rutas del día" (REQ-208, team-wide por diseño) — pero PERMISOS de SCRUM-272 exige que
     * "Mi calendario" sea estrictamente personal, nunca de equipo, para este módulo. Antes del
     * fix (`MODULES_WITH_TEAM_CALENDAR`), forjar `scope=team` directo contra el API (sin pasar
     * por `ServiciosMyCalendarPanel.tsx`, que nunca lo manda) devolvía el calendario real de
     * otros usuarios del "equipo" de `servicios` — confirmado en vivo contra el stack local con
     * Lider Servicios viendo eventos de Tecnico Garantias Test/Gerencia Test.
     */
    public function test_servicios_scope_team_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('liderservicios@test.com', 'liderservicios@test.com', 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/calendar?scope=team')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de liderservicios@test.com'));
        $this->assertFalse($titles->contains('Evento de tecnicogarantias@test.com'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
        $this->assertFalse($titles->contains('Evento de gerencia@test.com'));
    }

    public function test_servicios_owner_id_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $carlosId = $this->userIdByEmail('tecnico@test.com');
        $token = $this->loginAs('liderservicios@test.com', 'liderservicios@test.com', 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/calendar?owner_id={$carlosId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de liderservicios@test.com'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
    }

    // SCRUM-164 (REQ-102) — "Mi calendario" de Gerencia, mismo endpoint cableado con
    // module=gerencia (ver routes/gerencia.php). Gerencia queda deliberadamente FUERA de
    // MODULES_WITH_TEAM_CALENDAR, mismo criterio que Servicios (RN1: "nadie ve el calendario de
    // otro usuario desde acá").
    public function test_gerencia_usa_el_mismo_endpoint_gateado_por_su_propio_modulo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/calendar')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
    }

    public function test_gerencia_scope_team_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/calendar?scope=team')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de liderservicios@test.com'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
    }

    public function test_gerencia_owner_id_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $carlosId = $this->userIdByEmail('tecnico@test.com');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/gerencia/calendar?owner_id={$carlosId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
    }

    public function test_cuenta_sin_buzon_real_404_no_se_marca_como_fuente_no_disponible(): void
    {
        // Confirmado contra el tenant real (2026-07-21): un email sin cuenta de Microsoft 365
        // (como las demo *@atlantic.test) responde 404 ErrorInvalidUser — no es una falla
        // del servicio, así que no debe prender source_unavailable ni el circuit breaker.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorInvalidUser']], 404),
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar')
            ->assertStatus(200);

        $response->assertJsonPath('data', []);
        $response->assertJsonPath('source_unavailable', false);
    }

    /** Fake que respeta startDateTime/endDateTime de verdad — solo devuelve el evento si cae dentro del rango pedido, como Graph real. */
    private function fakeGraphWithEventAt(string $isoDateTime): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*' => function ($request) use ($isoDateTime) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $start = isset($query['startDateTime']) ? strtotime((string) $query['startDateTime']) : null;
                $end = isset($query['endDateTime']) ? strtotime((string) $query['endDateTime']) : null;
                $eventTime = strtotime($isoDateTime);

                $inRange = $start !== null && $end !== null && $eventTime >= $start && $eventTime < $end;

                return Http::response(['value' => $inRange ? [[
                    'id' => 'evt-1',
                    'subject' => 'Evento de hoy',
                    'start' => ['dateTime' => $isoDateTime],
                    'end' => ['dateTime' => $isoDateTime],
                    'isAllDay' => false,
                    'location' => null,
                    'organizer' => null,
                ]] : []], 200);
            },
        ]);
    }

    /**
     * Hallazgo CRÍTICO de Pre-QA (2026-07-21, tercera pasada): el pill "Día" del frontend manda
     * `from`/`to` con la MISMA fecha ("2026-07-21"), sin hora. `Carbon::parse()` deja ambas en
     * medianoche — sin normalizar `to` a fin de día, el intervalo pedido a Graph tenía ancho CERO
     * y nunca podía traer ningún evento real, sin importar cuántos hubiera ese día. Confirmado en
     * vivo contra dev.atlanticerp.ai antes de este fix: 0 eventos con from=to=hoy, 4 eventos reales con
     * to=mañana.
     */
    public function test_pill_dia_con_from_igual_a_to_no_deja_el_rango_en_ancho_cero(): void
    {
        $eventAt = now()->setTime(14, 30, 0)->toIso8601String();
        $this->fakeGraphWithEventAt($eventAt);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $today = now()->toDateString();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/calendar?from={$today}&to={$today}")
            ->assertStatus(200);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_falla_de_graph_degrada_a_lista_vacia_en_vez_de_romper_la_pantalla(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*' => Http::response(['error' => 'boom'], 500),
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar')
            ->assertStatus(200);

        $response->assertJsonPath('data', []);
        $response->assertJsonPath('source_unavailable', true);
    }

    /**
     * SCRUM-66/177 — hallazgo de Luis en validación (2026-07-21): "vista de equipo" (17 cuentas
     * reales) tenía un retraso notorio porque las llamadas a Graph se hacían una por una en serie.
     * Se paralelizaron con `Http::pool()` — este test confirma que la mezcla de resultados por
     * cuenta (éxito real / cuenta sin buzón real / falla real) se sigue resolviendo bien en el
     * modelo paralelo, no solo que "es más rápido" (imposible de medir de forma determinística en
     * un test con Http::fake).
     */
    // Batch final de Admin&Cont — "Inicio" (SCRUM-509, REQ-432) — "Mi calendario", mismo
    // endpoint cableado con module=admin_contab (ver routes/admin-contab.php). Deliberadamente
    // FUERA de MODULES_WITH_TEAM_CALENDAR, mismo criterio que Servicios/Gerencia (RN1 REQ-432:
    // "nadie ve el calendario de otro usuario desde acá").
    public function test_admin_contab_usa_el_mismo_endpoint_gateado_por_su_propio_modulo(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/calendar')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
    }

    public function test_admin_contab_scope_team_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/calendar?scope=team')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de liderservicios@test.com'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
    }

    public function test_admin_contab_owner_id_forjado_nunca_devuelve_calendario_de_otro_usuario(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $carlosId = $this->userIdByEmail('tecnico@test.com');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/calendar?owner_id={$carlosId}")
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento de management@atlantic.test'));
        $this->assertFalse($titles->contains('Evento de tecnico@test.com'));
    }

    public function test_admin_contab_sin_rol_del_gate_retorna_403(): void
    {
        $this->fakeGraphEchoingRequestedEmail();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/calendar')
            ->assertStatus(403);
    }

    public function test_pool_paralelo_mezcla_exito_404_y_falla_real_sin_perder_los_eventos_buenos(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*' => function ($request) {
                preg_match('#/users/([^/]+)/calendarView#', $request->url(), $matches);
                $email = urldecode($matches[1] ?? '');

                return match ($email) {
                    'vendedor@atlantic.test' => Http::response(['value' => [[
                        'id' => 'evt-1', 'subject' => 'Evento real', 'isAllDay' => false,
                        'start' => ['dateTime' => now()->toIso8601String()],
                        'end' => ['dateTime' => now()->addHour()->toIso8601String()],
                    ]]], 200),
                    'nivel8@atlantic.test' => Http::response(['error' => ['code' => 'ErrorInvalidUser']], 404),
                    default => Http::response(['error' => 'boom'], 500),
                };
            },
        ]);
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/calendar?scope=team')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Evento real'), 'el evento de la cuenta que sí respondió bien no debería perderse');
        $response->assertJsonPath('source_unavailable', true);
    }
}
