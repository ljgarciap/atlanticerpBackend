<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestDatabaseSeeder;
use Tests\TestCase;

/**
 * Clase base para todos los Feature tests de AtlanticERP.
 *
 * Estrategia de fixtures (distinta a la de Laravel estándar):
 * ─────────────────────────────────────────────────────────────
 * Laravel's RefreshDatabase hace `migrate:fresh` antes de cada suite de tests.
 * En AtlanticERP usamos multi-tenancy schema-per-tenant con PostgreSQL, lo que implica:
 *
 *   1. La tabla `tenants` vive en el schema `public` (conexión `landlord`).
 *      RefreshDatabase corre las migrations de la carpeta `database/migrations/`
 *      (registradas en AppServiceProvider::boot via loadMigrationsFrom) — eso
 *      incluye la landlord migration que crea la tabla `tenants`.
 *
 *   2. Después del migrate:fresh, el seeder `DatabaseSeeder` llama:
 *      a. TenantSeeder  → ejecuta `tenant:create atlantic` que crea los
 *         schemas Postgres (`atlantic_auth`, `atlantic_crm`,
 *         `atlantic_servicios`, etc. — ver `Tenant::schemas()`) y corre
 *         las migrations de tenant.
 *      b. AuthUserSeeder → activa el tenant y crea los 4 usuarios de prueba.
 *
 *   3. Las migrations de tenant NO están en `database/migrations/` sino en
 *      `database/migrations/tenant/`. RefreshDatabase NO las toca — las corre
 *      el command `tenant:create` a través de `tenants:artisan`. Esto garantiza
 *      que cada schema se migre dentro del tenant correcto.
 *
 * Por esta razón usamos `$seed = true` y `$seeder = DatabaseSeeder::class`
 * en vez de llamar al seeder manualmente en setUp(): RefreshDatabase se encarga
 * de correr el seeder después del migrate:fresh automáticamente.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase correrá DatabaseSeeder tras el migrate:fresh.
     * DatabaseSeeder → TenantSeeder (crea tenant + schemas + migrations tenant)
     *                → AuthUserSeeder (crea 4 usuarios de prueba).
     */
    protected bool $seed = true;

    /**
     * DatabaseSeeder + concesiones por persona de binax (solo tests): ver TestDatabaseSeeder. `SpecialPermissionSeeder::GRANTS`
     * queda vacío en Atlantic.
     */
    protected string $seeder = TestDatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // SCRUM-29: Symfony\Request::create() (usado por getJson/postJson cuando
        // no se especifica el header) simula un browser por defecto con
        // 'Accept-Language: en-us,en;q=0.5' — sin este default explícito, todos
        // los tests corrían con locale 'en' en vez del 'es' real de producción,
        // rompiendo cualquier aserción sobre mensajes de trans(). Tests que
        // necesiten otro idioma pueden sobreescribirlo con su propio withHeaders().
        $this->withHeaders(['Accept-Language' => 'es']);
    }

    protected function loginAs(
        string $email    = 'management@atlantic.test',
        string $password = 'Password123!',
        string $role     = 'management',
    ): string {
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertStatus(200);

        // login() devuelve siempre el JWT en un solo paso (ADR-006 Fase C; select-role se eliminó en SCRUM-1035).
        return (string) $response->json('token');
    }

    /** @return array<string, string> */
    protected function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Contador estático por proceso — reemplaza el patrón `random_int(10000, 99999)` que se
     * repetía en ~19 archivos de test para armar folios/números de prueba (ej. `'INS-2026-'.
     * random_int(10000, 99999)`). Ese patrón NO garantiza unicidad, solo la hace improbable
     * (90000 valores posibles) — con miles de folios creados en la suite completa, la paradoja
     * del cumpleaños producía colisiones reales e intermitentes contra columnas `unique` (ej.
     * `servicios_tickets_numero_uq`), nunca reproducibles en aislado. Un contador estático dentro
     * del mismo proceso de PHPUnit sí garantiza unicidad real, no solo baja probabilidad de
     * choque. Arranca en 500000 para no solaparse nunca con folios reales (secuenciales, chicos)
     * generados por el servicio de conteo real de cada módulo.
     */
    private static int $uniqueTestNumberCounter = 500000;

    protected static function uniqueTestNumber(): int
    {
        return ++self::$uniqueTestNumberCounter;
    }

    /**
     * SCRUM-66/177 — cualquier test que dispare OutlookCalendarService (Home de Ventas & Diseño o
     * de Compras) sin llamar esto haría una llamada de red real a Microsoft. Opt-in explícito, no
     * automático en setUp() — mismo criterio que los fakes de Anthropic en este proyecto, para no
     * pisar el `Http::fake()` propio de un test que sí necesite otro escenario.
     *
     * @param list<array<string, mixed>> $events
     */
    protected function fakeOutlookCalendar(array $events = []): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-graph-token', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response(['value' => $events], 200),
        ]);
    }

    /**
     * SCRUM-1150 — devuelve a Félix al estado de SCRUM-1139 (sin el override de Ventas & Diseño ni los permisos de Mark), para los
     * tests que lo usan como fixture de "usuario de Admin&Cont con solo permisos angostos de Pipeline". El estado real de Félix
     * (igual a Mark) lo cubre FelixComoMarkVentasDisenoTest.
     */
    protected function felixComoEnScrum1139(): void
    {
        $felix = \App\Models\User::where('email', 'contabilidad@test.com')->firstOrFail();
        \App\Modules\Auth\Models\UserModuleVisibility::where('user_id', $felix->id)->where('module', 'ventas_diseno')->delete();
        $felix->update(['extra_permissions' => array_values(array_diff(
            $felix->extra_permissions ?? [],
            ['ventas_diseno.select_partner_price', 'ventas_diseno.override_min_margin', 'ventas_diseno.gerencia_scope'],
        ))]);
    }

    /**
     * SCRUM-1177 — el registro público ya no existe; una cuenta pendiente de aprobación se crea por el mismo servicio que usaba.
     */
    protected function crearCuentaPendiente(string $first, string $last, string $email): void
    {
        $tenant = \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();
        try {
            app(\App\Modules\Auth\Services\UserService::class)->register([
                'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'department_id' => (int) \Illuminate\Support\Facades\DB::table('atlantic_auth.departments')->value('id'),
            ]);
        } finally {
            \App\Shared\Multitenancy\Tenant::forgetCurrent();
        }
    }
}
