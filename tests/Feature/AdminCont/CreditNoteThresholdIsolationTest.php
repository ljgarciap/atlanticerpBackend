<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\Compras\Models\PurchaseApprovalRule;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14d (3.4) — el umbral de Configuración Fiscal es el de las NOTAS DE CRÉDITO: Compras decide con sus propias reglas
 * (`purchase_approval_rules`), de modo que cambiar el umbral no altera qué órdenes requieren aprobación.
 */
final class CreditNoteThresholdIsolationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_la_columna_se_llama_umbral_de_notas_de_credito_y_conserva_el_valor_por_defecto(): void
    {
        $tabla = Tenant::current()->slug.'_admin_contab.fiscal_settings';

        $this->assertTrue(Schema::hasColumn($tabla, 'credit_note_approval_threshold'));
        $this->assertFalse(Schema::hasColumn($tabla, 'primary_approval_threshold'));
        $this->assertSame(5000.0, AdminContFiscalSettings::current()->credit_note_approval_threshold);
    }

    public function test_cambiar_el_umbral_de_notas_de_credito_no_cambia_las_reglas_de_aprobacion_de_compras(): void
    {
        PurchaseApprovalRule::query()->update(['is_active' => false]);
        PurchaseApprovalRule::create(['name' => 'Grandes', 'sort_order' => 1, 'is_active' => true, 'min_amount' => 3000, 'requires_approval' => true]);

        $settings = AdminContFiscalSettings::current();
        foreach ([1.0, 5000.0, 1000000.0] as $umbral) {
            $settings->update(['credit_note_approval_threshold' => $umbral]);
            $this->assertTrue(PurchaseOrder::computeRequiresPrimaryApproval(null, null, 4000.0), "umbral {$umbral}");
            $this->assertFalse(PurchaseOrder::computeRequiresPrimaryApproval(null, null, 2000.0), "umbral {$umbral}");
        }
    }
}
