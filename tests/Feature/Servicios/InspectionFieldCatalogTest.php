<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\InspectionReportService;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 34c — etiqueta y obligatoriedad de los campos de sistema del informe de inspección por empresa (catálogo `inspection_field`).
 */
final class InspectionFieldCatalogTest extends FeatureTestCase
{
    private const LIDER = 'liderservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activarTenant();
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Prueba', 'cliente' => 'Cliente', 'estado' => Ticket::ESTADO_ON_SITE,
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    private function editar(string $key, array $cambios): void
    {
        $id = (int) CatalogEntry::query()->where('domain', 'inspection_field')->where('key', $key)->value('id');
        $this->withHeaders($this->superadmin())->putJson("/api/admin/catalogs/inspection_field/{$id}", $cambios)->assertOk();
        $this->activarTenant();
    }

    public function test_se_siembran_todos_los_campos_de_sistema_sin_obligatoriedad(): void
    {
        $claves = array_keys(InspectionReportService::defaultFieldLabels());
        $this->assertSame([], array_diff($claves, CatalogEntry::query()->where('domain', 'inspection_field')->where('is_system', true)->pluck('key')->all()));
        $this->assertSame(0, CatalogEntry::query()->where('domain', 'inspection_field')->where('props->obligatorio', true)->count());
    }

    public function test_el_conjunto_es_fijo_no_se_crean_ni_borran_campos(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/inspection_field', ['key' => 'campo_nuevo', 'label' => 'Nuevo'])->assertStatus(422);
        $this->activarTenant();
        $id = (int) CatalogEntry::query()->where('domain', 'inspection_field')->where('key', 'checklist_tecnico')->value('id');
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/inspection_field/{$id}")->assertStatus(422);
    }

    public function test_la_etiqueta_editada_llega_al_formulario_y_se_empareja_por_clave(): void
    {
        $this->editar('checklist_tecnico', ['label' => 'Lista de verificación']);
        $ticket = $this->ticket();
        $h = $this->authHeaders($this->loginAs(self::LIDER, self::LIDER, 'lider_servicios'));

        $campos = collect($this->withHeaders($h)->getJson("/api/servicios/tickets/{$ticket->id}/inspection-report/fields")->assertOk()->json('data'));
        $this->assertSame('Lista de verificación', $campos->firstWhere('key', 'checklist_tecnico')['label']);
        $this->assertFalse($campos->firstWhere('key', 'checklist_tecnico')['obligatorio']);

        $this->activarTenant();
        $r = $this->withHeaders($h)->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
            'conclusion' => 'ok', 'findings' => [['key' => 'recomendacion_cotizacion', 'label' => 'Texto viejo cualquiera', 'value' => 'Cambiar el equipo']],
        ])->assertOk();
        $r->assertJsonPath('findings.0.key', 'recomendacion_cotizacion');
        $r->assertJsonPath('findings.0.is_quote_recommendation', true);
    }

    public function test_un_campo_obligatorio_se_exige_al_completar_pero_no_en_borrador(): void
    {
        $this->editar('checklist_tecnico', ['props' => ['obligatorio' => true]]);
        $ticket = $this->ticket();
        $h = $this->authHeaders($this->loginAs(self::LIDER, self::LIDER, 'lider_servicios'));
        $vacio = ['conclusion' => 'ok', 'findings' => [['key' => 'checklist_tecnico', 'label' => 'Checklist técnico', 'value' => '  ']]];

        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", $vacio)->assertStatus(422);

        $this->activarTenant();
        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [...$vacio, 'borrador' => true])->assertOk();

        $this->activarTenant();
        $lleno = ['conclusion' => 'ok', 'findings' => [['key' => 'checklist_tecnico', 'label' => 'Checklist técnico', 'value' => 'Revisado']]];
        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", $lleno)->assertOk();
    }
}
