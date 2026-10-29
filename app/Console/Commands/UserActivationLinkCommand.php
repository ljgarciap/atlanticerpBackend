<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Services\PasswordResetService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Fase 12c — imprime un enlace de activación NUEVO para una cuenta, sin enviar correo: para soporte, para entornos sin proveedor de correo
 * (`MAIL_MAILER=log`) y para los e2e. Quien corre el comando ya tiene acceso al servidor; el enlace anula los anteriores de esa cuenta y vence
 * según `password_reset_expiration_hours`. No toca la contraseña ni `must_change_password`.
 */
final class UserActivationLinkCommand extends Command
{
    protected $signature = 'users:activation-link {slug : Slug del tenant} {email : Correo de la cuenta}';

    protected $description = 'Imprime un enlace de activación nuevo para una cuenta (sin enviar correo)';

    public function handle(PasswordResetService $links): int
    {
        $tenant = Tenant::where('slug', (string) $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error('Tenant no encontrado.');

            return self::FAILURE;
        }
        $tenant->makeCurrent();

        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        $url = $user === null ? null : $links->activationLink($user);
        if ($url === null) {
            $this->error('La cuenta no existe, está inactiva o no tiene acceso: no se emite enlace.');

            return self::FAILURE;
        }

        $this->line(OutputFormatter::escape($url));

        return self::SUCCESS;
    }
}
