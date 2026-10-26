<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Modules\Auth\Services\PasswordResetPolicy;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reset administrativo de contraseñas, individual (`--email=`) o masivo (`--all-active`): la contraseña pasa a ser
 * el correo de cada usuario y se le obliga a cambiarla al ingresar (`must_change_password`). Runbook:
 * docs/runbooks/reset-password.md.
 *
 * - SIMULACIÓN por defecto: lista a quiénes alcanzaría y no cambia nada.
 * - `--apply` exige `--operador` (queda en la auditoría) y escribir exactamente `RESETEAR <ENTORNO> <N>`, con
 *   ENTORNO = APP_ENV en mayúsculas y `PRODUCCION` en producción. Sin TTY (ssh) la confirmación se entrega por stdin con
 *   SHELL_INTERACTIVE=1.
 * - `--yes` (omite la confirmación) SOLO en local y testing.
 * - Nunca imprime contraseñas ni hashes: solo cantidades y correos.
 */
final class UsersResetPasswordCommand extends Command
{
    private const ENTORNOS_SIN_CONFIRMACION = ['local', 'testing'];

    /** @var string */
    protected $signature = 'users:reset-password
        {--email= : Correo del usuario a resetear (reset individual)}
        {--all-active : Resetea a los usuarios activos, SIN los superadmin salvo --include-superadmins (reset masivo)}
        {--include-superadmins : Incluye a los superadmin en el reset masivo (por defecto quedan fuera)}
        {--except= : Correos a excluir, separados por coma (solo con --all-active)}
        {--tenant=atlantic : Slug del tenant}
        {--apply : Aplica el reset. Sin esta opción es una simulación que no cambia nada}
        {--yes : Omite la confirmación escrita. SOLO en local y testing; en cualquier otro entorno se rechaza}
        {--operador= : Quién ejecuta (queda en la auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'Resetea contraseñas (contraseña = correo, cambio obligatorio al ingresar): individual con --email o masivo con --all-active. Simulación por defecto.';

    public function __construct(
        private readonly AdminPasswordResetService $service,
        private readonly PasswordResetPolicy $policy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email     = trim((string) $this->option('email'));
        $allActive = (bool) $this->option('all-active');
        $apply     = (bool) $this->option('apply');

        if (($email === '') === (! $allActive)) {
            // O ninguno, o los dos.
            $this->error('Indica exactamente una de estas opciones: --email=<correo> (individual) o --all-active (masivo).');

            return self::FAILURE;
        }

        if (! $allActive && trim((string) $this->option('except')) !== '') {
            $this->error('--except solo se usa con --all-active.');

            return self::FAILURE;
        }

        if (! $allActive && (bool) $this->option('include-superadmins')) {
            $this->error('--include-superadmins solo se usa con --all-active.');

            return self::FAILURE;
        }

        if ($apply && (bool) $this->option('yes') && ! $this->entornoSinConfirmacion()) {
            $this->error('--yes no se acepta en el entorno "' . $this->entorno() . '": solo en ' . implode(' y ', self::ENTORNOS_SIN_CONFIRMACION) . '. La confirmación debe ser escrita.');

            return self::FAILURE;
        }

        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en la auditoría (quién ejecutó el reset).');

            return self::FAILURE;
        }

        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No existe el tenant [{$slug}].");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        try {
            // Interruptor `password_reset_enabled` (apagado por defecto en producción): la simulación siempre funciona; aplicar no.
            if ($apply && ! $this->policy->enabled()) {
                $this->error('El reset administrativo está DESHABILITADO en este entorno (ajuste global password_reset_enabled). '
                    . 'Un superadmin puede habilitarlo en Configuración global durante la ventana acordada. La simulación sigue disponible.');

                return self::FAILURE;
            }

            return $this->ejecutar($slug, $email, $allActive, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, string $email, bool $allActive, bool $apply): int
    {
        $targets = $allActive ? $this->resolverMasivo() : $this->resolverIndividual($email);

        if ($targets === null) {
            return self::FAILURE;
        }

        $this->info('users:reset-password — ' . ($apply ? 'APLICAR' : 'SIMULACIÓN') . ' (entorno ' . $this->entorno() . ', tenant ' . $slug . ')');
        $this->line('Usuarios alcanzados: ' . $targets->count());
        foreach ($targets as $user) {
            $this->line('  - ' . $user->email);
        }

        if (! $apply) {
            $this->comment('Simulación: NO se cambió nada. Para aplicar: --apply --operador="Nombre" (pide confirmación escrita).');

            return self::SUCCESS;
        }

        if ($targets->isEmpty()) {
            $this->warn('No hay usuarios que resetear.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('yes') && ! $this->confirmar($targets->count())) {
            $this->error('Confirmación incorrecta: no se aplicó ningún cambio.');

            return self::FAILURE;
        }

        $operador = trim((string) $this->option('operador'));

        if ($allActive) {
            $hechos = $this->service->resetMany($targets, $operador, 'comando', ['except' => $this->exceptEmails(), 'include_superadmins' => (bool) $this->option('include-superadmins')]);
        } else {
            $this->service->resetUser($targets->firstOrFail(), $operador, 'comando');
            $hechos = 1;
        }

        $this->info("Reset aplicado a {$hechos} usuario(s): contraseña = correo y cambio obligatorio al ingresar.");

        return self::SUCCESS;
    }

    /** @return Collection<int, User>|null */
    private function resolverIndividual(string $email): ?Collection
    {
        /** @var User|null $user */
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($user === null) {
            $this->error("No existe un usuario con el correo [{$email}].");

            return null;
        }

        if ($user->is_active !== true) {
            $this->error("El usuario [{$user->email}] está inactivo: un reset de contraseña no reactiva cuentas.");

            return null;
        }

        return new Collection([$user]);
    }

    /** @return Collection<int, User> */
    private function resolverMasivo(): Collection
    {
        return $this->service->resolveTargets([], $this->exceptEmails(), null, (bool) $this->option('include-superadmins'));
    }

    /** @return list<string> */
    private function exceptEmails(): array
    {
        $raw = trim((string) $this->option('except'));

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $e): bool => $e !== ''));
    }

    private function confirmar(int $cantidad): bool
    {
        $esperado = 'RESETEAR ' . $this->etiquetaEntorno() . ' ' . $cantidad;
        $this->newLine();
        $this->warn('Operación SENSIBLE: la contraseña de cada usuario pasa a ser su correo y deberá cambiarla al ingresar.');
        if ($this->entorno() === 'production') {
            $this->warn('PRODUCCIÓN: hasta que cada persona cambie su contraseña, cualquiera que conozca su correo podría ingresar. Ejecútalo en una ventana controlada y avisa a las personas.');
        }

        return trim((string) $this->ask("Para continuar escribe exactamente: {$esperado}")) === $esperado;
    }

    private function entorno(): string
    {
        return (string) config('app.env');
    }

    private function etiquetaEntorno(): string
    {
        return $this->entorno() === 'production' ? 'PRODUCCION' : strtoupper($this->entorno());
    }

    private function entornoSinConfirmacion(): bool
    {
        return in_array($this->entorno(), self::ENTORNOS_SIN_CONFIRMACION, true);
    }
}
