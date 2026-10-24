<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Marca "debe cambiar la contraseña al primer ingreso" a las cuentas activas cuya contraseña sigue siendo su propio correo
 * (la contraseña inicial del onboarding). NO toca la contraseña, NO pone plazo de caducidad (`password_reset_at` queda
 * intacto) y NO cierra sesiones: quien entre con la contraseña inicial es obligado a elegir la suya, sin importar cuándo lo haga.
 *
 * Diferencia con `users:reset-password`: aquel devuelve la contraseña a "= correo" con caducidad de 48 h y avisa por correo;
 * este solo añade el cambio obligatorio y es el que corresponde antes del lanzamiento (SCRUM-1034, H7).
 *
 * - SIMULACIÓN por defecto: lista a quiénes alcanzaría y no cambia nada.
 * - `--apply` exige `--operador` (queda en la auditoría) y escribir exactamente `MARCAR <ENTORNO> <N>` (`PRODUCCION` en
 *   producción). Sin TTY (ssh), la confirmación entra por stdin con SHELL_INTERACTIVE=1.
 * - `--yes` (omite la confirmación) SOLO en local y testing.
 * - Nunca imprime contraseñas ni hashes: solo correos y cantidades.
 * - Quien lo ejecute y esté dentro del conjunto debe excluirse con `--except` si no quiere ver su propia sesión pedir el cambio.
 */
final class UsersRequirePasswordChangeCommand extends Command
{
    private const ENTORNOS_SIN_CONFIRMACION = ['local', 'testing'];

    /** @var string */
    protected $signature = 'users:require-password-change
        {--except= : Correos a excluir, separados por coma}
        {--tenant=atlantic : Slug del tenant}
        {--apply : Aplica el cambio. Sin esta opción es una simulación que no cambia nada}
        {--yes : Omite la confirmación escrita. SOLO en local y testing; en cualquier otro entorno se rechaza}
        {--operador= : Quién ejecuta (queda en la auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'Obliga a cambiar la contraseña al primer ingreso a las cuentas activas con contraseña = correo (sin caducidad, sin tocar la contraseña). Simulación por defecto.';

    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('yes') && ! $this->entornoSinConfirmacion()) {
            $this->error('--yes no se acepta en el entorno "' . $this->entorno() . '": solo en ' . implode(' y ', self::ENTORNOS_SIN_CONFIRMACION) . '. La confirmación debe ser escrita.');

            return self::FAILURE;
        }

        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en la auditoría (quién ejecutó el cambio).');

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
            return $this->ejecutar($slug, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply): int
    {
        $targets = $this->resolverObjetivo();

        $this->info('users:require-password-change — ' . ($apply ? 'APLICAR' : 'SIMULACIÓN') . ' (entorno ' . $this->entorno() . ', tenant ' . $slug . ')');
        $this->line('Cuentas activas con contraseña = correo y sin cambio obligatorio: ' . $targets->count());
        foreach ($targets as $user) {
            $this->line('  - ' . $user->email);
        }

        if (! $apply) {
            $this->comment('Simulación: NO se cambió nada. Para aplicar: --apply --operador="Nombre" (pide confirmación escrita).');

            return self::SUCCESS;
        }

        if ($targets->isEmpty()) {
            $this->warn('No hay cuentas que marcar.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('yes') && ! $this->confirmar($targets->count())) {
            $this->error('Confirmación incorrecta: no se aplicó ningún cambio.');

            return self::FAILURE;
        }

        $operador = trim((string) $this->option('operador'));
        foreach ($targets as $user) {
            // Solo el indicador: sin `password_reset_at` (no caduca) y sin revocar sesiones ni cambiar la contraseña.
            $user->must_change_password = true;
            $user->save();

            $this->audit->log(
                action:     'auth.user.password_change_required',
                entityType: 'user',
                entityId:   $user->id,
                metadata:   ['operador' => $operador, 'origen' => 'comando', 'caduca' => false],
            );
        }

        $this->audit->log(
            action:   'auth.user.password_change_required_bulk',
            metadata: ['operador' => $operador, 'origen' => 'comando', 'cantidad' => $targets->count(), 'except' => $this->exceptEmails()],
        );

        $this->info("Marcadas {$targets->count()} cuenta(s): deberán cambiar la contraseña al primer ingreso (sin caducidad).");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function resolverObjetivo(): Collection
    {
        $except = array_map('mb_strtolower', $this->exceptEmails());

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('must_change_password', false)->orWhereNull('must_change_password'))
            ->orderBy('id')
            ->get()
            ->filter(static fn (User $u): bool => ! in_array(mb_strtolower($u->email), $except, true) && Hash::check($u->email, $u->password_hash))
            ->values();
    }

    /** @return list<string> */
    private function exceptEmails(): array
    {
        $raw = trim((string) $this->option('except'));

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $e): bool => $e !== ''));
    }

    private function confirmar(int $cantidad): bool
    {
        $esperado = 'MARCAR ' . $this->etiquetaEntorno() . ' ' . $cantidad;
        $this->newLine();
        $this->warn('Estas cuentas pedirán cambiar la contraseña en su próximo ingreso; una sesión abierta recibirá la pantalla de cambio obligatorio.');

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
