<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\Servicios\Services\TicketTypes;
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
        $this->assertEqualsCanonicalizing(Ticket::TIPOS, TicketTypes::keys());

        foreach (Ticket::PREFIX_BY_TIPO as $tipo => $prefijo) {
            $this->assertSame($prefijo, TicketTypes::prefijo($tipo), "prefijo de {$tipo}");
        }
        foreach (Ticket::SUBTIPOS_BY_TIPO as $tipo => $subtipos) {
            $this->assertSame($subtipos, TicketTypes::subtipos($tipo), "subtipos de {$tipo}");
        }
        $this->assertSame([], TicketTypes::subtipos(Ticket::TIPO_CLAIM));
        $this->assertSame([], TicketTypes::subtipos(Ticket::TIPO_RETROFIT));
    }

    public function test_el_sla_sembrado_es_el_vigente_de_la_empresa(): void
    {
        $settings = app(ServiciosSettingsService::class);

        foreach (Ticket::TIPOS as $tipo) {
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

    public function test_los_tipos_de_sistema_no_se_borran_ni_se_desactivan(): void
    {
        $entry = CatalogEntry::query()->where('domain', TicketTypes::DOMAIN)->where('key', Ticket::TIPO_CLAIM)->firstOrFail();

        $this->expectException(\DomainException::class);
        (new CatalogService)->delete($entry);
    }
}
