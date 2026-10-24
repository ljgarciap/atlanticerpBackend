<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Tipo A — bootstrap obligatorio, todos los entornos
        $this->call(TenantSeeder::class);
        $this->call(SecurityLevelSeeder::class);
        $this->call(DepartmentSeeder::class);
        $this->call(CoreUserSeeder::class);
        $this->call(PrimaryApproverBackfillSeeder::class);
        $this->call(AdminContPrimaryApproverBackfillSeeder::class);
        $this->call(RoleBackfillSeeder::class);
        $this->call(BusinessRoleUserSeeder::class);
        // Después de BusinessRoleUserSeeder a propósito — resuelve al usuario Felix
        // (contabilidad@test.com), que solo existe a partir de ese seeder.
        $this->call(AdminContFiscalIdentitySeeder::class);
        // SCRUM-928 — cuentas bancarias de la empresa: las crea solo si la tabla está vacía (prod) y
        // rellena el número completo de Banco General si está vacío; nunca pisa, reactiva ni duplica.
        // Se salta en `testing` (Senior Review ALTO-1): `FeatureTestCase` corre este seeder tras cada
        // `migrate:fresh` y los tests de Facturas/Estado de Cuenta/Cobros crean SU cuenta asumiendo una
        // base sin cuentas — mismo motivo por el que `CatalogUatTestDataSeeder` se salta `testing`.
        // El test propio (`AdminContBankAccountSeederTest`) invoca la clase directamente.
        if (! app()->environment('testing')) {
            $this->call(AdminContBankAccountSeeder::class);
        }
        $this->call(VentasDisenoSalesGoalsSeeder::class);
        // SCRUM-1120 — Cliente Master/Subcliente «CotizaYA» y su fila de ajustes (idempotente; reutiliza el de prod).
        $this->call(CotizaYaClientSeeder::class);
        $this->call(SpecialPermissionSeeder::class);
        // SCRUM-1139 — override de visibilidad de módulo por persona (líder de operaciones ve Admin&Cont como Félix).
        $this->call(UserModuleVisibilityGrantSeeder::class);
        // SCRUM-881 — corrección de rol (no de persona puntual, por eso va separado de
        // SpecialPermissionSeeder): asistente_administrativa necesita can_view_team en admin_contab.
        $this->call(AdminContRoleVisibilityBackfillSeeder::class);
        $this->call(InternalTechnicianSeeder::class);
        $this->call(WarehouseAssistantMappingSeeder::class);
        $this->call(GlobalSettingsSeeder::class);
        $this->call(AiModelPricingSeeder::class);
        $this->call(ZonaLibreProviderSeeder::class);
        // SCRUM-970 (épica SCRUM-969) — provider real distinto de Zona Libre de Colón (arriba),
        // usado por la integración Lafayette Lighting para atribuir/deduplicar productos. Se salta
        // en `testing` (mismo motivo que AdminContBankAccountSeeder arriba): a diferencia de Zona
        // Libre de Colón, ningún código referencia este provider por lógica de negocio — solo
        // ensucia conteos/orden fijos que ProviderControllerTest ya asume (hallazgo real,
        // ver git blame). Los tests del módulo Integrations\Lafayette crean su propio fixture.
        if (! app()->environment('testing')) {
            $this->call(LafayetteLightingProviderSeeder::class);
        }
        // SCRUM-745 — data de prueba de Catálogo pedida explícitamente en Producción para UAT.
        // Idempotente (guard por prefijo 'UAT-'); se salta 'testing' internamente (catalog_products
        // es tabla compartida con Bodega/Compras, ver guard propio en CatalogUatTestDataSeeder::run()).
        $this->call(CatalogUatTestDataSeeder::class);
        // SCRUM-1010 — familias compartidas (Flua Luna S / Mylight Riel Magnético Mini y Normal) de
        // Inventario → Familias. Se salta `testing`: `catalog_product_families` se lista/cuenta en
        // tests que no scopean sus fixtures; `SharedFamiliesSeederTest` invoca la clase directamente.
        if (! app()->environment('testing')) {
            $this->call(SharedFamiliesSeeder::class);
        }

        // Tipo B + C — datos demo: máquina local (local), CI (testing) y dev.atlanticerp.ai (dev).
        // Nunca en test.atlanticerp.ai (qa) ni en prod (production).
        if (app()->environment(['local', 'dev', 'testing'])) {
            $this->call(AuthUserSeeder::class);
            $this->call(CrmDemoSeeder::class);
            $this->call(VentasDisenoDemoSeeder::class);
            $this->call(ServiciosDemoSeeder::class);
            $this->call(CatalogProductSeeder::class);
            $this->call(CatalogProductFamilySeeder::class);
            $this->call(DemoProviderAssignmentSeeder::class);
            $this->call(DemoReplacementRequestSeeder::class);
            // Last on purpose (SCRUM-717): backfills any CatalogProduct with stock_quantity > 0
            // and no product_warehouse_stock rows — must run after every other Tipo B/C seeder
            // that can create catalog products (DemoReplacementRequestSeeder seeds 3 of its own),
            // or those would be created too late to get picked up.
            $this->call(ProductWarehouseStockBackfillSeeder::class);
        }
    }
}
