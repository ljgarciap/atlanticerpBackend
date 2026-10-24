<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1121 (corrección de Gerencia) — los contactos de la cotización pasan al campo «Contacto» de la tarjeta de Pipeline al confirmarla
 * (o al agregarlos después, si ya está confirmada): solo agrega, sin duplicar y sin borrar nada de la tarjeta.
 */
class QuoteContactsToPipelineCardTest extends FeatureTestCase
{
    private const DESIGNER = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    /** El login/la petición HTTP del test deja el tenant sin activar: se reactiva para que los modelos sigan resolviendo sus schemas. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $response;
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

        return $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    private function ownerId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)->value('id');
    }

    /**
     * Cotización lista para generar (con ítem y fecha) sobre un proyecto NUEVO, con los contactos dados vinculados. Sin tarjeta todavía.
     *
     * @param list<array{name: string, role?: string, phone?: string|null, email?: string|null}> $contacts
     * @return array{quote: Quote, project: SalesProject, sub: SubClient}
     */
    private function draftQuote(array $contacts): array
    {
        $master  = MasterClient::create(['name' => 'Cliente '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $arch    = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);

        $quote = Quote::create([
            'owner_id' => $this->ownerId(), 'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id,
            'ruc' => '155-0000-1-2026', 'description' => 'COT', 'architect_id' => $arch->id,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'Luminaria', 'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 5]);

        foreach ($contacts as $c) {
            $quote->contacts()->attach($sub->contacts()->create(['role' => 'client', ...$c])->id);
        }

        return ['quote' => $quote, 'project' => $project, 'sub' => $sub];
    }

    private function generateAndConfirm(string $token, Quote $quote): void
    {
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/confirm")->assertStatus(200);
        $this->tenant();
    }

    private function cardOf(SalesProject $project): PipelineCard
    {
        return PipelineCard::where('sales_project_id', $project->id)->firstOrFail();
    }

    /** @return list<string> nombres de los contactos de la tarjeta, ordenados */
    private function cardContactNames(PipelineCard $card): array
    {
        $names = $card->contacts()->pluck('name')->all();
        sort($names);

        return $names;
    }

    public function test_al_confirmar_los_contactos_de_la_cotizacion_aparecen_en_el_contacto_de_la_tarjeta(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project] = $this->draftQuote([
            ['name' => 'Ana Cliente', 'phone' => '6111-1111', 'email' => 'ana@cliente.test'],
            ['name' => 'Beto Arquitecto', 'role' => 'architect', 'email' => 'beto@arq.test'],
        ]);

        $this->generateAndConfirm($token, $quote);

        $card = $this->cardOf($project);
        $this->assertSame(['Ana Cliente', 'Beto Arquitecto'], $this->cardContactNames($card));
        $ana = $card->contacts()->where('name', 'Ana Cliente')->firstOrFail();
        $this->assertSame('ana@cliente.test', $ana->email);
        $this->assertSame('6111-1111', $ana->phone);
        $this->assertSame('client', $ana->role);
        $this->assertSame('architect', $card->contacts()->where('name', 'Beto Arquitecto')->value('role'));
    }

    public function test_no_duplica_un_contacto_que_la_tarjeta_ya_tiene_por_correo_telefono_o_nombre_y_rol(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project] = $this->draftQuote([
            ['name' => 'Ana Cliente', 'email' => 'ANA@cliente.test'],
            ['name' => 'Beto Sin Correo', 'phone' => '+507 6222-2222'],
            ['name' => 'Cármen Pérez'],
            ['name' => 'Diego Nuevo', 'email' => 'diego@cliente.test'],
        ]);
        // La tarjeta (p. ej. el Lead automático) ya tiene a los tres primeros, escritos distinto.
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'owner_id' => $this->ownerId(),
            'created_by' => $this->ownerId(), 'stage_changed_at' => now(),
        ]);
        $card->contacts()->create(['name' => 'A. Cliente', 'role' => 'client', 'email' => 'ana@cliente.test']);
        $card->contacts()->create(['name' => 'Otro nombre', 'role' => 'client', 'phone' => '60 6222 2222 ']);
        $card->contacts()->create(['name' => 'carmen  perez', 'role' => 'client']);

        $this->generateAndConfirm($token, $quote);

