<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;
use Tests\Support\UniqueTaxId;

/**
 * Fase 18 — los segmentos de cliente son un catálogo por empresa (dominio `client_segment`): los 4 históricos se siembran como entradas de
 * sistema con sus claves de siempre y etiquetas neutras; la API valida contra el catálogo (propio sí, desactivado o desconocido no).
 */
final class ClientSegmentCatalogTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    private function subCliente(?string $segmento = null): SubClient
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $master = MasterClient::create(['name' => 'Cliente '.uniqid()]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => UniqueTaxId::next(), 'category' => $segmento]);
    }

    public function test_los_cuatro_segmentos_historicos_se_siembran_con_etiquetas_neutras(): void
    {
        $this->assertSame(['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred'], CatalogService::keys('client_segment'));
        $b = CatalogEntry::query()->where('domain', 'client_segment')->where('key', 'b_architect_designer')->firstOrFail();
        $this->assertSame('B · Prescriptor', $b->label);
        $this->assertTrue($b->is_system);
        $this->assertSame('B · Prescriber', $b->labels['en'] ?? null);
        $this->assertStringNotContainsStringIgnoringCase('arquitecto', (string) CatalogEntry::query()->where('domain', 'client_segment')->pluck('label')->implode(' '));
    }

    public function test_un_segmento_propio_se_acepta_al_dar_de_alta_y_al_editar(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/client_segment', ['key' => 'corporativo', 'label' => 'E · Corporativo'])->assertCreated();
        $sub = $this->subCliente();
        $token = $this->authHeaders($this->loginAs('vendedor@atlantic.test'));

        $this->withHeaders($token)->patchJson("/api/ventas-diseno/clients/sub-clients/{$sub->id}/category", ['category' => 'corporativo'])
            ->assertOk()->assertJsonPath('category', 'corporativo');
        $this->withHeaders($token)->postJson('/api/ventas-diseno/clients', [
            'name' => 'Cliente nuevo', 'business_name' => 'Nuevo S.A.', 'tax_id' => UniqueTaxId::next(), 'tax_id_dv' => '12', 'category' => 'corporativo',
            'contacts' => [['name' => 'Ana', 'role' => 'client', 'email' => 'ana@example.test']],
        ])->assertCreated();
    }

    public function test_un_segmento_desconocido_o_desactivado_se_rechaza(): void
    {
        $h = $this->superadmin();
        $sub = $this->subCliente();
        $token = $this->authHeaders($this->loginAs('vendedor@atlantic.test'));

        $this->withHeaders($token)->patchJson("/api/ventas-diseno/clients/sub-clients/{$sub->id}/category", ['category' => 'inexistente'])->assertStatus(422);

        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $id = (int) CatalogEntry::query()->where('domain', 'client_segment')->where('key', 'c_bidding')->value('id');
        $this->withHeaders($h)->putJson("/api/admin/catalogs/client_segment/{$id}", ['label' => 'C · Licitación', 'is_active' => false])->assertStatus(422);

        $propio = $this->withHeaders($h)->postJson('/api/admin/catalogs/client_segment', ['key' => 'temporal', 'label' => 'Temporal'])->assertCreated()->json('id');
        $this->withHeaders($h)->putJson("/api/admin/catalogs/client_segment/{$propio}", ['label' => 'Temporal', 'is_active' => false])->assertOk();
        $this->withHeaders($token)->patchJson("/api/ventas-diseno/clients/sub-clients/{$sub->id}/category", ['category' => 'temporal'])->assertStatus(422);
    }

    public function test_los_de_sistema_y_los_en_uso_no_se_borran(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/client_segment', ['key' => 'en_uso', 'label' => 'En uso'])->assertCreated();
        $this->subCliente('en_uso');
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        $sistema = (int) CatalogEntry::query()->where('domain', 'client_segment')->where('key', 'a_walkin')->value('id');
        $enUso = (int) CatalogEntry::query()->where('domain', 'client_segment')->where('key', 'en_uso')->value('id');
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/client_segment/{$sistema}")->assertStatus(422);
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/client_segment/{$enUso}")->assertStatus(422);
    }
}
