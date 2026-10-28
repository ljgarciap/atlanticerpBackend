<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Project;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 9b — el proyecto requiere un estudio o cálculo técnico: campo genérico `requires_technical_study`.
 */
class EstudioTecnicoFase9bTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_la_columna_existe(): void
    {
        $table = (new Project)->getTable();

        $this->assertTrue(Schema::hasColumn($table, 'requires_technical_study'));
    }

    public function test_el_campo_viaja_por_la_api_y_filtra_el_listado(): void
    {
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');
        $headers = $this->authHeaders($token);

        foreach ([['Con estudio', true], ['Sin estudio', false]] as [$nombre, $requiere]) {
            $this->withHeaders($headers)->postJson('/api/crm/projects', [
                'nombre' => $nombre, 'tipo' => 'diseno', 'requires_technical_study' => $requiere, 'assignee_ids' => [],
            ])->assertStatus(201)->assertJsonPath('requires_technical_study', $requiere);
        }

        $con = collect($this->withHeaders($headers)->getJson('/api/crm/projects?requires_technical_study=true')->assertStatus(200)->json('data'))->pluck('nombre');
        $this->assertContains('Con estudio', $con);
        $this->assertNotContains('Sin estudio', $con);
    }
}
