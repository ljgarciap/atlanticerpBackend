<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// REQ-031: agregar/quitar contactos (sub_client_contacts) a una cotización.
class QuoteContactControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    public function test_agregar_contacto_de_otro_subcliente_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $otherSub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Otro Sub', 'tax_id' => '155-1111-1-2026']);
        $otherContact = $otherSub->contacts()->create(['name' => 'Ajeno', 'role' => 'client', 'phone' => '6000-0000']);

        $quote = Quote::create(['sub_client_id' => $sub->id, 'master_client_id' => $master->id, 'owner_id' => $designerId]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/contacts", ['sub_client_contact_id' => $otherContact->id])
            ->assertStatus(422);
    }

    public function test_agregar_y_quitar_contacto_de_la_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $contact = $sub->contacts()->create(['name' => 'Ana Pérez', 'role' => 'client', 'phone' => '6000-0000']);

        $quote = Quote::create(['sub_client_id' => $sub->id, 'master_client_id' => $master->id, 'owner_id' => $designerId]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/contacts", ['sub_client_contact_id' => $contact->id])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$quote->id}")
            ->assertStatus(200);
        $this->assertCount(1, $detail->json('contacts'));
        $this->assertSame('Ana Pérez', $detail->json('contacts.0.name'));

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/contacts/{$contact->id}")
            ->assertStatus(204);

        $afterRemoval = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$quote->id}")
            ->assertStatus(200);
        $this->assertCount(0, $afterRemoval->json('contacts'));
    }

    public function test_agregar_contacto_a_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $managerId = $this->userIdByEmail('management@illuminations.test');

        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $contact = $sub->contacts()->create(['name' => 'Ana Pérez', 'role' => 'client', 'phone' => '6000-0000']);
        $quote = Quote::create(['sub_client_id' => $sub->id, 'master_client_id' => $master->id, 'owner_id' => $managerId]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/contacts", ['sub_client_contact_id' => $contact->id])
            ->assertStatus(403);
    }
}
