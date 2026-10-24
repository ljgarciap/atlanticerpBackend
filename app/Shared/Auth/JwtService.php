<?php

namespace App\Shared\Auth;

use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

class JwtService
{
    private string $secret;

    private int $expirationMs;

    public function __construct()
    {
        $secret       = config('jwt.secret');
        $expirationMs = config('jwt.expiration_ms');

        if (! is_string($secret) || $secret === '') {
            throw new UnexpectedValueException('JWT_SECRET is not configured.');
        }

        $this->secret       = $secret;
        $this->expirationMs = is_numeric($expirationMs) ? (int) $expirationMs : 3_600_000;
    }

    /**
     * Signs an access JWT with the given claims, adding `iat` and `exp`.
     *
     * @param array<string, mixed> $claims
     * @param int|null $expirationMs Override para la duración de este token
     *        (ej. session_timeout_min efectivo del usuario). Si se omite,
     *        usa el default de despliegue (JWT_EXPIRATION_MS).
     */
    public function encode(array $claims, ?int $expirationMs = null): string
    {
        $now        = time();
        $expiration = $expirationMs ?? $this->expirationMs;

        return JWT::encode(
            array_merge($claims, [
                'iat' => $now,
                'exp' => $now + intdiv($expiration, 1000),
            ]),
            $this->secret,
            'HS256'
        );
    }

    /**
     * Decodes and validates a JWT. Returns null if invalid or expired.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));

            /** @var array<string, mixed> $payload */
            $payload = (array) json_decode((string) json_encode($decoded), true);

            return $payload;
        } catch (DomainException|ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }
}
