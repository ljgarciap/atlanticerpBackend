<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Services\SalesTotalsService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-944/945 — auditoría de valores de negocio hardcodeados. `SalesTotalsService::percentFor()`
 * tenía la tabla de comisión escalonada de vendedores hardcodeada; migrada a
 * `sales_commission_tiers`, con el PUT atómico como único camino de escritura.
 */
class SalesCommissionTierControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsSuperadmin(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    private function loginAsDesigner(): string
    {
        return $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
    }

    /**
     * Comportamiento preservado EXACTAMENTE del `match(true)` original — antes de la migración,
     * `SalesTotalsService::percentFor()` era: $total < 20000/35000/60000/80000/120000 →
     * 1.5/2.0/3.0/4.0/4.5%, default 5.0%. Cada corte se prueba tanto justo abajo como justo en el
     * límite, para confirmar que el modelo de rango inclusivo replica el "<" estricto original.
     */
    public function test_percent_for_preserva_los_6_tramos_originales(): void
    {
        $service = app(SalesTotalsService::class);

        $this->assertSame(1.5, $service->percentFor(0));
        $this->assertSame(1.5, $service->percentFor(19999.99));
        $this->assertSame(2.0, $service->percentFor(20000));
        $this->assertSame(2.0, $service->percentFor(34999.99));
        $this->assertSame(3.0, $service->percentFor(35000));
        $this->assertSame(3.0, $service->percentFor(59999.99));
        $this->assertSame(4.0, $service->percentFor(60000));
        $this->assertSame(4.0, $service->percentFor(79999.99));
        $this->assertSame(4.5, $service->percentFor(80000));
        $this->assertSame(4.5, $service->percentFor(119999.99));
        $this->assertSame(5.0, $service->percentFor(120000));
        $this->assertSame(5.0, $service->percentFor(500000));
    }

    public function test_index_devuelve_los_6_tramos_seedeados(): void
    {
        $token = $this->loginAsSuperadmin();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/sales-commission-tiers')
            ->assertStatus(200);

        $response->assertJsonCount(6);
    }

    public function test_rechaza_a_un_rol_sin_superadmin_all(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/sales-commission-tiers')
            ->assertStatus(403);

        $this->putTiers($token, $this->seededTiers())->assertStatus(403);
    }

    /** S7 — sin ningún token (sin arrastrar headers de otro login) el contrato es 401 exacto. */
    public function test_sin_token_responde_401_en_get_y_put(): void
    {
        $this->getJson('/api/ventas-diseno/sales-commission-tiers')->assertStatus(401);
        $this->putJson('/api/ventas-diseno/sales-commission-tiers', ['tiers' => $this->seededTiers()])->assertStatus(401);
    }

    /** S1 — el PUT atómico es el único camino de escritura: POST/PUT/DELETE individuales ya no existen. */
    public function test_endpoints_individuales_ya_no_existen(): void
    {
        $token = $this->loginAsSuperadmin();
        $id = DB::table('atlantic_ventas_diseno.sales_commission_tiers')->min('id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sales-commission-tiers', [
                'monto_minimo' => 500001, 'monto_maximo' => null, 'porcentaje' => 6, 'orden' => 7,
            ])
            ->assertStatus(405);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/sales-commission-tiers/{$id}", ['porcentaje' => 9])
            ->assertStatus(404);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/sales-commission-tiers/{$id}")
            ->assertStatus(404);

        $this->assertSame(6, $this->tierCount());
    }

    // ── Pre-QA SCRUM-945, hallazgo A ─────────────────────────────────────────────────────────

    /** Cuenta por SQL directo: `loginAs()` resetea el tenant actual y el modelo dejaría de resolver la tabla. */
    private function tierCount(): int
    {
        return DB::table('atlantic_ventas_diseno.sales_commission_tiers')->count();
    }

    /** @param list<array<string, mixed>> $tiers */
    private function putTiers(string $token, array $tiers): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/sales-commission-tiers', ['tiers' => $tiers]);
    }

    /** @return list<array<string, mixed>> */
    private function seededTiers(): array
    {
        return [
            ['monto_minimo' => 0, 'monto_maximo' => 19999.99, 'porcentaje' => 1.5],
            ['monto_minimo' => 20000, 'monto_maximo' => 34999.99, 'porcentaje' => 2],
            ['monto_minimo' => 35000, 'monto_maximo' => 59999.99, 'porcentaje' => 3],
            ['monto_minimo' => 60000, 'monto_maximo' => 79999.99, 'porcentaje' => 4],
            ['monto_minimo' => 80000, 'monto_maximo' => 119999.99, 'porcentaje' => 4.5],
            ['monto_minimo' => 120000, 'monto_maximo' => null, 'porcentaje' => 5],
        ];
    }

    public function test_replace_atomico_acepta_el_set_sembrado_y_conserva_ids(): void
    {
        $token = $this->loginAsSuperadmin();
        $before = $this->withHeaders($this->authHeaders($token))->getJson('/api/ventas-diseno/sales-commission-tiers')->json();
        $withIds = collect($this->seededTiers())
            ->map(fn (array $t): array => $t + ['id' => collect($before)->firstWhere('monto_minimo', $t['monto_minimo'])['id']])
            ->all();

        $response = $this->putTiers($token, $withIds)->assertStatus(200);

        $this->assertSame(collect($before)->pluck('id')->all(), collect($response->json())->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], collect($response->json())->pluck('orden')->all());
    }

    public function test_replace_atomico_reestructura_y_deja_un_solo_audit_log(): void
    {
        $token = $this->loginAsSuperadmin();
        $tiers = [
            ['monto_minimo' => 0, 'monto_maximo' => 49999.99, 'porcentaje' => 2],
            ['monto_minimo' => 50000, 'monto_maximo' => null, 'porcentaje' => 4],
        ];

        $this->putTiers($token, array_reverse($tiers))->assertStatus(200)->assertJsonCount(2);
        $this->assertSame(2, $this->tierCount());
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(2.0, app(SalesTotalsService::class)->percentFor(49999.99));
        $this->assertSame(4.0, app(SalesTotalsService::class)->percentFor(50000));

        $logs = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'ventas_diseno.sales_commission_tiers.replaced')->count();
        $this->assertSame(1, $logs);
        // withoutEvents: ninguna fila suelta de created/updated/deleted por tramo.
        $this->assertSame(0, DB::table('atlantic_auth.audit_logs')
            ->where('action', 'like', 'ventas_diseno.sales_commission_tier.%')->count());
    }

    public function test_replace_rechaza_hueco_al_subir_el_minimo_del_ultimo_tramo_a_200000(): void
    {
        $token = $this->loginAsSuperadmin();
        $tiers = $this->seededTiers();
        $tiers[5]['monto_minimo'] = 200000;

        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers']);
        $this->assertSame(6, $this->tierCount());
        $this->assertEquals(120000, DB::table('atlantic_ventas_diseno.sales_commission_tiers')->max('monto_minimo'));
    }

    public function test_replace_rechaza_solape_25000_30000(): void
    {
        $token = $this->loginAsSuperadmin();
        $tiers = $this->seededTiers();
        $tiers[] = ['monto_minimo' => 25000, 'monto_maximo' => 30000, 'porcentaje' => 9];

        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers']);
        $this->assertSame(6, $this->tierCount());
    }

    /** @return array<string, array{0: list<array<string, mixed>>}> */
    public static function invalidFullSets(): array
    {
        return [
            'primero no empieza en 0' => [[
                ['monto_minimo' => 100, 'monto_maximo' => null, 'porcentaje' => 1],
            ]],
            'ultimo tramo cerrado' => [[
                ['monto_minimo' => 0, 'monto_maximo' => 1000, 'porcentaje' => 1],
            ]],
            'tramo abierto en el medio' => [[
                ['monto_minimo' => 0, 'monto_maximo' => null, 'porcentaje' => 1],
                ['monto_minimo' => 1000, 'monto_maximo' => null, 'porcentaje' => 2],
            ]],
            'minimo mayor que maximo' => [[
                ['monto_minimo' => 0, 'monto_maximo' => 500, 'porcentaje' => 1],
                ['monto_minimo' => 500.01, 'monto_maximo' => 400, 'porcentaje' => 2],
                ['monto_minimo' => 900, 'monto_maximo' => null, 'porcentaje' => 3],
            ]],
            'hueco entre tramos' => [[
                ['monto_minimo' => 0, 'monto_maximo' => 999.99, 'porcentaje' => 1],
                ['monto_minimo' => 1005, 'monto_maximo' => null, 'porcentaje' => 2],
            ]],
            'porcentaje fuera de 0-100' => [[
                ['monto_minimo' => 0, 'monto_maximo' => null, 'porcentaje' => 101],
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $tiers */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFullSets')]
    public function test_replace_rechaza_sets_invalidos_sin_tocar_la_tabla(array $tiers): void
    {
        $token = $this->loginAsSuperadmin();

        $this->putTiers($token, $tiers)->assertStatus(422);
        $this->assertSame(6, $this->tierCount());
    }

    public function test_replace_acepta_orden_pero_lo_ignora_y_reporta_errores_por_fila(): void
    {
        $token = $this->loginAsSuperadmin();

        $tiers = $this->seededTiers();
        foreach ($tiers as $i => &$tier) {
            $tier['orden'] = 99 - $i; // orden enviado al revés: debe ignorarse
        }
        unset($tier);
        $response = $this->putTiers($token, $tiers)->assertStatus(200);
        $this->assertSame([1, 2, 3, 4, 5, 6], collect($response->json())->pluck('orden')->all());
        $this->assertEquals([0, 20000, 35000, 60000, 80000, 120000], collect($response->json())->pluck('monto_minimo')->all());

        // Errores de regla por campo llegan como tiers.N.<campo> (N = índice 0-based del array enviado).
        $bad = $this->seededTiers();
        $bad[2]['porcentaje'] = 150;
        $bad[3]['orden'] = 'x';
        $this->putTiers($token, $bad)->assertStatus(422)
            ->assertJsonValidationErrors(['tiers.2.porcentaje', 'tiers.3.orden']);
    }

    public function test_replace_rechaza_lista_vacia_e_id_inexistente(): void
    {
        $token = $this->loginAsSuperadmin();

        $this->putTiers($token, [])->assertStatus(422);

        $tiers = $this->seededTiers();
        $tiers[0]['id'] = 999999;
        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers']);
    }

    /** S2 — un mismo id repetido en la lista se rechaza con 422 en la clave por fila `tiers.N.id`. */
    public function test_replace_rechaza_ids_repetidos_con_error_por_fila(): void
    {
        $token = $this->loginAsSuperadmin();
        $id = DB::table('atlantic_ventas_diseno.sales_commission_tiers')->min('id');

        $tiers = $this->seededTiers();
        $tiers[0]['id'] = $id;
        $tiers[1]['id'] = $id;

        $this->putTiers($token, $tiers)->assertStatus(422)
            ->assertJsonValidationErrors(['tiers.0.id', 'tiers.1.id']);
        $this->assertSame(6, $this->tierCount());
    }

    /** S5 — máximo 2 decimales en los montos (20000.005 se redondearía a centavos distintos en silencio). */
    public function test_replace_rechaza_montos_con_mas_de_2_decimales(): void
    {
        $token = $this->loginAsSuperadmin();

        $tiers = $this->seededTiers();
        $tiers[1]['monto_minimo'] = 20000.005;
        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers.1.monto_minimo']);

        $tiers = $this->seededTiers();
        $tiers[0]['monto_maximo'] = 19999.995;
        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers.0.monto_maximo']);

        $this->assertSame(6, $this->tierCount());
    }

    /** S6 — el audit describe exactamente lo persistido (antes = sembrado, después = lo guardado). */
    public function test_replace_audita_el_estado_antes_y_despues_persistido(): void
    {
        $token = $this->loginAsSuperadmin();
        $this->putTiers($token, [
            ['monto_minimo' => 0, 'monto_maximo' => null, 'porcentaje' => 2.5],
        ])->assertStatus(200);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'ventas_diseno.sales_commission_tiers.replaced')->first();
        $this->assertNotNull($log);
        $this->assertCount(6, json_decode((string) $log->old_values, true)['tiers']);
        $new = json_decode((string) $log->new_values, true)['tiers'];
        $this->assertCount(1, $new);
        $this->assertEquals(2.5, $new[0]['porcentaje']);
    }

    /** Observación BAJA 2 — 4.555 se redondeaba en silencio a 4.56: ahora 422 en `tiers.N.porcentaje`. */
    public function test_replace_rechaza_porcentaje_con_mas_de_2_decimales(): void
    {
        $token = $this->loginAsSuperadmin();

        $tiers = $this->seededTiers();
        $tiers[4]['porcentaje'] = 4.555;
        $this->putTiers($token, $tiers)->assertStatus(422)->assertJsonValidationErrors(['tiers.4.porcentaje']);

        $tiers[4]['porcentaje'] = 4.55;
        $this->putTiers($token, $tiers)->assertStatus(200);
    }

    /** Observación BAJA 1 — nombre legible con fila 1-based en vez de la ruta cruda; las claves no cambian. */
    public function test_replace_muestra_nombres_legibles_y_conserva_las_claves_de_error(): void
    {
        $token = $this->loginAsSuperadmin();
        $tiers = $this->seededTiers();
        $tiers[2]['porcentaje'] = 4.555;

        $response = $this->putTiers($token, $tiers)->assertStatus(422)
            ->assertJsonValidationErrors(['tiers.2.porcentaje']);

        $message = (string) $response->json('errors')['tiers.2.porcentaje'][0];
        $this->assertStringContainsString('porcentaje (fila 3)', $message);
        $this->assertStringNotContainsString('tiers.2', $message);
        $this->assertStringNotContainsString('tiers.2', (string) $response->json('message'));
    }

    /** Observación BAJA 3 — el sufijo "(and N more error)" de Laravel sale en español. */
    public function test_replace_traduce_el_sufijo_de_mas_errores(): void
    {
        $token = $this->loginAsSuperadmin();
        $tiers = $this->seededTiers();
        $tiers[0]['porcentaje'] = 150;
        $tiers[1]['porcentaje'] = -1;

        $message = (string) $this->putTiers($token, $tiers)->assertStatus(422)->json('message');

        $this->assertStringContainsString('(y 1 error más)', $message);
        $this->assertStringNotContainsString('(and ', $message);

        $tiers[2]['porcentaje'] = 200;
        $message = (string) $this->putTiers($token, $tiers)->assertStatus(422)->json('message');
        $this->assertStringContainsString('(y 2 errores más)', $message);
    }
}
