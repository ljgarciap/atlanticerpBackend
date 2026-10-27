<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ClaimSheetService;
use App\Modules\Servicios\Services\InspectionReportService;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\Servicios\Services\TicketTypes;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 7b (docs/specs/fase7-catalogos.md) — dominio `ticket_type`: la siembra reproduce las constantes que había en `Ticket`
 * (equivalencia con los datos por defecto) y la fachada `TicketTypes` contesta por comportamiento, no por clave.
 */
final class TicketTypeCatalogTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_la_siembra_reproduce_las_constantes_de_ticket(): void
    {
        $this->assertEqualsCanonicalizing(['installation', 'warranty', 'claim', 'retrofit'], TicketTypes::keys());

        foreach (['installation' => 'INS', 'warranty' => 'GAR', 'claim' => 'REC', 'retrofit' => 'RET'] as $tipo => $prefijo) {
            $this->assertSame($prefijo, TicketTypes::prefijo($tipo), "prefijo de {$tipo}");
        }
        foreach (['installation' => ['installation', 'inspection'], 'warranty' => ['warranty_generic', 'replacement_inspection']] as $tipo => $subtipos) {
            $this->assertSame($subtipos, TicketTypes::subtipos($tipo), "subtipos de {$tipo}");
        }
        $this->assertSame([], TicketTypes::subtipos(Ticket::TIPO_CLAIM));
        $this->assertSame([], TicketTypes::subtipos(Ticket::TIPO_RETROFIT));
    }

    public function test_el_sla_sembrado_es_el_vigente_de_la_empresa(): void
    {
        $settings = app(ServiciosSettingsService::class);

        foreach (['installation', 'warranty', 'claim', 'retrofit'] as $tipo) {
            $this->assertSame($settings->slaDiasByTipo($tipo), TicketTypes::slaDias($tipo), "SLA de {$tipo}");
        }
    }

    public function test_cada_tipo_actual_tiene_su_comportamiento(): void
    {
        $this->assertSame(TicketTypes::INSTALACION, TicketTypes::comportamiento(Ticket::TIPO_INSTALLATION));
        $this->assertSame(TicketTypes::GARANTIA, TicketTypes::comportamiento(Ticket::TIPO_WARRANTY));
        $this->assertSame(TicketTypes::RECLAMO, TicketTypes::comportamiento(Ticket::TIPO_CLAIM));
        $this->assertSame(TicketTypes::RETROFIT, TicketTypes::comportamiento(Ticket::TIPO_RETROFIT));
        $this->assertTrue(TicketTypes::is(Ticket::TIPO_CLAIM, TicketTypes::RECLAMO));
        $this->assertSame([Ticket::TIPO_CLAIM], TicketTypes::keysWith(TicketTypes::RECLAMO));
    }

    public function test_una_entrada_nueva_sin_orden_va_al_final_del_catalogo(): void
    {
        (new CatalogService)->create(TicketTypes::DOMAIN, ['key' => 'mantenimiento', 'label' => 'Mantenimiento', 'props' => ['comportamiento' => 'generico']]);

        $this->assertSame(['installation', 'warranty', 'claim', 'retrofit', 'mantenimiento'], TicketTypes::keys());
    }

    public function test_un_tipo_desconocido_se_trata_como_generico(): void
    {
        $this->assertSame(TicketTypes::GENERICO, TicketTypes::comportamiento('inexistente'));
        $this->assertSame([], TicketTypes::subtipos('inexistente'));
    }

    public function test_un_tipo_nuevo_hereda_el_comportamiento_elegido(): void
    {
        $entry = (new CatalogService)->create(TicketTypes::DOMAIN, [
            'key' => 'mantenimiento', 'label' => 'Mantenimiento',
            'props' => ['comportamiento' => 'reclamo', 'prefijo' => 'MNT', 'sla_dias' => 4, 'subtipos' => ['preventivo', 'correctivo']],
        ]);

        $this->assertContains('mantenimiento', TicketTypes::keys());
        $this->assertTrue(TicketTypes::is('mantenimiento', TicketTypes::RECLAMO));
        $this->assertSame('MNT', TicketTypes::prefijo('mantenimiento'));
        $this->assertSame(4, TicketTypes::slaDias('mantenimiento'));
        $this->assertSame(['preventivo', 'correctivo'], TicketTypes::subtipos('mantenimiento'));
        $this->assertEqualsCanonicalizing([Ticket::TIPO_CLAIM, 'mantenimiento'], TicketTypes::keysWith(TicketTypes::RECLAMO));

        (new CatalogService)->delete($entry);
        $this->assertNotContains('mantenimiento', TicketTypes::keys());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function propiedadesInvalidas(): array
    {
        return [
            'comportamiento fuera del enum' => [['comportamiento' => 'otro']],
            'SLA no entero' => [['sla_dias' => '3']],
            'SLA cero' => [['sla_dias' => 0]],
            'prefijo vacío' => [['prefijo' => '']],
            'prefijo demasiado largo' => [['prefijo' => 'ABCDEF']],
            'prefijo en minúsculas o con símbolos' => [['prefijo' => 'mn-t']],
            'color que no es hex' => [['color' => 'url(http://x)']],
            'color hex corto' => [['color' => '#fff']],
            'subtipos con un número' => [['subtipos' => ['ok', 5]]],
            'propiedad desconocida' => [['inventada' => true]],
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     */
    #[DataProvider('propiedadesInvalidas')]
    public function test_las_propiedades_se_validan_contra_el_esquema_del_dominio(array $props): void
    {
        $this->expectException(ValidationException::class);

        (new CatalogService)->create(TicketTypes::DOMAIN, ['key' => 'x', 'label' => 'X', 'props' => $props]);
    }

    private function proyecto(): int
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"])->id;
    }

    /** Orden: fixtures Eloquent ANTES de loginAs() (cada request HTTP olvida el tenant actual). */
    public function test_un_tipo_nuevo_recorre_el_flujo_completo_por_la_api(): void
    {
        (new CatalogService)->create(TicketTypes::DOMAIN, [
            'key' => 'mantenimiento', 'label' => 'Mantenimiento',
            'props' => ['comportamiento' => 'reclamo', 'prefijo' => 'MNT', 'sla_dias' => 4, 'subtipos' => ['preventivo', 'correctivo']],
        ]);
        $proyecto = $this->proyecto();
        $token = $this->loginAs('liderservicios@test.com', 'liderservicios@test.com', 'lider_servicios');
        $h = $this->authHeaders($token);
        $base = ['tipo' => 'mantenimiento', 'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Revisión', 'sales_project_id' => $proyecto];

        // El tipo exige un subtipo del catálogo; uno ajeno se rechaza.
        $this->withHeaders($h)->postJson('/api/servicios/tickets', $base)->assertStatus(422)->assertJsonValidationErrors('subtipo');
        $this->withHeaders($h)->postJson('/api/servicios/tickets', $base + ['subtipo' => 'inspection'])->assertStatus(422);

        $r = $this->withHeaders($h)->postJson('/api/servicios/tickets', $base + ['subtipo' => 'preventivo'])->assertStatus(201);
        $this->assertStringStartsWith('MNT-', (string) $r->json('numero'));
        // Hereda el flujo de «reclamo»: cotización pendiente, sin informe de inspección.
        $r->assertJsonPath('quote_status', Ticket::QUOTE_STATUS_PENDING)->assertJsonPath('inspection_report_status', Ticket::INSPECTION_STATUS_NOT_APPLICABLE);

        // Un tipo inexistente o desactivado no se puede crear.
        $this->withHeaders($h)->postJson('/api/servicios/tickets', ['tipo' => 'inexistente'] + $base)->assertStatus(422)->assertJsonValidationErrors('tipo');

        // El SLA de un tipo nuevo se ajusta por la misma API que los 4 históricos y se refleja en el catálogo.
        $gerencia = $this->authHeaders($this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management'));
        $this->withHeaders($gerencia)->patchJson('/api/servicios/internal-technicians/sla-settings', ['mantenimiento' => 6])
            ->assertOk()->assertJsonPath('data.mantenimiento', 6);
        $this->activarTenant();
        $this->assertSame(6, TicketTypes::slaDias('mantenimiento'));
    }

    public function test_el_comportamiento_decide_hoja_de_reclamo_e_informe_de_inspeccion(): void
    {
        (new CatalogService)->create(TicketTypes::DOMAIN, ['key' => 'mantenimiento', 'label' => 'Mantenimiento', 'props' => ['comportamiento' => 'reclamo']]);
        $tipo = new Ticket(['tipo' => 'mantenimiento']);

        $this->assertTrue(app(ClaimSheetService::class)->isApplicable($tipo));
        $this->assertFalse(app(InspectionReportService::class)->isApplicable($tipo));
        $this->assertFalse(app(ClaimSheetService::class)->isApplicable(new Ticket(['tipo' => Ticket::TIPO_WARRANTY])));
        $this->assertTrue(app(InspectionReportService::class)->isApplicable(new Ticket(['tipo' => Ticket::TIPO_WARRANTY])));
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_los_tipos_de_sistema_no_se_borran_ni_se_desactivan(): void
    {
        $entry = CatalogEntry::query()->where('domain', TicketTypes::DOMAIN)->where('key', Ticket::TIPO_CLAIM)->firstOrFail();

        $this->expectException(\DomainException::class);
        (new CatalogService)->delete($entry);
    }
}
