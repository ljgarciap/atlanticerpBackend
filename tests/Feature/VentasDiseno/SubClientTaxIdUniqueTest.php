<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Exceptions\DuplicateSubClientTaxIdException;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 17a — una identificación fiscal por tenant: clave normalizada (sin espacios/guiones/puntos, en mayúsculas) + DV, 409 con el cliente
 * existente en los caminos de alta y edición, y respaldo por índice único en la BD.
 */
class SubClientTaxIdUniqueTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, mixed> */
    private function payload(string $ruc, string $dv = '12', string $suffix = 'A'): array
    {
        return [
            'name' => "Cliente {$suffix}", 'business_name' => "Razón {$suffix} S.A.", 'tax_id' => $ruc, 'tax_id_dv' => $dv, 'receptor_tipo' => '01',
            'contacts' => [['name' => 'Ana', 'role' => 'client', 'email' => "ana.{$suffix}@example.test"]],
        ];
    }

    private function sub(string $ruc, ?string $dv = '12', string $name = 'Existente'): SubClient
    {
        $master = MasterClient::create(['name' => "Maestro {$name}"]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => $name, 'tax_id' => $ruc, 'tax_id_dv' => $dv]);
    }

    public function test_la_clave_ignora_formato_y_mayusculas_y_distingue_el_dv(): void
    {
        $this->assertSame('1551234567|12', SubClient::taxIdKey('155-123-4567', '12'));
        $this->assertSame('1551234567|12', SubClient::taxIdKey(' 155.123.4567 ', '12'));
        $this->assertSame('E123|', SubClient::taxIdKey('e-123'));
        $this->assertNotSame(SubClient::taxIdKey('155-123-4567', '12'), SubClient::taxIdKey('155-123-4567', '13'));
        $this->assertNull(SubClient::taxIdKey('', null), 'sin identificación (consumidor final) no hay clave');
        $this->assertNull(SubClient::taxIdKey('---', '12'));
    }

    public function test_alta_de_cliente_con_la_misma_identificacion_responde_409_con_el_existente(): void
    {
        $existente = $this->sub('155-123-4567');
        $token = $this->loginAs('vendedor@atlantic.test');

        $respuesta = $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->payload('1551234567', '12', 'B'));

        $respuesta->assertStatus(409)->assertJsonPath('code', 'duplicate_tax_id')
            ->assertJsonPath('existing.sub_client_id', $existente->id)
            ->assertJsonPath('existing.business_name', 'Existente');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, SubClient::query()->where('tax_id_key', $existente->tax_id_key)->count());
    }

    public function test_otro_dv_con_el_mismo_numero_se_acepta(): void
    {
        $this->sub('155-123-4567', '12');
        $token = $this->loginAs('vendedor@atlantic.test');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $this->payload('155-123-4567', '13', 'C'))->assertStatus(201);
    }

    public function test_alta_de_subcliente_en_un_cliente_existente_tambien_se_bloquea(): void
    {
        $existente = $this->sub('155-123-4567');
        $maestro = MasterClient::create(['name' => 'Otro maestro']);
        $token = $this->loginAs('vendedor@atlantic.test');

        $this->withHeaders($this->authHeaders($token))->postJson("/api/ventas-diseno/clients/{$maestro->id}/sub-clients", [
            'business_name' => 'Duplicado', 'tax_id' => '155 123 4567', 'tax_id_dv' => '12', 'receptor_tipo' => '01',
            'contacts' => [['name' => 'Ana', 'role' => 'client', 'email' => 'ana@example.test']],
        ])->assertStatus(409)->assertJsonPath('existing.sub_client_id', $existente->id);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/sub-clients', [
            'master_client_id' => $maestro->id, 'business_name' => 'Duplicado', 'tax_id' => '155-123-4567', 'tax_id_dv' => '12', 'receptor_tipo' => '01',
        ])->assertStatus(409)->assertJsonPath('code', 'duplicate_tax_id');
    }

    public function test_editar_los_datos_fiscales_no_choca_consigo_mismo_pero_si_con_otro(): void
    {
        $a = $this->sub('155-111-1111', '11', 'Uno');
        $b = $this->sub('155-222-2222', '22', 'Dos');
        $token = $this->loginAs('vendedor@atlantic.test');
        $datos = fn (SubClient $s, string $ruc, string $dv): array => [
            'business_name' => $s->business_name, 'tax_id' => $ruc, 'tax_id_dv' => $dv, 'receptor_tipo' => '01',
        ];

        $this->withHeaders($this->authHeaders($token))->patchJson("/api/ventas-diseno/clients/sub-clients/{$a->id}/tax-data", $datos($a, '155.111.1111', '11'))->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))->patchJson("/api/ventas-diseno/clients/sub-clients/{$a->id}/tax-data", $datos($a, '155-222-2222', '22'))
            ->assertStatus(409)->assertJsonPath('existing.sub_client_id', $b->id);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('155.111.1111', $a->fresh()?->tax_id, 'el rechazo no cambia nada');
    }

    public function test_sin_identificacion_no_entra_al_indice_y_se_pueden_repetir(): void
    {
        $this->sub('', null, 'Consumidor 1');
        $this->sub('', null, 'Consumidor 2');

        $this->assertSame(2, SubClient::query()->whereNull('tax_id_key')->count());
    }

    public function test_el_modelo_lanza_la_excepcion_aunque_no_pase_por_la_api(): void
    {
        $this->sub('155-123-4567');

        $this->expectException(DuplicateSubClientTaxIdException::class);
        $this->sub('155-123-4567', '12', 'Repetido');
    }

    public function test_proveedores_comparan_la_identificacion_sin_formato(): void
    {
        $token = $this->loginAs('management@atlantic.test');
        $base = ['name' => 'Proveedor Uno', 'category' => 'locales', 'country' => 'Panamá', 'phone' => '6000-0000', 'tax_id' => '8-123-4567', 'tax_id_dv' => '12'];
        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/providers', $base)->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/providers', array_merge($base, ['name' => 'Proveedor Dos', 'tax_id' => '8.123.4567']))
            ->assertStatus(422)->assertJsonValidationErrors('tax_id');
    }

    public function test_el_indice_unico_respalda_la_regla_ante_una_carrera(): void
    {
        $existente = $this->sub('155-123-4567');
        $maestro = MasterClient::create(['name' => 'Maestro carrera']);

        // `saveQuietly` se salta la verificación del modelo, como haría una segunda petición simultánea.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        (new SubClient(['master_client_id' => $maestro->id, 'business_name' => 'Carrera', 'tax_id' => '155-123-4567', 'tax_id_dv' => '12']))
            ->forceFill(['tax_id_key' => $existente->tax_id_key])->saveQuietly();
    }
}
