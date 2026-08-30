<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-133 (sprint2) — buscador de Pedidos Aprobados para Compras, sin depender de
 * ventas_diseno.read (Yirena/lider_compras no tiene ese permiso).
 */
class ApprovedProjectControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeQuote(string $projectName, ?string $stage, bool $withFolio = true): Quote
    {
        $owner = User::where('email', 'designer@illuminations.test')->firstOrFail();
        $master = MasterClient::create(['name' => "Cliente {$projectName}"]);
        $sub = SubClient::create([
            'master_client_id' => $master->id, 'business_name' => "Cliente {$projectName} SA", 'tax_id' => uniqid(),
        ]);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName]);
        // SCRUM-723: confirmed_at (no folio) es lo que decide document_status ==
        // "approved" — este helper simula una cotización ya guardada/oficial.
        $quote = Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio'        => $withFolio ? 'COT-'.random_int(1000, 9999) : null,
            'confirmed_at' => $withFolio ? now() : null,
        ]);

        if ($stage !== null) {
            PipelineCard::create([
                'sales_project_id' => $salesProject->id, 'stage' => $stage,
                'sub_client_id' => $sub->id, 'owner_id' => $owner->id,
            ]);
        }

        return $quote;
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/approved-projects')->assertStatus(401);
    }

    public function test_solo_incluye_pedidos_aprobados(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeQuote('Proyecto Aprobado', 'approved');
        $this->makeQuote('Proyecto Enviado', 'quote');
        $this->makeQuote('Proyecto Sin Folio', 'approved', withFolio: false);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto Aprobado', $names);
        $this->assertNotContains('Proyecto Enviado', $names);
        $this->assertNotContains('Proyecto Sin Folio', $names);
    }

    public function test_usuario_de_compras_sin_ventas_diseno_read_puede_buscar(): void
    {
        // El propio caso que motivó este endpoint: rol lider_compras no tiene ventas_diseno.read.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeQuote('Proyecto Compras', 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);
    }

    public function test_respuesta_no_incluye_monto(): void
    {
        // Mismo criterio que "el costo nunca viaja al buscador" (CatalogProductController).
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeQuote('Proyecto Aprobado', 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('amount', $response->json('data.0'));
    }

    /**
     * SCRUM-734 (Senior Review retroactivo 2026-08-06) — un proyecto Aprobado
     * puede tener más de una cotización con documentStatus()==='approved' en su
     * historial (todas las confirmadas antes de llegar a Aprobado quedan con ese
     * mismo estado calculado); antes de este fix aparecían como filas duplicadas
     * del mismo proyecto acá.
     */
    public function test_proyecto_con_varias_cotizaciones_aprobadas_aparece_una_sola_vez(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $owner = User::where('email', 'designer@illuminations.test')->firstOrFail();
        $master = MasterClient::create(['name' => 'Cliente Duplicado']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente Duplicado SA', 'tax_id' => uniqid()]);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Con Versiones']);
        PipelineCard::create([
            'sales_project_id' => $salesProject->id, 'stage' => 'approved',
            'sub_client_id' => $sub->id, 'owner_id' => $owner->id,
        ]);

        Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio' => 'COT-1111', 'confirmed_at' => now()->subMinutes(10),
        ]);
        $latest = Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio' => 'COT-2222', 'confirmed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $rows = collect($response->json('data'))->where('project_name', 'Proyecto Con Versiones');
        $this->assertCount(1, $rows);
        $this->assertSame('COT-2222', $rows->first()['folio']);
    }
}
