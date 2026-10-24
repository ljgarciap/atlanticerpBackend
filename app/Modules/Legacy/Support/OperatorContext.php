<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * SCRUM-1114 (revisión de seguridad S-04) — los comandos manuales de stock (`--operador`, `--force`,
 * `--apply`) no tienen usuario autenticado: `--operador` es texto libre. Para no depender solo de ese texto,
 * cada evento de auditoría agrega quién es realmente el proceso.
 *
 * Dentro de `docker compose exec` el contexto de la sesión ssh NO llega solo (Docker no reenvía el entorno y
 * el usuario del proceso es el del contenedor), así que hay dos fuentes:
 *  - `usuario_so` / `host`: lo que el propio proceso ve (usuario efectivo y hostname del contenedor).
 *  - `usuario_ssh` / `origen_ssh`: lo que el comando de ssh pasa explícitamente con
 *    `-e OPERATOR_OS_USER="$USER" -e SSH_CONNECTION="$SSH_CONNECTION"` (ver el runbook). `SSH_CONNECTION` es
 *    "ip_origen puerto_origen ip_destino puerto_destino"; solo se guarda la IP de origen. Son datos DECLARADOS
 *    por quien lanza el comando (igual que `--operador`): sirven para rastrear y cruzar con el log de ssh del
 *    servidor, no como prueba criptográfica.
 */
final class OperatorContext
{
    /** @return array{operador: string, usuario_so: string, host: string, usuario_ssh: string|null, origen_ssh: string|null} */
    public static function describe(string $operador): array
    {
        return [
            'operador'    => $operador,
            'usuario_so'  => self::usuarioDelProceso(),
            'host'        => (string) gethostname(),
            'usuario_ssh' => self::entorno('OPERATOR_OS_USER', 64),
            'origen_ssh'  => self::origenSsh(),
        ];
    }

    /**
     * Línea de texto para correos y avisos, p. ej.:
     * "Operador declarado: Luis — proceso: root en 69c1c1855290 (sesión ssh de ubuntu desde 190.x.x.x)".
     *
     * @param array{operador: string, usuario_so: string, host: string, usuario_ssh: string|null, origen_ssh: string|null} $ctx
     */
    public static function linea(array $ctx): string
    {
        $ssh = '';
        if ($ctx['usuario_ssh'] !== null || $ctx['origen_ssh'] !== null) {
            $ssh = ' (sesión ssh' . ($ctx['usuario_ssh'] !== null ? " de {$ctx['usuario_ssh']}" : '') . ($ctx['origen_ssh'] !== null ? " desde {$ctx['origen_ssh']}" : '') . ')';
        }

        return "Operador declarado: {$ctx['operador']} — proceso: {$ctx['usuario_so']} en {$ctx['host']}{$ssh}";
    }

    private static function usuarioDelProceso(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $actual = get_current_user();

        return $actual !== '' ? $actual : 'desconocido';
    }

    private static function origenSsh(): ?string
    {
        $conexion = self::entorno('SSH_CONNECTION', 120);

        return $conexion === null ? null : (explode(' ', $conexion)[0] ?: null);
    }

    /** Variable de entorno limpia (sin saltos de línea ni caracteres de control) y acotada, o null si no existe. */
    private static function entorno(string $nombre, int $max): ?string
    {
        $valor = getenv($nombre);
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($valor)), 0, $max);
    }
}
