<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 28 (grupo 6) — aprobadores propios por proceso. Sin configurar nada rige el aprobador primario (cero cambio);
 * con aprobadores propios, solo ellos deciden ese proceso y los demás procesos no se tocan.
 */
class ProcessApproversTest extends FeatureTestCase
{
    private const TITULAR = 'gerencia3@test.com';

    private const OTRA = 'gerencia2@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function idDe(string $email): int
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    private function configurar(string $proceso, array $ids): void
    {
        $super = $this->loginAs('superadmin@test.com', 'superadmin@test.com', 'superadmin');
        $this->withHeaders($this->authHeaders($super))->putJson("/api/admin/process-approvers/{$proceso}", ['user_ids' => $ids])->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function codigoDe(string $email, string $url): int
    {
        $token = $this->loginAs($email, $email, 'management');
        $status = $this->withHeaders($this->authHeaders($token))->putJson($url)->getStatusCode();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $status;
    }

    public function test_sin_configurar_rige_el_aprobador_primario_en_todos_los_procesos(): void
    {
        foreach (['/api/admin-contab/petty-cash/expenses/999999/reject', '/api/admin-contab/notas-credito/999999/decision'] as $url) {
            $this->assertNotSame(403, $this->codigoDe(self::TITULAR, $url), $url);
            $this->assertSame(403, $this->codigoDe(self::OTRA, $url), $url);
        }
    }

    public function test_un_proceso_con_aprobadores_propios_solo_los_admite_a_ellos_y_no_toca_a_los_demas(): void
    {
        $this->configurar('caja_chica', [$this->idDe(self::OTRA)]);
        $caja = '/api/admin-contab/petty-cash/expenses/999999/reject';
        $nota = '/api/admin-contab/notas-credito/999999/decision';

        $this->assertNotSame(403, $this->codigoDe(self::OTRA, $caja));
        $this->assertSame(403, $this->codigoDe(self::TITULAR, $caja), 'el titular global no decide un proceso con aprobadores propios');
        // Otro proceso sigue con el primario.
        $this->assertNotSame(403, $this->codigoDe(self::TITULAR, $nota));
        $this->assertSame(403, $this->codigoDe(self::OTRA, $nota));

        // Vaciar la lista devuelve el proceso al aprobador primario.
        $this->configurar('caja_chica', []);
        $this->assertNotSame(403, $this->codigoDe(self::TITULAR, $caja));
        $this->assertSame(403, $this->codigoDe(self::OTRA, $caja));
    }

    public function test_solo_superadmin_administra_y_un_proceso_desconocido_da_404(): void
    {
        $gerencia = $this->loginAs(self::TITULAR, self::TITULAR, 'management');
        $this->withHeaders($this->authHeaders($gerencia))->putJson('/api/admin/process-approvers/caja_chica', ['user_ids' => []])->assertStatus(403);

        $super = $this->loginAs('superadmin@test.com', 'superadmin@test.com', 'superadmin');
        $this->withHeaders($this->authHeaders($super))->putJson('/api/admin/process-approvers/inventado', ['user_ids' => []])->assertStatus(404);
        $this->withHeaders($this->authHeaders($super))->getJson('/api/admin/process-approvers')->assertStatus(200)
            ->assertJsonPath('processes.caja_chica.sigue_al_primario', true);
    }
}
