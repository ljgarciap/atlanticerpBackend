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
        // Fase 4a (docs/specs/fase4-dataset-demo-generico.md) — TRES niveles:
        //   Núcleo neutro (este bloque): corre en TODOS los entornos; nada con nombre de empresa, persona ni sector, y ningún usuario (Fase 4d).
        //   Demo genérico (bloque de abajo): solo local/dev/testing.
        //   Datos del cliente anterior: YA NO se siembran por defecto (un tenant nuevo nace sin identidad fiscal, cuentas bancarias, proveedores ni
        //   catálogo ajenos). Sus seeders quedan SIN LLAMADOR y con valores de ejemplo, hasta borrarse con Legacy/Lafayette en la Fase 5:
        //   AdminContFiscalIdentity, AdminContBankAccount, ZonaLibreProvider, LafayetteLightingProvider, SharedFamilies, CatalogUatTestData, AlmacenEquivalencia.
        //   Se corren a mano con `php artisan db:seed --class=...`; la base de pruebas los carga vía `tests/Support/TestDatabaseSeeder`.
        // Tipo A — bootstrap obligatorio, todos los entornos
        $this->call(TenantSeeder::class);
        $this->call(SecurityLevelSeeder::class);
        $this->call(DepartmentSeeder::class);
        $this->call(SpecialPermissionSeeder::class);
        // SCRUM-881 — corrección de rol (no de persona puntual, por eso va separado de
        // SpecialPermissionSeeder): asistente_administrativa necesita can_view_team en admin_contab.
        $this->call(AdminContRoleVisibilityBackfillSeeder::class);
        $this->call(GlobalSettingsSeeder::class);
        $this->call(AiModelPricingSeeder::class);
        // Tipo B + C — datos demo: máquina local (local), CI (testing) y dev.atlanticerp.ai (dev).
        // Nunca en test.atlanticerp.ai (qa) ni en prod (production).
        if (app()->environment(['local', 'dev', 'testing'])) {
            // Fase 4d (docs/specs/fase4d-superadmin-inicial.md) — las identidades `*@test.com` (contraseña = correo) y todo seeder que las busca por correo
            // son DEMO: ya no nacen en `qa` ni `production`. El primer superadmin real se da de alta con `php artisan tenant:create-superadmin`.
            // Orden: usuarios primero, luego lo que los referencia.
            $this->call(CoreUserSeeder::class);
            $this->call(PrimaryApproverBackfillSeeder::class);
            $this->call(AdminContPrimaryApproverBackfillSeeder::class);
            $this->call(RoleBackfillSeeder::class);
            $this->call(BusinessRoleUserSeeder::class);
            $this->call(VentasDisenoSalesGoalsSeeder::class);
            // SCRUM-1139 — override de visibilidad de módulo por persona (líder de operaciones ve Admin&Cont como Contabilidad).
            $this->call(UserModuleVisibilityGrantSeeder::class);
            $this->call(InternalTechnicianSeeder::class);
            $this->call(WarehouseAssistantMappingSeeder::class);
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
