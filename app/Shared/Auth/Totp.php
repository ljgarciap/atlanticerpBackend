<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Fase 27c — contraseña de un solo uso basada en tiempo (RFC 6238, que usa el HOTP del RFC 4226): SHA-1, 6 dígitos, paso de 30 s.
 * Es el formato que entienden todas las aplicaciones autenticadoras (Google Authenticator, Authy, 1Password, Microsoft Authenticator…).
 * Sin dependencias: son ~40 líneas y se comprueban contra los vectores de prueba del propio RFC (`TotpTest`).
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Pasos de tolerancia a cada lado del actual (relojes desfasados hasta ±30 s). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo de 160 bits (lo que recomienda el RFC 4226) en base32, sin relleno. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Código del paso `$step` (= segundos Unix / 30). */
    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $mac = hash_hmac('sha1', pack('J', $step), $key, true);
        $off = ord($mac[19]) & 0x0F;
        $bin = ((ord($mac[$off]) & 0x7F) << 24) | (ord($mac[$off + 1]) << 16) | (ord($mac[$off + 2]) << 8) | ord($mac[$off + 3]);

        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $now = null): int
    {
        return intdiv($now ?? time(), self::PERIOD);
    }

    /**
     * Paso en el que `$code` es válido (dentro de la tolerancia), o null. Devuelve el paso, no un booleano, para que quien llama
     * pueda rechazar la REPETICIÓN: un código ya usado no debe servir otra vez dentro de su ventana de ~90 s.
     */
    public static function matchStep(string $secret, string $code, ?int $now = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = self::currentStep($now);
        $found   = null;
        // Se recorren todos los pasos (sin cortar al primero) para que el tiempo de respuesta no dependa de cuál acertó.
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::codeAt($secret, $step), $code) && $found === null) {
                $found = $step;
            }
        }

        return $found;
    }

    /** URI `otpauth://` que codifica el QR de alta. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, "0"))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Secreto base32 no válido.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
