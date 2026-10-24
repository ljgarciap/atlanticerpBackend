<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * SCRUM-1142 — cuentas que existen solo para que la persona (o la opción) aparezca en los selectores de Bodega
 * (picker / repartidor) y NUNCA deben poder iniciar sesión. Decisión de Luis: "no tienen acceso, únicamente son
 * para que el usuario aparezca".
 *
 * `BusinessRoleUserSeeder` las crea con una contraseña aleatoria (no su correo) y el reset masivo de contraseñas
 * (`AdminPasswordResetService::resolveTargets()`) las deja fuera, porque un reset las reactivaría con contraseña =
 * correo. Una cuenta con acceso real NO va en esta lista.
 */
final class NoLoginAccounts
{
    /** @var list<string> correos en minúsculas */
    public const EMAILS = [
        'pickerbodega@test.com',
        'repartidor@test.com',
        'personalbodega@test.com',
    ];

    public static function isNoLogin(string $email): bool
    {
        return in_array(mb_strtolower(trim($email)), self::EMAILS, true);
    }
}
