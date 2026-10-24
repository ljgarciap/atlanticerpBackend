<?php

declare(strict_types=1);

/**
 * Hosts desde los que se acepta (y se descarga) `catalog_products.photo_url` — SCRUM-1038 (S-01, SSRF).
 *
 * Por defecto: el host del CDN (`CDN_URL`), el de `AWS_URL` y el del bucket regional de S3. Hosts adicionales,
 * separados por coma, en `CATALOG_PHOTO_EXTRA_HOSTS`.
 */
$hostOf = static function (?string $url): ?string {
    $host = $url === null || $url === '' ? null : parse_url($url, PHP_URL_HOST);

    return is_string($host) && $host !== '' ? strtolower($host) : null;
};

$bucket = env('AWS_BUCKET');
$region = env('AWS_DEFAULT_REGION', 'us-east-1');

return [
    'photo_hosts' => array_values(array_unique(array_filter(array_merge(
        [
            $hostOf(env('CDN_URL')),
            $hostOf(env('AWS_URL')),
            is_string($bucket) && $bucket !== '' ? strtolower("{$bucket}.s3.{$region}.amazonaws.com") : null,
        ],
        array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', (string) env('CATALOG_PHOTO_EXTRA_HOSTS', ''))),
    )))),
];
