<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Auth\Mail\WelcomeMail;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

class UserControllerTest extends FeatureTestCase
{
    private function defaultLevelId(): int
    {
        return (int) DB::table('atlantic_auth.security_levels')->where('level', 5)->value('id');
    }

    private function levelId(int $level): int
    {
        return (int) DB::table('atlantic_auth.security_levels')->where('level', $level)->value('id');
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_listar_usuarios_retorna_200_con_paginacion(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonStructure(['meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_listar_usuarios_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_busqueda_por_nombre_filtra_resultados(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users?search=management');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    public function test_filtro_por_role_id_retorna_solo_usuarios_de_ese_rol(): void
    {
        $token   = $this->loginAs();
        $roleId  = $this->roleId('vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Filtro',
                'last_name'         => 'PorRol',
                'email'             => 'filtro.porrol@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $roleId,
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/users?role_id={$roleId}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        foreach ($data as $user) {
            $this->assertSame($roleId, $user['role_id']);
        }
    }

    public function test_filtro_por_role_id_incluye_usuarios_con_ese_rol_como_adicional(): void
    {
        $token         = $this->loginAs();
        $baseRoleId    = $this->roleId('vendedor_disenador');
        $additionalId  = $this->roleId('lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'          => 'Filtro',
                'last_name'           => 'RolAdicional',
                'email'               => 'filtro.roladicional@atlantic.test',
                'security_level_id'   => $this->defaultLevelId(),
                'role_id'             => $baseRoleId,
                'additional_role_ids' => [$additionalId],
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/users?role_id={$additionalId}");

        $response->assertStatus(200);
        $emails = collect($response->json('data'))->pluck('email');
        $this->assertContains('filtro.roladicional@atlantic.test', $emails);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_crear_usuario_retorna_201(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Juan',
                'last_name'         => 'Pérez',
                'email'             => 'juan.perez@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'first_name', 'last_name', 'email', 'roles', 'is_active']);
        $response->assertJsonPath('email', 'juan.perez@atlantic.test');
        $response->assertJsonPath('is_active', true);
        $this->assertContains('vendedor_disenador', $response->json('roles'));
    }

    public function test_crear_usuario_multirol_retorna_201(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Ana',
                'last_name'         => 'Gómez',
                'email'             => 'ana.gomez@atlantic.test',
                'role_id'             => $this->roleId('management'),
                'additional_role_ids' => [$this->roleId('vendedor_disenador')],
                'security_level_id' => $this->defaultLevelId(),
            ]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('roles'));
    }

    public function test_crear_usuario_email_duplicado_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name' => 'Duplicado',
                'last_name'  => 'Test',
                'email'      => 'management@atlantic.test',
                'role_id'      => $this->roleId('vendedor_disenador'),
            ])
            ->assertStatus(422);
    }

    public function test_crear_usuario_sin_roles_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name' => 'Sin',
                'last_name'  => 'Roles',
                'email'      => 'sin.roles@atlantic.test',
                'role_id'    => null,
            ])
            ->assertStatus(422);
    }

    public function test_crear_usuario_sin_role_id_ni_roles_retorna_422(): void
    {
        // Senior Review 2026-07-13, blocker 2 — `roles` pasó a `sometimes` en Fase D y `role_id`
        // ya era nullable, sin ninguna regla que exigiera al menos uno de los dos.
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Sin',
                'last_name'         => 'NingunRol',
                'email'             => 'sin.ningunrol@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_crear_usuario_sin_el_campo_roles_pero_con_role_id_retorna_201(): void
    {
        // ADR-006, Fase D — `roles` (legado) dejó de ser obligatorio: `role_id` es el modelo
        // real desde la Fase C, el claim JWT `role` se resuelve desde ahí cuando está presente.
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Sin',
                'last_name'         => 'CampoRoles',
                'email'             => 'sin.camporoles@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('vendedor_disenador'),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role_id', $this->roleId('vendedor_disenador'));
    }

    public function test_crear_usuario_rol_invalido_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name' => 'Test',
                'last_name'  => 'Rol',
                'email'      => 'test.rol@atlantic.test',
                'role_id'    => 999999,
            ])
            ->assertStatus(422);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_actualizar_usuario_retorna_200(): void
    {
        $token = $this->loginAs();

        // Crear usuario primero
        $createResponse = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Original',
                'last_name'         => 'Apellido',
                'email'             => 'original@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $createResponse->assertStatus(201);
        $userId = $createResponse->json('id');

        // Actualizar
        $updateResponse = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$userId}", [
                'first_name' => 'Actualizado',
                'last_name'  => 'Nombre',
            ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('first_name', 'Actualizado');
    }

    // ── toggleStatus ──────────────────────────────────────────────────────────

    public function test_desactivar_usuario_retorna_200(): void
    {
        $token = $this->loginAs();

        $createResponse = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Para',
                'last_name'         => 'Desactivar',
                'email'             => 'para.desactivar@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $userId = $createResponse->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/users/{$userId}/status", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);
    }

    public function test_reactivar_usuario_retorna_200(): void
    {
        $token = $this->loginAs();

        $createResponse = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Para',
                'last_name'         => 'Reactivar',
                'email'             => 'para.reactivar@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $userId = $createResponse->json('id');

        // Desactivar
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/users/{$userId}/status", ['is_active' => false])
            ->assertStatus(200);

        // Reactivar
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/users/{$userId}/status", ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('is_active', true);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_obtener_usuario_por_id_retorna_200(): void
    {
        $token = $this->loginAs();

        $createResponse = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Ver',
                'last_name'         => 'Detalle',
                'email'             => 'ver.detalle@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $userId = $createResponse->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/users/{$userId}")
            ->assertStatus(200)
            ->assertJsonPath('id', $userId)
            ->assertJsonStructure(['id', 'first_name', 'last_name', 'email', 'roles', 'department', 'security_level', 'extra_permissions']);
    }

    public function test_usuario_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users/99999')
            ->assertStatus(404);
    }

    // ── RBAC ──────────────────────────────────────────────────────────────────

    public function test_listar_usuarios_con_rol_designer_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    public function test_ver_usuario_por_id_con_rol_designer_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users/1')
            ->assertStatus(403);
    }

    public function test_crear_usuario_con_rol_designer_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name' => 'No',
                'last_name'  => 'Access',
                'email'      => 'no.access@atlantic.test',
                'role_id'      => $this->roleId('vendedor_disenador'),
            ])
            ->assertStatus(403);
    }

    public function test_actualizar_usuario_con_rol_designer_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/users/1', ['first_name' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_cambiar_estado_con_rol_electrician_retorna_403(): void
    {
        $token = $this->loginAs('electrician@atlantic.test', 'Password123!', 'electrician');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/users/1/status', ['is_active' => false])
            ->assertStatus(403);
    }

    // ── guardia jerárquica sobre el usuario objetivo (no solo security_level_id) ─

    public function test_actor_nivel_inferior_no_puede_editar_otros_campos_de_usuario_nivel_superior(): void
    {
        $adminToken = $this->loginAs();
        $create     = $this->withHeaders($this->authHeaders($adminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Alto',
                'last_name'         => 'Nivel',
                'email'             => 'alto.nivel@atlantic.test',
                'role_id'             => $this->roleId('management'),
                'security_level_id' => $this->levelId(9),
            ]);
        $create->assertStatus(201);
        $targetId = $create->json('id');

        // 'multi' tiene rol management pero nivel 8 (no superadmin.all)
        $actorToken = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        // No toca security_level_id — solo el nombre. Antes del fix esto pasaba (200).
        $this->withHeaders($this->authHeaders($actorToken))
            ->putJson("/api/users/{$targetId}", ['first_name' => 'Hackeado'])
            ->assertStatus(403);
    }

    public function test_actor_nivel_inferior_no_puede_cambiar_estado_de_usuario_nivel_superior(): void
    {
        $adminToken = $this->loginAs();
        $create     = $this->withHeaders($this->authHeaders($adminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Alto2',
                'last_name'         => 'Nivel2',
                'email'             => 'alto.nivel2@atlantic.test',
                'role_id'             => $this->roleId('management'),
                'security_level_id' => $this->levelId(9),
            ]);
        $create->assertStatus(201);
        $targetId = $create->json('id');

        $actorToken = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        // toggleStatus no tenía NINGUNA guardia jerárquica antes del fix.
        $this->withHeaders($this->authHeaders($actorToken))
            ->patchJson("/api/users/{$targetId}/status", ['is_active' => false])
            ->assertStatus(403);
    }

    // ── security.users por permiso, no por rol hardcodeado ──────────────────────

    public function test_actor_con_permiso_security_users_pero_rol_no_gerencial_no_puede_listar_usuarios(): void
    {
        // Senior Review 2026-08-02 (Fase 4 — Servicios, hallazgo CRÍTICO): este test verificaba
        // originalmente que supervisor@ (Nivel 5 "Team Lead") podía listar usuarios porque el
        // Nivel 5 traía `security.users` baked in en su catálogo legado — es decir, exactamente el
        // bug real que se cerró en 2026_08_02_100005 (cualquier usuario, sin importar su rol real,
        // ganaba administración de usuarios solo por caer en un nivel numérico con ese permiso).
        // Nadie lo había explotado hasta que este mismo batch reasignó roles de negocio reales
        // (vendedor_disenador/tecnico_servicios) a Niveles 5/6, que sí tienen `role_id` y por lo
        // tanto un rol real no-gerencial. El mecanismo correcto para esto es el flag transversal
        // `manage_users` (ver EffectivePermissionsV2Test::test_manage_users_otorga_el_permiso_real_de_administrar_usuarios)
        // — se lo seteamos acá explícitamente en vez de depender del catálogo legado del nivel,
        // preservando la intención real del test (el gate es por PERMISO, no por rol/nivel).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        User::where('email', 'supervisor@atlantic.test')->update(['manage_users' => true]);

        $token = $this->loginAs('supervisor@atlantic.test', 'Password123!', 'supervisor');

        // SCRUM-1012: el permiso (flag manage_users) ya no alcanza solo — Usuarios exige ADEMAS
        // el rol base management/superadmin.
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    // ── extra_permissions ────────────────────────────────────────────────────

    public function test_respuesta_usuario_incluye_extra_permissions(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Perms',
                'last_name'         => 'Test',
                'email'             => 'perms.test@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['extra_permissions'])
            ->assertJsonPath('extra_permissions', []);
    }

    public function test_superadmin_puede_asignar_extra_permissions(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Extra',
                'last_name'         => 'Perms',
                'email'             => 'extra.perms@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'extra_permissions' => ['crm.read', 'reports.export'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('extra_permissions', ['crm.read', 'reports.export']);
    }

    public function test_management_no_puede_asignar_extra_permissions(): void
    {
        // Gerencia pura (rol management, nivel 9): no tiene superadmin.all
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Sin',
                'last_name'         => 'ExtraPerms',
                'email'             => 'sin.extraperms@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'extra_permissions' => ['superadmin.all'],
            ]);

        // Request succeeds but extra_permissions are silently dropped
        $response->assertStatus(201)
            ->assertJsonPath('extra_permissions', []);
    }

    public function test_extra_permissions_con_valor_fuera_de_catalogo_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Bad',
                'last_name'         => 'Perm',
                'email'             => 'bad.perm@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'extra_permissions' => ['invalid.permission'],
            ])
            ->assertStatus(422);
    }

    public function test_jwt_contiene_security_level(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'management@atlantic.test',
            'password' => 'Password123!',
        ]);
        $response->assertStatus(200);

        $token = (string) $response->json('token');

        // Decode JWT payload (middle segment)
        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertArrayHasKey('security_level', $payload);
        $this->assertIsInt($payload['security_level']);
        $this->assertSame(10, $payload['security_level']); // management seed user is level 10
    }

    // ── role_id / additional_role_ids — ADR-006, Decisión 1 revisada (2026-07-09) ──────────────

    private function roleId(string $key): int
    {
        return (int) DB::table('atlantic_auth.roles')->where('key', $key)->value('id');
    }

    public function test_crear_usuario_con_role_id_lo_persiste(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Rol',
                'last_name'         => 'Base',
                'email'             => 'rol.base@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('vendedor_disenador'),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role_id', $this->roleId('vendedor_disenador'))
            ->assertJsonPath('additional_role_ids', []);
    }

    public function test_superadmin_puede_asignar_additional_role_ids(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'          => 'Multi',
                'last_name'           => 'Rol',
                'email'               => 'multi.rol@atlantic.test',
                'security_level_id'   => $this->defaultLevelId(),
                'role_id'             => $this->roleId('vendedor_disenador'),
                'additional_role_ids' => [$this->roleId('lider_servicios')],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role_id', $this->roleId('vendedor_disenador'))
            ->assertJsonPath('additional_role_ids', [$this->roleId('lider_servicios')]);
    }

    public function test_management_no_superadmin_no_puede_asignar_additional_role_ids(): void
    {
        // Gerencia pura (rol management, nivel 9): no tiene superadmin.all
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'          => 'Sin',
                'last_name'           => 'MultiRol',
                'email'               => 'sin.multirol@atlantic.test',
                'security_level_id'   => $this->defaultLevelId(),
                'role_id'             => $this->roleId('vendedor_disenador'),
                'additional_role_ids' => [$this->roleId('lider_servicios')],
            ]);

        // Request succeeds (role_id sigue siendo asignable), pero additional_role_ids se ignora
        $response->assertStatus(201)
            ->assertJsonPath('role_id', $this->roleId('vendedor_disenador'))
            ->assertJsonPath('additional_role_ids', []);
    }

    public function test_actor_no_superadmin_no_puede_asignar_role_id_de_rol_superadmin_al_crear_retorna_403(): void
    {
        // Senior Review 2026-07-13, blocker 1 (crítico) — role_id no tenía el mismo gate que
        // additional_role_ids/extra_permissions: cualquier actor con security.users podía
        // convertir a un usuario en superadmin eligiéndolo como rol base.
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Escalado',
                'last_name'         => 'Privilegio',
                'email'             => 'escalado.privilegio@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('superadmin'),
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('atlantic_auth.users', ['email' => 'escalado.privilegio@atlantic.test']);
    }

    public function test_actualizar_role_id_reemplaza_nunca_agrega(): void
    {
        $token = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Reemplazo',
                'last_name'         => 'Rol',
                'email'             => 'reemplazo.rol@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('vendedor_disenador'),
            ]);
        $id = $created->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$id}", ['role_id' => $this->roleId('lider_bodega')]);

        $response->assertStatus(200)
            ->assertJsonPath('role_id', $this->roleId('lider_bodega'));
    }

    public function test_management_no_superadmin_no_puede_agregar_additional_role_ids_al_editar(): void
    {
        $superadminToken = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($superadminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Editar',
                'last_name'         => 'MultiRol',
                'email'             => 'editar.multirol@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('vendedor_disenador'),
            ]);
        $id = $created->json('id');

        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$id}", [
                'additional_role_ids' => [$this->roleId('lider_servicios')],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('additional_role_ids', []);
    }

    public function test_actor_no_superadmin_no_puede_asignar_role_id_de_rol_superadmin_al_editar_retorna_403(): void
    {
        // Senior Review 2026-07-13, blocker 1 — mismo gate que al crear, ahora sobre update().
        $superadminToken = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($superadminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Editar',
                'last_name'         => 'Escalado',
                'email'             => 'editar.escalado@atlantic.test',
                'security_level_id' => $this->defaultLevelId(),
                'role_id'           => $this->roleId('vendedor_disenador'),
            ]);
        $id = $created->json('id');

        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$id}", ['role_id' => $this->roleId('superadmin')]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Solo un superadmin puede asignar el rol Superadmin como rol base.');

        $this->assertSame(
            $this->roleId('vendedor_disenador'),
            (int) DB::table('atlantic_auth.users')->where('id', $id)->value('role_id'),
        );
    }

    // ── PATCH /api/admin/users/{id}/flags — ADR-006, Decisión 8/10 ─────────────────────────────

    public function test_actualizar_flags_persiste_ambos_valores(): void
    {
        $superadminToken = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($superadminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Flags',
                'last_name'         => 'Test',
                'email'             => 'flags.test@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $id = $created->json('id');

        $response = $this->withHeaders($this->authHeaders($superadminToken))
            ->patchJson("/api/admin/users/{$id}/flags", [
                'approve_large_amounts' => true,
                'manage_users'          => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('approve_large_amounts', true);
        $response->assertJsonPath('manage_users', true);
    }

    public function test_actualizar_flags_parcial_no_toca_el_otro_flag(): void
    {
        $superadminToken = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($superadminToken))
            ->postJson('/api/users', [
                'first_name'        => 'Flags',
                'last_name'         => 'Parcial',
                'email'             => 'flags.parcial@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $id = $created->json('id');

        $this->withHeaders($this->authHeaders($superadminToken))
            ->patchJson("/api/admin/users/{$id}/flags", ['manage_users' => true])
            ->assertStatus(200)
            ->assertJsonPath('manage_users', true)
            ->assertJsonPath('approve_large_amounts', false);
    }

    public function test_actualizar_flags_sin_permiso_security_users_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $target = User::where('email', 'designer@atlantic.test')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/admin/users/{$target->id}/flags", ['manage_users' => true])
            ->assertStatus(403);
    }

    public function test_actualizar_flags_sin_autenticar_retorna_401(): void
    {
        $this->patchJson('/api/admin/users/1/flags', ['manage_users' => true])
            ->assertStatus(401);
    }

    // ── SCRUM-822 — cargo/fecha de ingreso/jefe directo ─────────────────────────

    public function test_crear_usuario_con_cargo_fecha_ingreso_y_jefe_directo(): void
    {
        $token = $this->loginAs();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $manager = User::where('email', 'management@atlantic.test')->firstOrFail();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Con',
                'last_name'         => 'Jefe',
                'email'             => 'con.jefe@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'job_title'         => 'Diseñador Senior',
                'hire_date'         => '2026-01-15',
                'manager_id'        => $manager->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('job_title', 'Diseñador Senior')
            ->assertJsonPath('hire_date', '2026-01-15')
            ->assertJsonPath('manager.id', $manager->id)
            ->assertJsonPath('manager.name', $manager->first_name . ' ' . $manager->last_name);
    }

    public function test_crear_usuario_sin_jefe_directo_retorna_manager_null(): void
    {
        // RN2 — null = "Ninguno — Socio Director", el nivel más alto sin jefe asignado.
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Sin',
                'last_name'         => 'Jefe',
                'email'             => 'sin.jefe@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('manager', null)
            ->assertJsonPath('job_title', null)
            ->assertJsonPath('hire_date', null);
    }

    public function test_actualizar_cargo_fecha_ingreso_y_jefe_directo(): void
    {
        $token   = $this->loginAs();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $manager = User::where('email', 'management@atlantic.test')->firstOrFail();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Para',
                'last_name'         => 'Actualizar',
                'email'             => 'para.actualizar.822@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $id = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$id}", [
                'job_title'  => 'Coordinador',
                'hire_date'  => '2026-03-01',
                'manager_id' => $manager->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('job_title', 'Coordinador')
            ->assertJsonPath('hire_date', '2026-03-01')
            ->assertJsonPath('manager.id', $manager->id);
    }

    public function test_actualizar_usuario_no_puede_ser_su_propio_jefe_directo(): void
    {
        $token = $this->loginAs();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Auto',
                'last_name'         => 'Referencia',
                'email'             => 'auto.referencia@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ]);
        $id = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$id}", ['manager_id' => $id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_crear_usuario_con_jefe_directo_inexistente_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Jefe',
                'last_name'         => 'Inexistente',
                'email'             => 'jefe.inexistente@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'manager_id'        => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_crear_usuario_inactivo_no_envia_correo_de_bienvenida(): void
    {
        // RN3 — estado elegible al crear; is_active=false no debe disparar WelcomeMail.
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Inactivo',
                'last_name'         => 'AlCrear',
                'email'             => 'inactivo.alcrear@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
                'is_active'         => false,
            ]);

        $response->assertStatus(201)->assertJsonPath('is_active', false);
        Mail::assertNotSent(WelcomeMail::class);
    }

    public function test_crear_usuario_activo_por_defecto_envia_correo_de_bienvenida(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name'        => 'Activo',
                'last_name'         => 'PorDefecto',
                'email'             => 'activo.pordefecto@atlantic.test',
                'role_id'             => $this->roleId('vendedor_disenador'),
                'security_level_id' => $this->defaultLevelId(),
            ])
            ->assertStatus(201);

        Mail::assertSent(WelcomeMail::class);
    }
}
