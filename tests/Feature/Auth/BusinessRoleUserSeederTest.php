<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\NoLoginAccounts;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-148 ("Usuarios - Correos") — roster real de 25 personas de negocio de SCRUM-144 que no
 * tenían cuenta (Gerencia ya existía vía CoreUserSeeder). Verifica los casos puntuales acordados con
 * Luis: el override de nivel de Gerencia, el correo "Correo 1" de Contabilidad, el correo genérico de
 * Ayudante General Bodega, que Vendedor Disenador (10mo Vendedor/Diseñador) se creó igual, y que los 3 sin cargo NO se crearon.
 *
 * No hay test de "no se manda WelcomeMail" — RefreshDatabase siembra una sola vez por proceso de
 * test, antes de que `Mail::fake()` (en `FeatureTestCase::setUp()`) empiece a interceptar, así que
 * `Mail::assertNothingSent()` no sería una aserción real. La garantía real es de código:
 * `BusinessRoleUserSeeder` no importa `Mail`/`WelcomeMail` en absoluto, a diferencia de
 * `UserService::create()`.
 */
class BusinessRoleUserSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_restringido_queda_en_rol_gerencia_con_override_de_nivel_restringido(): void
    {
        $gerencia = Role::where('key', 'management')->firstOrFail();
        $restricted = User::where('email', 'gerencia5@test.com')->firstOrFail();

        $this->assertSame($gerencia->id, $restricted->role_id);
        $this->assertSame(8, $restricted->securityLevel?->level, 'Gerencia debe quedar en "Gerencia Restringida" (Nivel 8), no el default del rol (Nivel 9).');
        $this->assertFalse((bool) $restricted->approve_large_amounts);
        $this->assertFalse((bool) $restricted->manage_users);
    }

    public function test_contabilidad_usa_el_correo_1_del_ticket(): void
    {
        $liderAdminContab = Role::where('key', 'lider_admin_contab')->firstOrFail();
        $contabilidad = User::where('email', 'contabilidad@test.com')->first();

        $this->assertNotNull($contabilidad, 'Contabilidad debe existir con el correo de la columna "Correo 1" (contabilidad@test.com).');
        $this->assertNull(User::where('email', 'contabilidad-alterno@test.com')->first(), 'El "Correo 2" de Contabilidad no debe usarse.');
        $this->assertSame($liderAdminContab->id, $contabilidad->role_id);
        $this->assertTrue((bool) $contabilidad->manage_users);
    }

    public function test_apolonio_usa_correo_generico_nombre_apellido(): void
    {
        $ayudanteBodega = Role::where('key', 'auxiliar_bodega')->firstOrFail();
        $apolonio = User::where('email', 'ayudantegeneralbodega@test.com')->first();

        $this->assertNotNull($apolonio, 'Ayudante General Bodega debe existir con el correo genérico del rol (ayudantegeneralbodega@test.com).');
        $this->assertSame($ayudanteBodega->id, $apolonio->role_id);
    }

    public function test_vendedor_disenador_incluye_a_idmar_el_decimo(): void
    {
        // Cuenta acotada al roster real (no al total de role_id=vendedor de la BD):
        // en local/testing, AuthUserSeeder también le asigna este rol al fixture
        // designer@atlantic.test (Task 4, ver ADR-006), así que un conteo global daría 11,
        // no 10 — no es un bug, es esperado en este entorno.
        $roster = [
            'vendedordisenador2@test.com', 'vendedordisenador3@test.com', 'vendedordisenador4@test.com',
            'vendedordisenador5@test.com', 'vendedordisenador6@test.com', 'vendedordisenador7@test.com',
            'vendedordisenador8@test.com', 'vendedordisenador9@test.com', 'vendedordisenador10@test.com',
            'vendedordisenador@test.com',
        ];

        $vendedor = Role::where('key', 'vendedor')->firstOrFail();
        $count = User::where('role_id', $vendedor->id)->whereIn('email', $roster)->count();

        // 9 originales de SCRUM-144 + Vendedor Disenador (cargo confirmado en SCRUM-148, aunque no contado
        // originalmente) = 10.
        $this->assertSame(10, $count);
        $this->assertNotNull(User::where('email', 'vendedordisenador10@test.com')->first());
    }

    public function test_vendedor_disenador_usa_el_nivel_default_del_rol(): void
    {
        // Fase 4 — Servicios (2026-08-02): vendedor se movió de Nivel 4 ("Power User",
        // compartido, can_edit=true en bloque para los 7 módulos) a Nivel 6 ("Supervisor", angosto
        // — can_edit=true solo en ventas_diseno) para poder darle visibilidad de solo lectura en
        // `servicios` sin heredar edición ahí. Ver
        // 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php.
        $neil = User::where('email', 'vendedordisenador2@test.com')->firstOrFail();
        $this->assertSame(6, $neil->securityLevel?->level);
    }

    public function test_dan_rosales_sigue_sin_cargo_y_sin_crear(): void
    {
        $this->assertNull(User::where('first_name', 'Dan')->first());
    }

    // SCRUM-1142 — Picker Bodega Test (picker) y Repartidor Test (repartidor) ya tienen cargo.
    public function test_carlos_gonzalez_es_picker_y_joel_somarriba_es_repartidor(): void
    {
        $picker = Role::where('key', 'auxiliar_bodega')->firstOrFail();
        $courier = Role::where('key', 'transporte')->firstOrFail();

        $carlos = User::where('email', 'pickerbodega@test.com')->first();
        $joel = User::where('email', 'repartidor@test.com')->first();

        $this->assertNotNull($carlos);
        $this->assertSame($picker->id, $carlos->role_id);
        $this->assertSame('Picker Bodega Test', trim("{$carlos->first_name} {$carlos->last_name}"));
        $this->assertNotNull($joel);
        $this->assertSame($courier->id, $joel->role_id);
        $this->assertSame('Repartidor Test', trim("{$joel->first_name} {$joel->last_name}"));
    }

    // SCRUM-1142 — Tecnico Servicios, Repartidor y "Personal de Bodega" existen solo para aparecer en los selectores: nadie entra con ellos.
    public function test_las_cuentas_de_bodega_sin_acceso_no_aceptan_contrasena_igual_al_correo(): void
    {
        $courier = Role::where('key', 'transporte')->firstOrFail();

        foreach (NoLoginAccounts::EMAILS as $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "{$email} debe existir para aparecer en los selectores.");
            $this->assertTrue((bool) $user->is_active, 'assignCourier() rechaza repartidores inactivos.');
            $this->assertFalse(Hash::check($email, $user->password_hash), "{$email} no debe poder entrar con su correo como contraseña.");
        }

        $personal = User::where('email', 'personalbodega@test.com')->firstOrFail();
        $this->assertSame($courier->id, $personal->role_id);
        $this->assertSame('Personal de Bodega', trim("{$personal->first_name} {$personal->last_name}"));
    }

    public function test_el_resto_del_roster_sigue_con_contrasena_igual_al_correo(): void
    {
        $apolonio = User::where('email', 'ayudantegeneralbodega@test.com')->firstOrFail();

        $this->assertTrue(Hash::check('ayudantegeneralbodega@test.com', $apolonio->password_hash));
    }

    public function test_25_personas_nuevas_mas_aprobador_ya_existente_suman_26_del_roster_de_scrum_144(): void
    {
        $emails = [
            'gerencia5@test.com', 'contabilidad@test.com', 'asistenteadministrativa@test.com',
            'lidercompras@test.com', 'liderbodega@test.com', 'asistentebodega@test.com',
            'asistentebodega2@test.com', 'ayudantegeneralbodega@test.com', 'transporte@test.com',
            'liderservicios@test.com', 'tecnicoservicios@test.com', 'tecnicoservicios2@test.com',
            'tecnicoservicios3@test.com', 'garantiasservicios@test.com', 'vendedordisenador@test.com',
            'vendedordisenador2@test.com', 'vendedordisenador3@test.com', 'vendedordisenador4@test.com',
            'vendedordisenador5@test.com', 'vendedordisenador6@test.com', 'vendedordisenador7@test.com',
            'vendedordisenador8@test.com', 'vendedordisenador9@test.com', 'vendedordisenador10@test.com',
            'lideroperaciones@test.com',
        ];

        $this->assertCount(25, $emails);
        $this->assertSame(25, User::whereIn('email', $emails)->count());
        $this->assertNotNull(User::where('email', 'gerencia3@test.com')->first(), 'Gerencia ya existía vía CoreUserSeeder.');
    }
}
