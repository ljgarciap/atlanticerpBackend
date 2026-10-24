<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserModuleMenuVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\SpecialPermissionSeeder;
use Database\Seeders\UserModuleVisibilityGrantSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Seeder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1139 — override de visibilidad de Admin&Cont para el líder de operaciones; idempotente y no destructivo. */
class UserModuleVisibilityGrantSeederTest extends FeatureTestCase
{
    private const OPERACIONES = 'lideroperaciones@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function operaciones(): User
    {
        return User::where('email', self::OPERACIONES)->firstOrFail();
    }

    /** @param class-string<Seeder> $seeder */
    private function correr(string $seeder): void
    {
        $comando = new class extends Command {
            protected $signature = 'test:seeder-noop';
        };
        $comando->setLaravel($this->app);
        $comando->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        (new $seeder())->setCommand($comando)->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_deja_a_operaciones_con_admin_cont_completo_y_equipo(): void
    {
        $fila = UserModuleVisibility::where('user_id', $this->operaciones()->id)->where('module', 'admin_contab')->first();

        $this->assertNotNull($fila);
        $this->assertSame(RoleModuleVisibility::VIEW_FULL, $fila->can_view);
        $this->assertTrue($fila->can_view_team);
    }

    public function test_es_idempotente_no_duplica_filas(): void
    {
        $this->correr(UserModuleVisibilityGrantSeeder::class);
        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $this->assertSame(1, UserModuleVisibility::where('user_id', $this->operaciones()->id)->where('module', 'admin_contab')->count());
    }

    public function test_no_pisa_un_override_que_un_superadmin_ya_ajusto(): void
    {
        $fila = UserModuleVisibility::where('user_id', $this->operaciones()->id)->where('module', 'admin_contab')->firstOrFail();
        $fila->update(['can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false]);

        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $fila->refresh();
        $this->assertSame(RoleModuleVisibility::VIEW_NONE, $fila->can_view);
        $this->assertFalse($fila->can_view_team);
    }

    public function test_si_el_usuario_no_existe_salta_sin_error(): void
    {
        $operaciones = $this->operaciones();
        $operaciones->update(['email' => 'otra-persona@atlantic.com.pa']);
        UserModuleVisibility::where('user_id', $operaciones->id)->delete();

        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $this->assertSame(0, UserModuleVisibility::where('user_id', $operaciones->id)->count());
    }

    // SCRUM-1157 — el menú de Compras de Mariam: solo consulta. Test no tenía la configuración (se aplicaba a mano) y mostraba enlaces fantasma.
    private const COMPRAS_OCULTOS = ['agencias', 'inicio', 'nueva_orden', 'pagos', 'proveedores', 'reclamos', 'reportes'];

    /** @return list<string> */
    private function comprasOcultos(): array
    {
        $llaves = UserModuleMenuVisibility::where('user_id', $this->operaciones()->id)->where('module', 'compras')
            ->where('visible', false)->pluck('menu_item_key')->all();
        sort($llaves);

        return $llaves;
    }

    public function test_siembra_los_items_de_compras_ocultos_de_operaciones_sobre_una_configuracion_vacia(): void
    {
        UserModuleMenuVisibility::where('user_id', $this->operaciones()->id)->delete();

        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $this->assertSame(self::COMPRAS_OCULTOS, $this->comprasOcultos());
    }

    public function test_los_items_ocultos_son_idempotentes(): void
    {
        UserModuleMenuVisibility::where('user_id', $this->operaciones()->id)->delete();

        $this->correr(UserModuleVisibilityGrantSeeder::class);
        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $this->assertSame(7, UserModuleMenuVisibility::where('user_id', $this->operaciones()->id)->where('module', 'compras')->count());
    }

    public function test_no_toca_la_configuracion_de_menu_que_alguien_ya_ajusto(): void
    {
        $userId = $this->operaciones()->id;
        UserModuleMenuVisibility::where('user_id', $userId)->delete();
        // Un superadmin dejó oculto solo "reportes" (los demás visibles): el seeder no debe volver a ocultar nada más.
        UserModuleMenuVisibility::create(['user_id' => $userId, 'module' => 'compras', 'menu_item_key' => 'reportes', 'visible' => false]);

        $this->correr(UserModuleVisibilityGrantSeeder::class);

        $this->assertSame(['reportes'], $this->comprasOcultos());
    }

    public function test_el_unico_cambio_de_permisos_efectivos_de_operaciones_es_admin_cont_y_receptor_fiscal(): void
    {
        $operaciones = $this->operaciones();

        // Estado "antes": sin el override ni el grant nuevo.
        UserModuleVisibility::where('user_id', $operaciones->id)->delete();
        $operaciones->update(['extra_permissions' => array_values(array_diff($operaciones->extra_permissions ?? [], ['admin_contab.edit.receptor_fiscal']))]);
        $operaciones->refresh();
        $permAntes = $operaciones->effectivePermissionsV2();
        $modAntes  = $operaciones->modulesPayload();

        $this->correr(\Tests\Support\FixtureSpecialPermissionSeeder::class);
        $this->correr(UserModuleVisibilityGrantSeeder::class);
        $operaciones->refresh();
        $permDespues = $operaciones->effectivePermissionsV2();
        $modDespues  = $operaciones->modulesPayload();

        $nuevos = array_values(array_diff($permDespues, $permAntes));
        sort($nuevos);
        $this->assertSame(
            ['admin_contab.edit.receptor_fiscal', 'admin_contab.read', 'admin_contab.view', 'admin_contab.view_team'],
            $nuevos,
        );
        $this->assertSame([], array_values(array_diff($permAntes, $permDespues)), 'No debe perder ningún permiso.');

        foreach ($modAntes as $modulo => $capacidades) {
            if ($modulo === 'admin_contab') {
                continue;
            }
            $this->assertSame($capacidades, $modDespues[$modulo], "El módulo {$modulo} no debe cambiar.");
        }
    }
}
