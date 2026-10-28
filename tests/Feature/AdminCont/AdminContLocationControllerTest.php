<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-640 (REQ-563) — Ubicaciones: combina Warehouse (Bodega, solo lectura) + AdminContLocation. */
class AdminContLocationControllerTest extends FeatureTestCase
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

    public function test_index_incluye_las_ubicaciones_oficiales_de_atlantic_como_solo_lectura(): void
    {
        $token = $this->loginAsApprover();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/locations')
            ->assertStatus(200);

        $names = collect($response->json())->pluck('nombre')->all();
        foreach (['Bodega Central', 'Sala de exhibición 1', 'Sala de exhibición 2', 'Exhibición en cliente', 'Merma', 'Reclamos y Devoluciones'] as $expected) {
            $this->assertContains($expected, $names, "Falta la ubicación oficial: {$expected}");
        }

        // SCRUM-990: Zona Libre es de solo consulta (catálogo de Lafayette) y ya no es una ubicación de Atlantic.
        $this->assertNotContains('Bodega Zona Libre', $names);

        $aparte = collect($response->json())->firstWhere('nombre', 'Sala de exhibición 1');
        $this->assertSame('exhibicion', $aparte['tipo']);
        $this->assertFalse($aparte['editable']);
        $this->assertSame('bodega', $aparte['source']);

        $central = collect($response->json())->firstWhere('nombre', 'Bodega Central');
        $this->assertSame('bodega', $central['tipo']);
    }

    public function test_index_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        $token = $this->loginAs('nivel8@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/locations')
            ->assertStatus(403);
    }

    public function test_store_crea_una_ubicacion_administrativa_activa_por_defecto(): void
    {
        $token = $this->loginAsApprover();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/locations', [
                'nombre' => 'Oficina Administrativa',
                'tipo' => 'oficina',
                'direccion' => 'Calle 50, Panamá',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('tipo', 'oficina');
        $response->assertJsonPath('activa', true);

        $index = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/locations');
        $created = collect($index->json())->firstWhere('nombre', 'Oficina Administrativa');
        $this->assertTrue($created['editable']);
        $this->assertSame('admin_contab', $created['source']);
    }

    public function test_store_rechaza_tipo_invalido(): void
    {
        $token = $this->loginAsApprover();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/locations', ['nombre' => 'X', 'tipo' => 'bodega'])
            ->assertStatus(422);
    }

    public function test_update_desactiva_una_ubicacion_administrativa_sin_eliminarla(): void
    {
        $token = $this->loginAsApprover();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/locations', ['nombre' => 'Bodega Temporal', 'tipo' => 'otra'])
            ->json();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/locations/{$created['id']}", ['activa' => false])
            ->assertStatus(200);

        $response->assertJsonPath('activa', false);

        $index = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/locations');
        $this->assertContains('Bodega Temporal', collect($index->json())->pluck('nombre')->all());
    }

    public function test_update_devuelve_404_para_un_id_que_no_pertenece_a_admin_contab_locations(): void
    {
        $token = $this->loginAsApprover();

        // id 1 existe como Warehouse ("Bodega Central"), pero nunca como AdminContLocation propia.
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/locations/1', ['activa' => false])
            ->assertStatus(404);
    }
}
