<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\CustomerReturnLine;
use App\Modules\CRM\Models\Contact;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14c (docs/specs/fase14-parametrizacion.md) — contact_role, adjustment_reason, return_reason y technician_specialty pasan de listas
 * fijas del código a catálogos por empresa: se siembran con los valores de siempre, una entrada propia se acepta al validar, y las claves
 * con significado para la lógica son de sistema (no se borran).
 */
final class FixedListCatalogsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activarTenant();
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    public function test_se_siembran_exactamente_los_valores_que_antes_eran_constantes(): void
    {
        $this->assertSame(Contact::ALLOWED_TYPES, CatalogService::keys('contact_role'));
        $this->assertSame(AdjustmentRequestLine::MOTIVOS, CatalogService::keys('adjustment_reason'));
        $this->assertSame(CustomerReturnLine::REASONS, CatalogService::keys('return_reason'));
        $this->assertSame([InternalTechnician::ESPECIALIDAD_GENERAL, InternalTechnician::ESPECIALIDAD_WARRANTY], CatalogService::keys('technician_specialty'));
    }

    public function test_las_claves_con_significado_para_la_logica_son_de_sistema(): void
    {
        $sistema = fn (string $domain): array => CatalogEntry::query()->where('domain', $domain)->where('is_system', true)->orderBy('sort_order')->pluck('key')->all();

        $this->assertSame(Contact::ALLOWED_TYPES, $sistema('contact_role'));
        $this->assertSame([AdjustmentRequestLine::MOTIVO_OTRO_ESPECIFICAR], $sistema('adjustment_reason'));
        $this->assertSame([CustomerReturnLine::REASON_OTRA], $sistema('return_reason'));
        $this->assertSame(['general', 'warranty'], $sistema('technician_specialty'));

        $h = $this->superadmin();
        $this->activarTenant();
        $id = CatalogEntry::query()->where('domain', 'technician_specialty')->where('key', 'warranty')->value('id');
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/technician_specialty/{$id}")->assertStatus(422);
    }

    public function test_una_entrada_propia_se_acepta_al_validar_y_una_inactiva_se_rechaza(): void
    {
        $h = $this->superadmin();
        foreach (['adjustment_reason' => 'merma_proceso', 'return_reason' => 'garantia_proveedor', 'contact_role' => 'proveedor_servicio', 'technician_specialty' => 'electrico'] as $domain => $key) {
            $this->withHeaders($h)->postJson("/api/admin/catalogs/{$domain}", ['key' => $key, 'label' => 'Propia'])->assertCreated();
        }

        $this->activarTenant();
        $this->assertContains('merma_proceso', CatalogService::keys('adjustment_reason'));
        $this->assertContains('electrico', CatalogService::keys('technician_specialty'));

        // Un técnico con la especialidad propia se crea (antes solo valían general y warranty).
        $liderServicios = $this->authHeaders($this->loginAs('liderservicios@test.com', 'liderservicios@test.com', 'lider_servicios'));
        $this->withHeaders($liderServicios)->postJson('/api/servicios/internal-technicians', ['nombre' => 'Técnico Eléctrico', 'especialidad' => 'electrico'])->assertCreated();
        $this->withHeaders($liderServicios)->postJson('/api/servicios/internal-technicians', ['nombre' => 'Otro', 'especialidad' => 'inexistente'])->assertStatus(422);

        $this->activarTenant();
        $id = (int) CatalogEntry::query()->where('domain', 'adjustment_reason')->where('key', 'merma_proceso')->value('id');
        $this->withHeaders($h)->putJson("/api/admin/catalogs/adjustment_reason/{$id}", ['label' => 'Propia', 'is_active' => false])->assertOk();
        $this->activarTenant();
        $this->assertNotContains('merma_proceso', CatalogService::keys('adjustment_reason'));
    }
}
