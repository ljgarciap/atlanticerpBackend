<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 1 (docs/specs/fase1-limpieza-nucleo.md, bloque D) — las rutas dejaron de decidir por NOMBRE de rol (`role:`) y pasaron a
 * permisos de rol completo (`platform.management`, `ventas_diseno.orders.access`) y a `superadmin.all`. Este test fija la
 * EQUIVALENCIA: para cada rol sembrado y cada ruta cambiada, el acceso es el mismo que daba el `role:` anterior.
 */
class RoleGatesToPermissionsTest extends FeatureTestCase
{
    /** @var array<string, string> role_key => email sembrado */
    private const USERS = [
        'superadmin'               => 'superadmin@test.com',
        'management'               => 'gerencia@test.com',
        'lider_admin_contab'       => 'contabilidad@test.com',
        'asistente_administrativa' => 'asistenteadministrativa@test.com',
        'lider_compras'            => 'lidercompras@test.com',
        'lider_operaciones'        => 'lideroperaciones@test.com',
        'lider_bodega'             => 'liderbodega@test.com',
        'asistente_bodega'         => 'asistentebodega@test.com',
        'auxiliar_bodega'  => 'auxiliarbodega@test.com',
        'transporte'               => 'transporte@test.com',
        'lider_servicios'          => 'liderservicios@test.com',
        'tecnico'        => 'tecnico@test.com',
        'tecnico_garantias'      => 'tecnicogarantias@test.com',
        'vendedor'       => 'vendedor@test.com',
    ];

    /**
     * ruta => roles que pasan (los demás reciben 403). Conjuntos idénticos a los `role:` que había antes.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function rutas(): array
    {
        $gerencia = ['superadmin', 'management'];

        return [
            'usuarios: listado'              => ['/api/users', $gerencia],
            'departamentos'                  => ['/api/departments', $gerencia],
            'niveles de seguridad'           => ['/api/security-levels', $gerencia],
            'catálogo de permisos'           => ['/api/permission-catalog', $gerencia],
            'dashboard CRM (Gerencia)'       => ['/api/ventas-diseno/dashboard/summary', $gerencia],
            'pedidos (Gerencia y Compras)'   => ['/api/ventas-diseno/orders', ['superadmin', 'management', 'lider_compras']],
            'reglas de aprobación (solo superadmin)' => ['/api/gerencia/reglas-aprobacion', ['superadmin']],
        ];
    }

    /**
     * @param list<string> $permitidos
     *
     * @dataProvider rutas
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rutas')]
    public function test_acceso_por_rol_es_identico_al_del_role_anterior(string $ruta, array $permitidos): void
    {
        // El seeder de fixtures le concede `ventas_diseno.gerencia_scope` (alcance de Gerencia por persona, SCRUM-1150) a un usuario; aquí se mide solo el
        // acceso por ROL, así que se quita. El alcance por persona tiene su propio test más abajo.
        $this->quitarGerenciaScope();

        foreach (self::USERS as $rol => $email) {
            $status = $this->withHeaders($this->authHeaders($this->loginAs($email, $email, $rol)))->getJson($ruta)->status();

            if (in_array($rol, $permitidos, true)) {
                $this->assertNotSame(403, $status, "{$rol} debe poder entrar a {$ruta}");
            } else {
                $this->assertSame(403, $status, "{$rol} NO debe poder entrar a {$ruta} (recibió {$status})");
            }
        }
    }

    private function quitarGerenciaScope(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        foreach (\App\Models\User::query()->get() as $u) {
            $extra = $u->extra_permissions ?? [];
            if (in_array('ventas_diseno.gerencia_scope', $extra, true)) {
                $u->update(['extra_permissions' => array_values(array_diff($extra, ['ventas_diseno.gerencia_scope']))]);
            }
        }
    }

    public function test_el_alcance_de_gerencia_por_persona_sigue_abriendo_dashboard_y_pedidos(): void
    {
        // SCRUM-1150: `ventas_diseno.gerencia_scope` trata a la persona como Gerencia SOLO en CRM y Ventas & Diseño.
        $this->quitarGerenciaScope();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $u = \App\Models\User::where('email', 'contabilidad@test.com')->firstOrFail();
        $headers = fn () => $this->authHeaders($this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab'));

        $this->withHeaders($headers())->getJson('/api/ventas-diseno/dashboard/summary')->assertStatus(403);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $u->update(['extra_permissions' => array_values(array_unique([...($u->extra_permissions ?? []), 'ventas_diseno.gerencia_scope']))]);

        foreach (['/api/ventas-diseno/dashboard/summary', '/api/ventas-diseno/orders'] as $ruta) {
            $this->assertNotSame(403, $this->withHeaders($headers())->getJson($ruta)->status(), "con gerencia_scope debe entrar a {$ruta}");
        }
        // …pero NO administra usuarios (esas rutas no pasan por ApplyGerenciaScope).
        $this->withHeaders($headers())->getJson('/api/users')->assertStatus(403);
    }

    public function test_un_permiso_de_rol_completo_no_se_puede_conceder_a_una_persona_suelta(): void
    {
        // Garantía de SCRUM-1012: aunque alguien tenga `security.users` (p. ej. por el flag manage_users), no administra usuarios.
        $contabilidad = $this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab');
        $this->withHeaders($this->authHeaders($contabilidad))->getJson('/api/users')->assertStatus(403);

        // …y los permisos nuevos no están en el catálogo, así que `extra_permissions` los rechaza (422).
        $gerencia = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');
        $id = (int) $this->withHeaders($this->authHeaders($gerencia))->getJson('/api/users?search=vendedor@test.com')->json('data.0.id');
        $this->assertGreaterThan(0, $id);

        foreach (['platform.management', 'ventas_diseno.orders.access'] as $permiso) {
            $this->withHeaders($this->authHeaders($gerencia))
                ->putJson("/api/users/{$id}", ['extra_permissions' => [$permiso]])
                ->assertStatus(422);
        }
    }
}
