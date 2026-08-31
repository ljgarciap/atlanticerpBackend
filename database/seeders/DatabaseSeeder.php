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
        $this->call(VentasDisenoSalesGoalsSeeder::class);
        $this->call(SpecialPermissionSeeder::class);
        $this->call(InternalTechnicianSeeder::class);
        $this->call(WarehouseAssistantMappingSeeder::class);
        $this->call(GlobalSettingsSeeder::class);
        $this->call(AiModelPricingSeeder::class);
        $this->call(ZonaLibreProviderSeeder::class);
        // SCRUM-745 — data de prueba de Catálogo pedida explícitamente en Producción para UAT.
        // Idempotente (guard por prefijo 'UAT-'); se salta 'testing' internamente (catalog_products
        // es tabla compartida con Bodega/Compras, ver guard propio en CatalogUatTestDataSeeder::run()).
        $this->call(CatalogUatTestDataSeeder::class);

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
