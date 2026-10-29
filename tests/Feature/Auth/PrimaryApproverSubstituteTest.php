<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 12b — aprobador suplente: el titular sigue decidiendo, un suplente designado por superadmin decide lo mismo, y cualquier otra
 * cuenta (incluida otra de Gerencia) sigue recibiendo 403.
 */
class PrimaryApproverSubstituteTest extends FeatureTestCase
{
    private const TITULAR = 'gerencia3@test.com';

    private const SUPLENTE = 'gerencia2@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        PrimaryApprovers::forget();
    }

    private function idDe(string $email): int
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    private function designar(array $ids): void
    {
        $super = $this->loginAs('superadmin@test.com', 'superadmin@test.com', 'superadmin');
        $this->withHeaders($this->authHeaders($super))
            ->putJson('/api/admin/primary-approver-substitutes', ['user_ids' => $ids])
            ->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function pantallaDelAprobador(string $email, string $rol = 'management'): int
    {
        $token = $this->loginAs($email, $email, $rol);
        $status = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/fiscal-settings')->getStatusCode();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $status;
    }

    public function test_sin_suplentes_solo_el_titular_entra_y_otra_gerencia_recibe_403(): void
    {
        $this->assertSame(200, $this->pantallaDelAprobador(self::TITULAR));
        $this->assertSame(403, $this->pantallaDelAprobador(self::SUPLENTE));
    }

    public function test_un_suplente_designado_entra_y_al_quitarlo_vuelve_el_403(): void
    {
        $this->designar([$this->idDe(self::SUPLENTE)]);
        $this->assertSame(200, $this->pantallaDelAprobador(self::SUPLENTE), 'el suplente decide lo mismo que el titular');
        $this->assertSame(200, $this->pantallaDelAprobador(self::TITULAR), 'el titular no pierde nada');
        $this->assertSame(403, $this->pantallaDelAprobador('lidercompras@test.com', 'lider_compras'), 'cualquier otra cuenta sigue sin acceso');

        $this->designar([]);
        $this->assertSame(403, $this->pantallaDelAprobador(self::SUPLENTE));
    }

    public function test_el_suplente_viaja_como_aprobador_en_el_token(): void
    {
        $this->designar([$this->idDe(self::SUPLENTE)]);
        $token = $this->loginAs(self::SUPLENTE, self::SUPLENTE, 'management');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertTrue($claims['flags']['primary_approver'] ?? false);
    }

    public function test_solo_superadmin_administra_los_suplentes_y_el_titular_no_puede_serlo(): void
    {
        $gerencia = $this->loginAs(self::TITULAR, self::TITULAR, 'management');
        $this->withHeaders($this->authHeaders($gerencia))
            ->putJson('/api/admin/primary-approver-substitutes', ['user_ids' => [$this->idDe(self::SUPLENTE)]])
            ->assertStatus(403);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $super = $this->loginAs('superadmin@test.com', 'superadmin@test.com', 'superadmin');
        $this->withHeaders($this->authHeaders($super))
            ->putJson('/api/admin/primary-approver-substitutes', ['user_ids' => [$this->idDe(self::TITULAR)]])
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($super))
            ->putJson('/api/admin/primary-approver-substitutes', ['user_ids' => [999999]])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($super))
            ->getJson('/api/admin/primary-approver-substitutes')
            ->assertStatus(200)->assertJsonPath('suplentes', []);
    }
}