        $this->assertSame(4, $card->contacts()->count(), 'solo Diego es nuevo');
        $this->assertSame(1, $card->contacts()->where('name', 'Diego Nuevo')->count());
    }

    public function test_nunca_borra_ni_modifica_los_contactos_que_ya_estaban_en_la_tarjeta(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project] = $this->draftQuote([['name' => 'Ana Cliente', 'email' => 'ana@cliente.test']]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'owner_id' => $this->ownerId(),
            'created_by' => $this->ownerId(), 'stage_changed_at' => now(),
        ]);
        $manual = $card->contacts()->create(['name' => 'Cargado a mano en Pipeline', 'role' => 'other', 'phone' => '6999-9999', 'email' => 'mano@x.test']);

        $this->generateAndConfirm($token, $quote);

        $this->assertSame(['Ana Cliente', 'Cargado a mano en Pipeline'], $this->cardContactNames($card));
        $manual->refresh();
        $this->assertSame(['other', '6999-9999', 'mano@x.test'], [$manual->role, $manual->phone, $manual->email]);
    }

    public function test_confirmar_otra_vez_o_una_segunda_version_no_duplica(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project] = $this->draftQuote([['name' => 'Ana Cliente', 'email' => 'ana@cliente.test']]);
        $this->generateAndConfirm($token, $quote);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/confirm")->assertStatus(200);

        $this->assertSame(1, $this->cardOf($project)->contacts()->count());
    }

    public function test_un_contacto_agregado_a_una_cotizacion_ya_confirmada_pasa_a_la_tarjeta_y_a_un_borrador_no(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project, 'sub' => $sub] = $this->draftQuote([['name' => 'Ana Cliente', 'email' => 'ana@cliente.test']]);

        // Borrador: agregar un contacto no toca Pipeline (SCRUM-734: la tarjeta ni existe todavía).
        $draftContact = $sub->contacts()->create(['name' => 'Contacto Borrador', 'role' => 'client', 'email' => 'borrador@x.test']);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/contacts", ['sub_client_contact_id' => $draftContact->id])->assertStatus(201);
        $this->assertSame(0, PipelineCard::where('sales_project_id', $project->id)->count());

        $this->generateAndConfirm($token, $quote);
        $this->assertSame(['Ana Cliente', 'Contacto Borrador'], $this->cardContactNames($this->cardOf($project)));

        // Confirmada: un contacto nuevo pasa de inmediato.
        $late = $sub->contacts()->create(['name' => 'Contacto Tardío', 'role' => 'other', 'phone' => '6333-3333']);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/contacts", ['sub_client_contact_id' => $late->id])->assertStatus(201);

        $this->assertSame(['Ana Cliente', 'Contacto Borrador', 'Contacto Tardío'], $this->cardContactNames($this->cardOf($project)));
    }

    public function test_el_comando_de_relleno_agrega_lo_que_falta_es_idempotente_y_el_dry_run_no_escribe(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'project' => $project] = $this->draftQuote([['name' => 'Ana Cliente', 'email' => 'ana@cliente.test']]);
        $this->generateAndConfirm($token, $quote);
        // Simula una cotización confirmada ANTES del fix: la tarjeta existe pero sin el contacto.
        $card = $this->cardOf($project);
        $card->contacts()->delete();

        $this->artisan('ventas-diseno:backfill-card-contacts', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->expectsOutputToContain('contactos que se agregarían: 1')->assertSuccessful();
        $this->assertSame(0, $card->contacts()->count(), 'el dry-run no escribe');

        $this->artisan('ventas-diseno:backfill-card-contacts', ['--tenant' => 'atlantic'])
            ->expectsOutputToContain('contactos agregados: 1')->assertSuccessful();
        $this->assertSame(['Ana Cliente'], $this->cardContactNames($card));

        $this->artisan('ventas-diseno:backfill-card-contacts', ['--tenant' => 'atlantic'])
            ->expectsOutputToContain('contactos agregados: 0')->assertSuccessful();
        $this->assertSame(1, $card->contacts()->count());
    }

    public function test_el_comando_rechaza_un_tenant_que_no_existe(): void
    {
        $this->artisan('ventas-diseno:backfill-card-contacts', ['--tenant' => 'no-existe'])->assertFailed();
    }
}
