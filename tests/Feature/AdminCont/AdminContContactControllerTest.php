<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-641 (REQ-564) — Contactos generales por área. */
class AdminContContactControllerTest extends FeatureTestCase
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsApprover(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_store_crea_un_contacto_activo(): void
    {
        $token = $this->loginAsApprover();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/contacts', [
                'area' => 'Soporte',
                'email' => 'soporte@atlantic.com.pa',
                'telefono' => '+507 6000-0000',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('area', 'Soporte');
        $response->assertJsonPath('activo', true);
    }

    public function test_store_exige_los_3_campos(): void
    {
        $token = $this->loginAsApprover();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/contacts', ['area' => 'Soporte'])
            ->assertStatus(422);
    }

    public function test_store_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        $token = $this->loginAs('nivel8@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/contacts', [
                'area' => 'Soporte', 'email' => 'a@b.com', 'telefono' => '123',
            ])
            ->assertStatus(403);
    }

    public function test_update_desactiva_un_contacto_sin_eliminarlo(): void
    {
        $token = $this->loginAsApprover();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/contacts', [
                'area' => 'Ventas', 'email' => 'ventas@atlantic.com.pa', 'telefono' => '6111-1111',
            ])->json();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/contacts/{$created['id']}", ['activo' => false])
            ->assertStatus(200);

        $response->assertJsonPath('activo', false);

        $index = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/contacts');
        $this->assertContains('Ventas', collect($index->json())->pluck('area')->all());
    }

    public function test_update_de_id_inexistente_devuelve_404(): void
    {
        $token = $this->loginAsApprover();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/contacts/999999', ['activo' => false])
            ->assertStatus(404);
    }
}
