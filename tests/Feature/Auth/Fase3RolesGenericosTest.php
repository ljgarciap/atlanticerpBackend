<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\CRM\Models\NotificationRule;
use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 3 del aislamiento del núcleo (docs/specs/fase3-roles-genericos.md) — catálogo de roles genérico.
 * La migración renombra 4 roles por id: ningún usuario cambia de rol ni de permisos.
 */
class Fase3RolesGenericosTest extends FeatureTestCase
{
    private const MIGRACION = 'migrations/tenant/2026_10_25_200000_rename_roles_to_generic_catalog.php';

    /** @var array<string, string> clave nueva => nombre visible nuevo */
    private const NUEVOS = [
        'vendedor' => 'Vendedor', 'auxiliar_bodega' => 'Auxiliar de Bodega', 'tecnico' => 'Técnico', 'tecnico_garantias' => 'Técnico de Garantías',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<int, array{role_id: int|null, permissions: list<string>}> */
    private function snapshotUsuarios(): array
    {
        $snap = [];
        foreach (User::query()->orderBy('id')->get() as $u) {
            $perms = $u->effectivePermissionsV2();
            sort($perms);
            $snap[$u->id] = ['role_id' => $u->role_id, 'permissions' => $perms];
        }

        return $snap;
    }

    public function test_el_catalogo_de_la_bd_coincide_exactamente_con_rolekeys(): void
    {
        $enBd = Role::pluck('key')->all();
        sort($enBd);
        $declaradas = RoleKeys::all();
        sort($declaradas);

        $this->assertSame($declaradas, $enBd);
        foreach (self::NUEVOS as $key => $name) {
            $this->assertSame($name, Role::where('key', $key)->value('name'));
        }
    }

    public function test_down_y_up_conservan_el_rol_y_los_permisos_de_cada_usuario(): void
    {
        $antes = $this->snapshotUsuarios();
        $this->assertGreaterThan(30, count($antes));
        $migracion = require database_path(self::MIGRACION);

        $migracion->down();
        $this->assertSame(['ayudante_general_bodega', 'garantias_servicios', 'tecnico_servicios', 'vendedor_disenador'],
            collect(['vendedor_disenador', 'ayudante_general_bodega', 'tecnico_servicios', 'garantias_servicios'])->filter(fn ($k) => Role::where('key', $k)->exists())->sort()->values()->all());
        $this->assertSame('Vendedor/Diseñador', Role::where('key', 'vendedor_disenador')->value('name'));
        $this->assertSame($antes, $this->snapshotUsuarios(), 'el rename por id no cambia rol ni permisos de nadie');

        $migracion->up();
        $this->assertSame($antes, $this->snapshotUsuarios());
        $this->assertSame(0, Role::whereIn('key', ['vendedor_disenador', 'ayudante_general_bodega', 'tecnico_servicios', 'garantias_servicios'])->count());
    }

    public function test_las_reglas_de_notificacion_por_rol_se_traducen_y_vuelven(): void
    {
        $regla = NotificationRule::create([
            'name' => 'Regla Fase 3', 'trigger_type' => 'model_event', 'trigger_model' => 'Project', 'trigger_event' => 'updated',
            'field' => 'etapa', 'operator' => 'changed_to', 'value' => 'cerrado', 'channels' => ['in_app'],
            'recipient_type' => 'role', 'recipient_value' => ['vendedor', 'management', 'tecnico_garantias'], 'is_active' => true,
        ]);
        $porUsuario = NotificationRule::create([
            'name' => 'Por usuario', 'trigger_type' => 'model_event', 'trigger_model' => 'Project', 'trigger_event' => 'updated',
            'field' => 'etapa', 'operator' => 'changed_to', 'value' => 'cerrado', 'channels' => ['in_app'],
            'recipient_type' => 'user', 'recipient_value' => [1, 2], 'is_active' => true,
        ]);
        $migracion = require database_path(self::MIGRACION);

        $migracion->down();
        $this->assertSame(['vendedor_disenador', 'management', 'garantias_servicios'], $regla->fresh()->recipient_value);
        $this->assertSame([1, 2], $porUsuario->fresh()->recipient_value);

        $migracion->up();
        $this->assertSame(['vendedor', 'management', 'tecnico_garantias'], $regla->fresh()->recipient_value);
    }

    public function test_up_aborta_sin_tocar_nada_si_ya_existe_una_clave_destino(): void
    {
        $migracion = require database_path(self::MIGRACION);
        $migracion->down();
        Role::create(['key' => 'tecnico', 'name' => 'Creado a mano', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);

        try {
            $migracion->up();
            $this->fail('debió abortar: ya existe un rol «tecnico»');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Ya existe un rol con la clave «tecnico»', $e->getMessage());
        }

        $this->assertTrue(Role::where('key', 'vendedor_disenador')->exists(), 'al abortar no se renombró nada');
        $this->assertFalse(Role::where('key', 'vendedor')->exists());
    }

    /** Decisión de Luis: reingreso obligado, sin alias. El claim `role` viejo no da ni quita nada: los permisos viajan aparte y ningún gate compara el claim con las 4 claves renombradas. */
    public function test_un_token_con_la_clave_vieja_en_el_claim_role_sigue_resolviendo_por_permisos(): void
    {
        $token = $this->loginAs('vendedordisenador@test.com', 'vendedordisenador@test.com');
        $claims = app(JwtService::class)->decode($token);
        $this->assertSame('vendedor', $claims['role']);

        $viejo = app(JwtService::class)->encode(array_merge($claims ?? [], ['role' => 'vendedor_disenador']));
        $this->withHeaders($this->authHeaders($viejo))->getJson('/api/auth/me')->assertStatus(200);
        // Sin permiso de Gerencia/Compras: el claim viejo no abre nada de más.
        $this->withHeaders($this->authHeaders($viejo))->getJson('/api/security-levels')->assertStatus(403);
    }

    public function test_login_y_me_devuelven_el_nombre_visible_del_rol(): void
    {
        $login = $this->postJson('/api/auth/login', ['email' => 'vendedordisenador@test.com', 'password' => 'vendedordisenador@test.com']);
        $login->assertStatus(200)->assertJsonPath('user.role', 'vendedor')->assertJsonPath('user.role_name', 'Vendedor');

        $this->withHeaders($this->authHeaders((string) $login->json('token')))->getJson('/api/auth/me')
            ->assertStatus(200)->assertJsonPath('role', 'vendedor')->assertJsonPath('role_name', 'Vendedor');

        // Renombrar el rol por CRUD (sin deploy) se refleja en el siguiente login.
        \Illuminate\Support\Facades\DB::table('atlantic_auth.roles')->where('key', 'vendedor')->update(['name' => 'Ejecutivo comercial']);
        $this->postJson('/api/auth/login', ['email' => 'vendedordisenador@test.com', 'password' => 'vendedordisenador@test.com'])
            ->assertJsonPath('user.role_name', 'Ejecutivo comercial');
    }
}
