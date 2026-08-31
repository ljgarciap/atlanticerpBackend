<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-006 — mapeo rol→nivel (2026-07-09), `RoleBackfillSeeder` (`database/seeders/
 * RoleBackfillSeeder.php`). Corrección 2026-07-10: "Gerencia Total" reusa el Nivel 9 ("Admin")
 * existente en vez de un nivel nuevo — el mismo nivel en el que Mark/David/Daniela ya estaban
 * desde `CoreUserSeeder`. El backfill nunca toca `security_level_id`, ni antes ni después del
 * cutover de Fase C: no hace falta, 9 ya es el nivel correcto bajo el diseño corregido.
 */
class RoleBackfillTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_mark_david_daniela_quedan_en_rol_gerencia_con_flags_transversales(): void
    {
        $gerencia = Role::where('key', 'management')->firstOrFail();

        foreach (['gerencia3@test.com', 'gerencia2@test.com', 'gerencia@test.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame($gerencia->id, $user->role_id, "{$email} debería tener role_id=gerencia.");
            $this->assertTrue((bool) $user->approve_large_amounts, "{$email} debería tener approve_large_amounts=true.");
            $this->assertTrue((bool) $user->manage_users, "{$email} debería tener manage_users=true.");
        }
    }

    public function test_gerencia_real_permanece_en_nivel_9_gerencia_total(): void
    {
        // Corrección 2026-07-10: Nivel 9 ("Admin") YA ES "Gerencia Total" bajo el diseño
        // corregido (reusa la escala 1-10 existente) -- Mark/David/Daniela no se mueven de ahí.
        foreach (['gerencia3@test.com', 'gerencia2@test.com', 'gerencia@test.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame(9, $user->securityLevel?->level, "{$email} debería permanecer en nivel 9 (Gerencia Total).");
        }
    }

    public function test_backfill_no_toca_security_level_id_de_superadmins_reales(): void
    {
        // Andrés/Luis/Luis J ya están en Nivel 10 (Superadmin) -- bypass por is_superadmin/
        // superadmin.all en ambos algoritmos, no necesitan reasignación. Marly es un caso aparte
        // (CoreUserSeeder la deja en Nivel 9 a propósito, "técnico, no rol de negocio", ver
        // pregunta 7 del daily 2026-07-09) -- role_id=superadmin igual le da el bypass completo
        // sin importar su security_level_id numérico.
        foreach ([
            'superadmin@test.com',
            'superadmin2@test.com',
            'superadmin3@test.com',
        ] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame(10, $user->securityLevel?->level, "{$email} debería seguir en nivel 10 (Superadmin).");
        }

        $marly = User::where('email', 'gerencia4@test.com')->firstOrFail();
        $this->assertSame(9, $marly->securityLevel?->level, 'Marly debería seguir en nivel 9 (su nivel original, sin relación al mapeo rol→nivel).');
    }

    public function test_marly_queda_en_rol_superadmin_no_en_gerencia(): void
    {
        // Pregunta 7 del daily 2026-07-09: Marly es QA de plataforma, aunque su fila legada en
        // user_roles siga diciendo role='management' — role_id nuevo es superadmin, no gerencia.
        $superadmin = Role::where('key', 'superadmin')->firstOrFail();
        $marly = User::where('email', 'gerencia4@test.com')->firstOrFail();

        $this->assertSame($superadmin->id, $marly->role_id);
    }

    public function test_los_3_individuos_sin_cargo_definido_siguen_sin_cuenta(): void
    {
        // SCRUM-148 ("Usuarios - Correos") completó el correo de 25 de las 26 personas de
        // negocio de SCRUM-144 (Whil incluido, ver BusinessRoleUserSeederTest) — pero Carlos
        // Gonzalez/Joel Somarria/Dan Rosales aparecen en ese ticket sin cargo, así que siguen sin
        // cuenta: nada que backfillear todavía, y no se inventa un rol para ellos.
        $this->assertNull(User::where('first_name', 'Carlos')->where('last_name', 'Gonzalez')->first());
    }
}
