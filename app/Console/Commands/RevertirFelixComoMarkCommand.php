<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1150 — reversa de «Félix replica a Mark en CRM y Ventas & Diseño». Deshace EXACTAMENTE lo que ese ticket agregó y nada más:
 * la fila de override `ventas_diseno` (can_edit/can_approve) y los permisos `ventas_diseno.select_partner_price`,
 * `ventas_diseno.override_min_margin` y `ventas_diseno.gerencia_scope`. Los permisos que Félix ya tenía antes (SCRUM-1128/1139) no se tocan.
 *
 * Por defecto es un ensayo (no escribe); `--apply` ejecuta. Idempotente. IMPORTANTE: el `SpecialPermissionSeeder` y el
 * `UserModuleVisibilityGrantSeeder` recrean estos grants en cada deploy, así que la reversa completa es este comando MÁS revertir
 * el commit (ver docs/runbooks/revertir-scrum1150-felix-como-mark.md).
 */
final class RevertirFelixComoMarkCommand extends Command
{
    /** @var list<string> */
    private const PERMISOS = ['ventas_diseno.select_partner_price', 'ventas_diseno.override_min_margin', 'ventas_diseno.gerencia_scope'];

    /** @var string */
    protected $signature = 'users:revertir-felix-como-mark
        {--email=contabilidad@test.com : Correo de la persona}
        {--tenant=atlantic : Slug del tenant}
        {--apply : Ejecuta la reversa (sin esto es solo un ensayo)}';

    /** @var string */
    protected $description = 'SCRUM-1150 — revierte el override de Ventas & Diseño y los permisos de Mark concedidos a Félix (ensayo por defecto)';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error('Tenant no existe.');

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $user = User::where('email', (string) $this->option('email'))->first();
        if ($user === null) {
            $this->error('Usuario no encontrado.');

            return self::FAILURE;
        }

        $extra   = $user->extra_permissions ?? [];
        $quitar  = array_values(array_intersect($extra, self::PERMISOS));
        $fila    = UserModuleVisibility::where('user_id', $user->id)->where('module', 'ventas_diseno')->first();
        $aplicar = (bool) $this->option('apply');

        $this->line(($aplicar ? 'APLICA' : 'ENSAYO').' — '.$user->email);
        $this->line('  permisos a quitar: '.($quitar === [] ? '(ninguno)' : implode(', ', $quitar)));
        $this->line('  fila de override ventas_diseno: '.($fila === null ? '(no existe)' : "existe (can_edit={$this->v($fila->can_edit)}, can_approve={$this->v($fila->can_approve)})"));

        if (! $aplicar) {
            $this->info('Nada escrito. Repetir con --apply para ejecutar.');

            return self::SUCCESS;
        }

        if ($quitar !== []) {
            $user->update(['extra_permissions' => array_values(array_diff($extra, self::PERMISOS))]);
        }
        $fila?->delete();

        $this->info('Reversa aplicada. La persona debe volver a iniciar sesión (los permisos viajan en el token).');

        return self::SUCCESS;
    }

    private function v(?bool $valor): string
    {
        return $valor === null ? 'null' : ($valor ? 'true' : 'false');
    }
}
