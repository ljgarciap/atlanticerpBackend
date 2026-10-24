<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\AdminCont\Services\AdminContSettingsService;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\CRM\Services\CrmSettingsService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 1 (docs/specs/fase1-limpieza-nucleo.md, bloque C) — umbrales de negocio que estaban fijos en código y ahora viven en tablas de ajustes:
 * valor por defecto idéntico al de antes, cambio por la API de ajustes (solo con el permiso de configurar) y efecto inmediato en la regla.
 */
class BusinessThresholdsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(string $email, string $role): string
    {
        return $this->loginAs($email, $email, $role);
    }

    private function reactivarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── Pipeline estancado (CRM) ─────────────────────────────────────────────────────────────────

    public function test_pipeline_estancado_usa_15_por_defecto_y_respeta_el_ajuste(): void
    {
        $card = (new PipelineCard())->forceFill(['stage_changed_at' => now()->subDays(20)]);
        $this->assertTrue($card->isStagnant(), 'con el default (15) una tarjeta de 20 días está estancada');

        app(CrmSettingsService::class)->updateSettings(['pipeline_estancado_dias' => 30]);

        $this->assertFalse($card->isStagnant(), 'con el umbral en 30 ya no lo está');
        $this->assertSame(30, app(CrmSettingsService::class)->allSettings()['pipeline_estancado_dias']);
    }

    public function test_contacto_obsoleto_de_clientes_tiene_default_60_y_es_configurable(): void
    {
        $this->assertSame(60, app(CrmSettingsService::class)->clienteContactoObsoletoDias());
        app(CrmSettingsService::class)->updateSettings(['cliente_contacto_obsoleto_dias' => 90]);
        $this->assertSame(90, app(CrmSettingsService::class)->clienteContactoObsoletoDias());
    }

    public function test_ajustes_de_crm_solo_los_cambia_el_superadmin(): void
    {
        $gerencia = $this->authHeaders($this->token('gerencia@test.com', 'management'));
        $this->withHeaders($gerencia)->putJson('/api/crm/settings', ['pipeline_estancado_dias' => 20])->assertStatus(403);

        $super = $this->authHeaders($this->token('superadmin@test.com', 'superadmin'));
        $this->withHeaders($super)->putJson('/api/crm/settings', ['pipeline_estancado_dias' => 20])->assertStatus(200);
        $this->withHeaders($super)->putJson('/api/crm/settings', ['pipeline_estancado_dias' => 0])->assertStatus(422);
    }

    // ── Orden crítica «Por aprobar» (Compras) ──────────────────────────────────────────────────

    public function test_orden_por_aprobar_es_critica_a_los_3_dias_por_defecto_y_respeta_el_ajuste(): void
    {
        $orden = (new PurchaseOrder())->forceFill(['status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now()->subDays(4)]);
        $this->assertTrue($orden->isCritical(), 'con el default (3) una orden de 4 días es crítica');

        $super = $this->authHeaders($this->token('superadmin@test.com', 'superadmin'));
        $this->withHeaders($super)->putJson('/api/compras/settings', ['critical_days_por_aprobar' => 10])->assertStatus(200);
        $this->reactivarTenant();
        app()->forgetScopedInstances();

        $this->assertFalse($orden->isCritical(), 'con el umbral en 10 una orden de 4 días ya no es crítica');
    }

    public function test_ajuste_de_orden_critica_exige_el_permiso_de_configurar(): void
    {
        $sinPermiso = $this->authHeaders($this->token('liderbodega@test.com', 'lider_bodega'));
        $this->withHeaders($sinPermiso)->putJson('/api/compras/settings', ['critical_days_por_aprobar' => 5])->assertStatus(403);

        $super = $this->authHeaders($this->token('superadmin@test.com', 'superadmin'));
        $this->withHeaders($super)->putJson('/api/compras/settings', ['critical_days_por_aprobar' => 400])->assertStatus(422);
    }

    // ── Comisión pendiente (AdminCont) ────────────────────────────────────────────────────────────

    public function test_comision_pendiente_tiene_default_10_y_se_cambia_por_la_api(): void
    {
        $this->assertSame(10, app(AdminContSettingsService::class)->comisionPendienteDias());

        $super = $this->authHeaders($this->token('superadmin@test.com', 'superadmin'));
        $this->withHeaders($super)->putJson('/api/admin-contab/settings', ['comision_pendiente_dias' => 20])->assertStatus(200);
        $this->reactivarTenant();

        $this->assertSame(20, app(AdminContSettingsService::class)->comisionPendienteDias());
        $this->withHeaders($super)->putJson('/api/admin-contab/settings', ['comision_pendiente_dias' => 0])->assertStatus(422);
    }

    public function test_ajuste_de_comision_pendiente_exige_el_permiso_de_configurar(): void
    {
        $sinPermiso = $this->authHeaders($this->token('asistenteadministrativa@test.com', 'asistente_administrativa'));
        $this->withHeaders($sinPermiso)->putJson('/api/admin-contab/settings', ['comision_pendiente_dias' => 20])->assertStatus(403);
    }
}
