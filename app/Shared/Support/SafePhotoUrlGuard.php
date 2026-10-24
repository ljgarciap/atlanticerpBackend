<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Closure;

/**
 * SCRUM-1038 (S-01) — defensa contra SSRF para URLs de foto de producto que escribe un usuario.
 *
 * Dos chequeos: `isAllowed()` (esquema https + host en la lista configurada, usado al validar y al descargar) y
 * `resolvesToPublicIps()` (solo al descargar: el host no puede resolver a IP privada, de loopback, de enlace local
 * ni de metadatos). La lista de hosts vive en configuración (`config/catalog.php`), nunca en el código.
 */
final class SafePhotoUrlGuard
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param (Closure(string): list<string>)|null $resolver Inyectable para pruebas; por defecto DNS real (A y AAAA). */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }

            return $ips;
        };
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $port = $parts['port'] ?? 443;
        if ($port !== 443) {
            return false;
        }

        /** @var list<string> $hosts */
        $hosts = array_map('strtolower', (array) config('catalog.photo_hosts', []));

        return in_array(strtolower($parts['host']), $hosts, true);
    }

    public function resolvesToPublicIps(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            // Rechaza privadas, reservadas (incluye loopback, 169.254.0.0/16 y fc00::/7) y cualquier cosa que no sea IP.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
