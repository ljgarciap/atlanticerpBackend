<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1121 (P1) — la tabla de Arquitectos pasa a ser la base ÚNICA de asociados (arquitectos, diseñadores, contratistas, ingenieros,
 * consultores y otros): búsqueda global con autocompletado, tipo y documento, y detección de duplicados (D2 de Luis: correo, teléfono o
 * documento iguales BLOQUEAN; nombre parecido solo AVISA). Los arquitectos existentes y `quotes.architect_id` no cambian de significado.
 */
class AssociatesBaseTest extends FeatureTestCase
{
    private const DESIGNER = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
        $token = $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
        $this->tenant();

        return $token;
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    private function create(string $token, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/architects', $data);
    }

    // ── tipos, documento y búsqueda global ──────────────────────────────────────────────────────────────────

    public function test_se_crean_asociados_de_todos_los_tipos_con_documento(): void
    {
        $token = $this->token();

        foreach (Architect::TYPES as $i => $type) {
            $this->create($token, ['type' => $type, 'name' => "Asociado {$type}", 'document' => "DOC-{$i}-X"])
                ->assertStatus(201)
                ->assertJsonPath('type', $type)
                ->assertJsonPath('document', "DOC-{$i}-X");
        }

        $this->tenant();
        $this->assertSame(count(Architect::TYPES), Architect::where('created_by', '!=', null)->count());
    }

    public function test_tipo_invalido_retorna_422(): void
    {
        $token = $this->token();

        $this->create($token, ['type' => 'plomero', 'name' => 'X', 'phone' => '6000-0000'])->assertStatus(422);
    }

    public function test_sin_telefono_correo_ni_documento_retorna_422_pero_basta_uno(): void
    {
        $token = $this->token();

        $this->create($token, ['name' => 'Sin datos'])->assertStatus(422);
        $this->create($token, ['name' => 'Solo documento', 'document' => '8-123-456'])->assertStatus(201);
    }

