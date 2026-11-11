<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 32 — Servicios: cierre de abiertos (vínculo técnico↔usuario, etc.). */
class Fase32ServiciosTest extends FeatureTestCase
{
    private const LIDER = 'liderservicios@test.com';

    private const VENDEDOR = 'vendedor@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMake();
        (new \Database\Seeders\ServiciosDemoSeeder)->seedParaTests();
    }

    private function reMake(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function as(string $email, string $role): array
    {
        return $this->authHeaders($this->loginAs($email, $email, $role));
    }

    public function test_usuarios_vinculables_son_tecnicos_activos_sin_otra_ficha(): void
    {
        $h = $this->as(self::LIDER, 'lider_servicios');
        $this->reMake();
        InternalTechnician::query()->update(['user_id' => null]); // el demo ya liga algunas fichas
        $tecnico = User::query()->whereHas('role', fn ($q) => $q->where('key', 'tecnico'))->firstOrFail();

        $ids = collect($this->withHeaders($h)->getJson('/api/servicios/internal-technicians/linkable-users')->assertStatus(200)->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($tecnico->id));

        // Un vendedor no es técnico: nunca aparece.
        $this->reMake();
        $vendedorId = User::query()->where('email', self::VENDEDOR)->value('id');
        $this->assertFalse($ids->contains($vendedorId));

        // Ligado a una ficha → deja de ofrecerse, salvo para esa misma ficha.
        $this->reMake();
        $ficha = InternalTechnician::create(['nombre' => 'Ficha F32', 'especialidad' => 'general', 'color' => '#112233', 'user_id' => $tecnico->id]);
        $sin = collect($this->withHeaders($h)->getJson('/api/servicios/internal-technicians/linkable-users')->json('data'))->pluck('id');
        $this->assertFalse($sin->contains($tecnico->id));
        $propia = collect($this->withHeaders($h)->getJson('/api/servicios/internal-technicians/linkable-users?ficha_id='.$ficha->id)->json('data'))->pluck('id');
        $this->assertTrue($propia->contains($tecnico->id));
    }

    public function test_usuarios_vinculables_exigen_permiso_de_gestion(): void
    {
        $this->withHeaders($this->as(self::VENDEDOR, 'vendedor'))
            ->getJson('/api/servicios/internal-technicians/linkable-users')->assertStatus(403);
    }
}
