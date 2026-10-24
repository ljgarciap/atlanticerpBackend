<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * SCRUM-1158 («Ver como usuario», ADR-010, condición C1 del threat model) — única fuente de «quién hizo esto» a partir de los claims del JWT.
 *
 * En una sesión normal el autor es `sub`. En una sesión de impersonación `sub` es el usuario OBJETIVO (así toda la autorización sale con su
 * identidad) y el autor real es `act.sub`: el superadmin. Cualquier código que registre un autor (auditoría, bitácoras, `created_by`,
 * atribución a nivel de BD) debe pasar por acá en vez de leer `sub` a mano, o culparía al suplantado.
 */
final class JwtActor
{
    /**
     * Id del autor real. Null si el payload no trae un id usable.
     *
     * @param array<string, mixed>|null $payload claims del JWT (`$request->attributes->get('jwt')`)
     */
    public static function id(?array $payload): ?int
    {
        if ($payload === null) {
            return null;
        }

        if (array_key_exists('act', $payload)) {
            // Con claim `act` el autor es SIEMPRE el actor. Si `act` viniera mal formado no se cae a `sub`: sería atribuir al suplantado
            // (AuthenticateJwt ya rechaza esos tokens con 401; esto es defensa en profundidad).
            $act = $payload['act'];

            return is_array($act) && isset($act['sub']) && is_numeric($act['sub']) ? (int) $act['sub'] : null;
        }

        $sub = $payload['sub'] ?? null;

        return is_int($sub) || (is_string($sub) && ctype_digit($sub)) ? (int) $sub : null;
    }

    /**
     * Contexto de impersonación del payload, o null si el token es normal. `impersonated_user_id` es el `sub` (el objetivo).
     *
     * @param array<string, mixed>|null $payload
     * @return array{actor_id: int|null, impersonation_id: string|null, impersonated_user_id: int|null}|null
     */
    public static function impersonation(?array $payload): ?array
    {
        if ($payload === null || ! array_key_exists('act', $payload)) {
            return null;
        }

        $act = is_array($payload['act']) ? $payload['act'] : [];
        $sub = $payload['sub'] ?? null;

        return [
            'actor_id'             => self::id($payload),
            'impersonation_id'     => isset($act['imp_id']) && is_string($act['imp_id']) ? $act['imp_id'] : null,
            'impersonated_user_id' => is_int($sub) || (is_string($sub) && ctype_digit($sub)) ? (int) $sub : null,
        ];
    }

    /**
     * ¿La request en curso es una sesión «Ver como usuario»? Para el código de negocio que, aunque sea un GET, persiste algo al calcular (p. ej. congelar
     * el snapshot de comisión de un mes cerrado): con `act` debe calcular y devolver lo mismo SIN escribir. Fuera de HTTP (cola, consola) es false.
     */
    public static function currentRequestIsImpersonating(): bool
    {
        try {
            $payload = request()->attributes->get('jwt');

            return self::impersonation(is_array($payload) ? $payload : null) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