    public function test_el_listado_sin_type_sigue_devolviendo_solo_arquitectos(): void
    {
        $token = $this->token();
        $this->create($token, ['type' => 'architect', 'name' => 'Arq Uno', 'phone' => '6000-0001'])->assertStatus(201);
        $this->create($token, ['type' => 'contractor', 'name' => 'Contratista Uno', 'phone' => '6000-0002'])->assertStatus(201);

        $names = collect($this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects')->assertStatus(200)->json('data'))->pluck('name');

        $this->assertContains('Arq Uno', $names->all());
        $this->assertNotContains('Contratista Uno', $names->all());
    }

    public function test_type_all_y_type_puntual_filtran(): void
    {
        $token = $this->token();
        $this->create($token, ['type' => 'architect', 'name' => 'Arq Dos', 'phone' => '6000-0003'])->assertStatus(201);
        $this->create($token, ['type' => 'designer', 'name' => 'Diseñadora Dos', 'phone' => '6000-0004'])->assertStatus(201);

        $all = collect($this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects?type=all')->json('data'))->pluck('name')->all();
        $this->assertContains('Arq Dos', $all);
        $this->assertContains('Diseñadora Dos', $all);

        $designers = collect($this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects?type=designer')->json('data'))->pluck('name')->all();
        $this->assertContains('Diseñadora Dos', $designers);
        $this->assertNotContains('Arq Dos', $designers);

        $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects?type=plomero')->assertStatus(422);
    }

    public function test_la_busqueda_global_encuentra_por_nombre_correo_telefono_y_documento_sin_acotar_por_subcliente(): void
    {
        $token = $this->token();
        $this->create($token, ['type' => 'engineer', 'name' => 'Ing. Zulema Quintero', 'email' => 'zulema@obras.test', 'phone' => '6677-8899', 'document' => '8-777-123'])->assertStatus(201);

        foreach (['zulema quint', 'obras.test', '66778899', '8-777'] as $term) {
            $found = collect($this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects?type=all&limit=20&search='.urlencode($term))->json('data'))->pluck('name')->all();
            $this->assertContains('Ing. Zulema Quintero', $found, "no encontró por «{$term}»");
        }
    }

    public function test_los_arquitectos_existentes_quedan_como_tipo_arquitecto(): void
    {
        $this->tenant();
        $legacy = Architect::create(['name' => 'Arq. Histórico', 'phone' => '6000-9999']);

        $this->assertSame('architect', Architect::findOrFail($legacy->id)->type);
    }

    // ── duplicados (D2) ─────────────────────────────────────────────────────────────────────────────────────

    public function test_correo_telefono_o_documento_iguales_bloquean_y_ofrecen_el_existente(): void
    {
        $token = $this->token();
        $first = $this->create($token, ['name' => 'Tecnico Vega', 'email' => 'Tecnico.Vega@Estudio.test', 'phone' => '+507 6000-1111', 'document' => '8-111-222'])
            ->assertStatus(201)->json('id');

        foreach ([
            ['name' => 'Otro Nombre', 'email' => 'tecnico.vega@estudio.test'],        // correo (mayúsculas distintas)
            ['name' => 'Otro Nombre', 'phone' => '507-6000.1111'],                    // teléfono (formato distinto, mismos dígitos)
            ['name' => 'Otro Nombre', 'document' => '8 111 222'],                     // documento (separadores distintos)
        ] as $payload) {
            $response = $this->create($token, $payload)->assertStatus(409);
            $this->assertSame('duplicate_exact', $response->json('code'));
            $this->assertSame($first, $response->json('duplicates.0.id'));
        }

        $this->tenant();
        $this->assertSame(1, Architect::where('name', 'Tecnico Vega')->count());
        $this->assertSame(0, Architect::where('name', 'Otro Nombre')->count());
    }

    public function test_un_dato_vacio_nunca_cuenta_como_coincidencia(): void
    {
        $token = $this->token();
        $this->create($token, ['name' => 'Sin Correo Uno', 'phone' => '6000-2001'])->assertStatus(201);

        // ambos sin correo ni documento: solo el teléfono (distinto) identifica; no hay coincidencia por vacío
        $this->create($token, ['name' => 'Sin Correo Dos', 'phone' => '6000-2002'])->assertStatus(201);
    }

    public function test_nombre_parecido_avisa_y_se_crea_al_confirmar(): void
    {
        $token = $this->token();
        $this->create($token, ['name' => 'María  Fernández', 'phone' => '6000-3001'])->assertStatus(201);

        $warn = $this->create($token, ['name' => 'maria fernandez', 'phone' => '6000-3002'])->assertStatus(409);
        $this->assertSame('duplicate_similar', $warn->json('code'));

        $this->create($token, ['name' => 'maria fernandez', 'phone' => '6000-3002', 'confirm_similar' => true])->assertStatus(201);

        $this->tenant();
        $this->assertSame(2, Architect::whereRaw("public.f_unaccent(lower(name)) like 'maria%fernandez'")->count());
    }

    public function test_editar_no_puede_dejar_dos_asociados_con_el_mismo_correo(): void
    {
        $token = $this->token();
        $a = $this->create($token, ['name' => 'Ana', 'email' => 'ana@x.test'])->assertStatus(201)->json('id');
        $b = $this->create($token, ['name' => 'Beto', 'email' => 'beto@x.test'])->assertStatus(201)->json('id');

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$b}", ['name' => 'Beto', 'email' => 'ANA@x.test'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_exact');

        // conservar el propio correo no es duplicado de sí mismo
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$a}", ['name' => 'Ana Editada', 'email' => 'ana@x.test'])
            ->assertStatus(200)->assertJsonPath('name', 'Ana Editada');
    }

    // ── el «Arquitecto» de la cotización sigue siendo solo de tipo arquitecto (origina la comisión) ──────────────

    public function test_el_arquitecto_de_una_cotizacion_debe_ser_de_tipo_arquitecto(): void
    {
        $token = $this->token();
        $designer = $this->create($token, ['type' => 'designer', 'name' => 'Diseñadora Q', 'phone' => '6000-4001'])->assertStatus(201)->json('id');
        $architect = $this->create($token, ['type' => 'architect', 'name' => 'Arquitecto Q', 'phone' => '6000-4002'])->assertStatus(201)->json('id');

        $this->tenant();
        $master = MasterClient::create(['name' => 'Cliente Q']);
        $quote = Quote::create([
            'owner_id' => (int) DB::table(Tenant::current()->slug.'_auth.users')->where('email', self::DESIGNER)->value('id'),
            'master_client_id' => $master->id,
        ]);

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $designer])
            ->assertStatus(422)->assertJsonValidationErrors(['architect_id']);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $architect])
            ->assertStatus(200);
    }

    // ── Senior Review (hallazgos 1, 2, 5 y menores) ─────────────────────────────────────────────────────────

    public function test_un_duplicado_historico_no_bloquea_corregir_el_nombre_pero_si_copiar_el_dato_de_otro(): void
    {
        $token = $this->token();
        // dos asociados que ya compartían teléfono antes de que existiera la regla (se insertan saltando la API)
        $a = Architect::create(['name' => 'Historico Uno', 'phone' => '6000-5555']);
        $b = Architect::create(['name' => 'Historico Dos', 'phone' => '6000-5555']);

        // corregir el nombre de uno NO toca el teléfono: no debe dar 409 por un duplicado que ya existía
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$a->id}", ['name' => 'Histórico Uno Corregido', 'phone' => '6000-5555'])
            ->assertStatus(200);

        // pero cambiar el teléfono a uno que ya tiene OTRO asociado sí se bloquea
        $this->tenant();
        $c = Architect::create(['name' => 'Tercero', 'phone' => '6000-7777']);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$c->id}", ['name' => 'Tercero', 'phone' => '6000-5555'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_exact');
        $this->assertNotNull($b);
    }

    public function test_un_arquitecto_ya_usado_en_una_cotizacion_no_puede_cambiar_de_tipo(): void
    {
        $token = $this->token();
        $arch = Architect::create(['name' => 'Arq. Con Comisión', 'phone' => '6000-8001']);
        $free = Architect::create(['name' => 'Arq. Libre', 'phone' => '6000-8002']);
        $master = MasterClient::create(['name' => 'Cliente Tipo']);
        Quote::create(['owner_id' => (int) DB::table(Tenant::current()->slug.'_auth.users')->where('email', self::DESIGNER)->value('id'), 'master_client_id' => $master->id, 'architect_id' => $arch->id]);

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$arch->id}", ['name' => 'Arq. Con Comisión', 'phone' => '6000-8001', 'type' => 'designer'])
            ->assertStatus(422);
        $this->tenant();
        $this->assertSame('architect', Architect::findOrFail($arch->id)->type);

        // mantener el mismo tipo, o cambiar uno que no se usa en cotizaciones, sí se puede
        $this->tenant();
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$arch->id}", ['name' => 'Arq. Renombrado', 'phone' => '6000-8001', 'type' => 'architect'])->assertStatus(200);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/architects/{$free->id}", ['name' => 'Arq. Libre', 'phone' => '6000-8002', 'type' => 'designer'])->assertStatus(200);
    }

    public function test_la_busqueda_ignora_acentos_y_trata_porcentaje_y_guion_bajo_como_texto(): void
    {
        $token = $this->token();
        Architect::create(['name' => 'Arq. Pérez Ñandú', 'phone' => '6000-9001']);
        Architect::create(['name' => 'Arq. Otro', 'phone' => '6000-9002']);

        $names = fn (string $q): array => collect($this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/architects?type=all&search='.urlencode($q))->json('data'))->pluck('name')->all();

        $this->assertContains('Arq. Pérez Ñandú', $names('perez nandu'));
        $this->assertContains('Arq. Pérez Ñandú', $names('PÉREZ'));

        // el documento se busca sin separadores: «8400444» encuentra «8-400-444»
        $this->tenant();
        Architect::create(['name' => 'Arq. Con Documento', 'document' => '8-400-444']);
        $this->assertContains('Arq. Con Documento', $names('8400444'));
        $this->assertContains('Arq. Con Documento', $names('8-400-444'));
        // % y _ son texto, no comodines: no devuelven toda la base
        $this->assertSame([], $names('%'));
        $this->assertSame([], $names('_'));
    }

    public function test_dos_altas_con_el_mismo_correo_una_tras_otra_solo_crean_una(): void
    {
        $token = $this->token();
        $first = $this->create($token, ['name' => 'Alta Uno', 'email' => 'mismo@alta.test']);
        $second = $this->create($token, ['name' => 'Alta Dos', 'email' => 'mismo@alta.test']);

        $this->assertSame(201, $first->status());
        $this->assertSame(409, $second->status());
        $this->tenant();
        $this->assertSame(1, Architect::whereRaw("lower(email) = 'mismo@alta.test'")->count());
    }
}
