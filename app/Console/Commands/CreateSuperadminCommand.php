<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 4d (docs/specs/fase4d-superadmin-inicial.md) — alta del primer superadmin de un entorno. Reemplaza a las cuentas `*@test.com` con contraseña = correo
 * que el seed por defecto creaba en TODOS los entornos (ahora solo local/dev/testing).
 *
 * Contraseña aleatoria mostrada UNA sola vez (nunca se guarda en claro ni se escribe en logs) y `must_change_password = true`: el primer ingreso obliga a
 * cambiarla. Nunca toca a un usuario que ya existe.
 */
final class CreateSuperadminCommand extends Command
{
    private const SECURITY_LEVEL = 10;

    private const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'tenant:create-superadmin {slug : Slug del tenant} {email : Correo del superadmin}
        {--first-name=Superadmin : Nombre} {--last-name=Inicial : Apellido}
        {--password= : Contraseña inicial (por defecto se genera una aleatoria y se muestra una sola vez)}';

    protected $description = 'Crea el superadmin inicial de un tenant (contraseña aleatoria mostrada una vez, cambio obligatorio al primer ingreso)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$email}» no es un correo válido.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', (string) $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error('No existe un tenant con slug ['.$this->argument('slug').']. Créelo con `tenant:create`.');

            return self::FAILURE;
        }

        $provided = $this->option('password');
        $password = is_string($provided) && $provided !== '' ? $provided : Str::password(24);
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        try {
            if (User::query()->where('email', $email)->exists()) {
                $this->error("Ya existe un usuario con el correo {$email}; no se modifica.");

                return self::FAILURE;
            }

            $roleId = DB::table("{$tenant->slug}_auth.roles")->where('key', RoleKeys::SUPERADMIN)->value('id');
            $levelId = DB::table("{$tenant->slug}_auth.security_levels")->where('level', self::SECURITY_LEVEL)->value('id');
            if ($roleId === null || $levelId === null) {
                $this->error('Faltan el rol o el nivel de seguridad de superadmin: corra primero `php artisan db:seed`.');

                return self::FAILURE;
            }

            User::query()->create([
                'first_name' => (string) $this->option('first-name'),
                'last_name' => (string) $this->option('last-name'),
                'email' => $email,
                'password_hash' => $password,
                'is_active' => true,
                'must_change_password' => true,
                'security_level_id' => $levelId,
                'role_id' => $roleId,
                'extra_permissions' => [],
            ]);
        } finally {
            Tenant::forgetCurrent();
        }

        $this->info("Superadmin {$email} creado en el tenant [{$tenant->slug}].");
        if (! is_string($provided) || $provided === '') {
            $this->warn('Contraseña inicial (se muestra una sola vez, no queda guardada): '.$password);
        }
        $this->line('El primer ingreso exige cambiarla.');

        return self::SUCCESS;
    }
}
