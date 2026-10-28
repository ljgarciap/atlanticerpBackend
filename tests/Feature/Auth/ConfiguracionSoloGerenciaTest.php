<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1012 — la administración de usuarios, niveles de seguridad y departamentos (Configuración
 * global) es exclusiva del rol base Gerencia (`management`) + superadmin, ADEMÁS del permiso. Un
 * grant individual (extra_permissions / manage_users) sin ese rol ya no alcanza.
 */
class ConfiguracionSoloGerenciaTest extends FeatureTestCase
{
    /** @return array<string, array{0: string, 1: string}> [método, ruta] de cada endpoint restringido. */
    public static function restrictedEndpoints(): array
    {
        return [
            'users index'          => ['GET',    '/api/users'],
            'users show'           => ['GET',    '/api/users/1'],
            'users store'          => ['POST',   '/api/users'],
            'users update'         => ['PUT',    '/api/users/1'],
            'users status'         => ['PATCH',  '/api/users/1/status'],
            'users flags'          => ['PATCH',  '/api/admin/users/1/flags'],
            'departments index'    => ['GET',    '/api/departments'],
            'departments store'    => ['POST',   '/api/departments'],
            'departments update'   => ['PUT',    '/api/departments/1'],
            'departments status'   => ['PATCH',  '/api/departments/1/status'],
            'levels index'         => ['GET',    '/api/security-levels'],
            'levels store'         => ['POST',   '/api/security-levels'],
            'levels update'        => ['PUT',    '/api/security-levels/1'],
            'levels status'        => ['PATCH',  '/api/security-levels/1/status'],
            'levels perms get'     => ['GET',    '/api/security-levels/1/permissions'],
            'levels perms put'     => ['PUT',    '/api/security-levels/1/permissions'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function nonManagementRoles(): array
    {
        return [
            'vendedor' => ['vendedor'],
            'lider_compras'      => ['lider_compras'],
            'lider_bodega'       => ['lider_bodega'],
            'lider_servicios'    => ['lider_servicios'],
            'tecnico'  => ['tecnico'],
        ];
    }

    /** Loguea a vendedor@  con el role_key de negocio indicado. */
    private function tokenWithRole(string $roleKey, array $extraPermissions = []): string
    {
        $slug   = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $roleId = DB::table("{$slug}_auth.roles")->where('key', $roleKey)->value('id');
        $this->assertNotNull($roleId, "rol {$roleKey} no existe en el seed");

        DB::table("{$slug}_auth.users")->where('email', 'vendedor@atlantic.test')->update([
            'role_id'           => $roleId,
            'extra_permissions' => json_encode($extraPermissions),
        ]);

        return $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
    }

    /** @param array<string, mixed> $body */
    private function hit(string $token, string $method, string $uri, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->json($method, $uri, $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restrictedEndpoints')]
    public function test_management_y_superadmin_no_son_rechazados_por_el_gate(string $method, string $uri): void
    {
        foreach ([
            $this->loginAs('management@atlantic.test'),
            $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'),
        ] as $token) {
            $status = $this->hit($token, $method, $uri)->getStatusCode();
            $this->assertNotContains($status, [401, 403], "{$method} {$uri} rechazó a un actor autorizado ({$status})");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restrictedEndpoints')]
    public function test_roles_de_negocio_no_gerencia_reciben_403_aun_con_los_permisos_de_seguridad(string $method, string $uri): void
    {
        foreach (self::nonManagementRoles() as [$roleKey]) {
            // Con el grant individual completo: caso de pérdida de acceso pedido por Luis.
            $token = $this->tokenWithRole($roleKey, ['security.users', 'security.levels']);
            $this->hit($token, $method, $uri)->assertStatus(403);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restrictedEndpoints')]
    public function test_usuario_no_management_con_security_users_por_extra_permissions_pierde_el_acceso(string $method, string $uri): void
    {
        $token = $this->tokenWithRole('lider_admin_contab', ['security.users', 'security.levels']);
        $this->hit($token, $method, $uri)->assertStatus(403);
    }

    public function test_listado_de_usuarios_responde_200_a_management_y_superadmin(): void
    {
        foreach ([
            $this->loginAs('management@atlantic.test'),
            $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'),
        ] as $token) {
            foreach (['/api/users', '/api/departments', '/api/security-levels'] as $uri) {
                $this->hit($token, 'GET', $uri)->assertStatus(200);
            }
        }
    }

    public function test_endpoints_de_otros_modulos_y_ajustes_de_modulo_siguen_intactos_para_su_rol(): void
    {
        // Ajustes DENTRO de módulos: no los toca esta corrección (decisión 1 de Luis).
        $token = $this->tokenWithRole('lider_compras', ['compras.read', 'compras.write']);
        $status = $this->hit($token, 'GET', '/api/compras/settings')->getStatusCode();
        $this->assertNotContains($status, [401, 403]);

        // Pickers/catálogos abiertos a cualquier autenticado: roles y departamentos del registro público.
        $this->hit($token, 'GET', '/api/roles')->assertStatus(200);
        $this->getJson('/api/auth/departments')->assertStatus(200);
    }

    public function test_global_settings_siguen_siendo_solo_superadmin(): void
    {
        $this->hit($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'), 'GET', '/api/admin/global-settings')
            ->assertStatus(200);
        $this->hit($this->tokenWithRole('vendedor'), 'GET', '/api/admin/global-settings')->assertStatus(403);
    }

    public function test_reset_de_contrasenas_sigue_exclusivo_de_superadmin(): void
    {
        $this->hit($this->loginAs('management@atlantic.test'), 'POST', '/api/users/reset-passwords')->assertStatus(403);
    }
}
