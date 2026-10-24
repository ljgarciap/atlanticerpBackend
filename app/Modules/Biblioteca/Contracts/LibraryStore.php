<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Contracts;

/**
 * SCRUM-984 — acceso al bucket compartido de la biblioteca. Las claves son LÓGICAS (sin el prefijo
 * físico `library/`, que agrega la implementación). Existe como contrato para que los Feature
 * tests usen un fake en memoria: nunca se toca S3 real desde PHPUnit.
 */
interface LibraryStore
{
    /** false cuando falta AWS_LIBRARY_BUCKET — los endpoints responden 503. */
    public function isConfigured(): bool;

    /**
     * @return list<string> claves lógicas bajo el prefijo (vacío = todo el bucket de la biblioteca)
     */
    public function listKeys(string $prefix): array;

    /** @return array<string, mixed>|null null si la clave no existe */
    public function getJson(string $key): ?array;

    /**
     * Lectura de varios JSON en un solo pase (en paralelo en la implementación real), para no hacer
     * un GetObject secuencial por sidecar en cada cache miss del catálogo.
     *
     * @param  list<string> $keys
     * @return array<string, array<string, mixed>|null> clave => contenido (null si no existe)
     */
    public function getJsonMany(array $keys): array;

    /** @param array<string, mixed> $data */
    public function putJson(string $key, array $data): void;

    /** Sube un archivo local (carga inicial por comando; las subidas de usuarios van por PUT prefirmado). */
    public function putFile(string $key, string $localPath, string $contentType): void;

    /**
     * `version_id`/`etag` identifican EXACTAMENTE lo que se validó: finalize lee y copia atado a
     * ellos, para que un re-PUT posterior a la misma clave no publique contenido no validado.
     *
     * @return array{size: int, content_type: string, version_id: ?string, etag: ?string}|null null si la clave no existe
     */
    public function head(string $key): ?array;

    /**
     * $length bytes desde $offset (lectura por Range), atados a una versión concreta si se indica.
     * Sirve para los magic bytes y para el escaneo de contenido activo del PDF.
     */
    public function readRange(string $key, int $offset, int $length, ?string $versionId = null): string;

    /**
     * PUT prefirmado. OJO: el SDK NO firma `Content-Length` ni `Content-Type` en una URL prefirmada
     * (SignatureV4 los excluye) y un PUT prefirmado tampoco admite `content-length-range`: S3 no
     * impone el tope. Lo hace cumplir finalize (HeadObject), más el lifecycle de `_pending/` y la
     * cuota de pendientes (decisión de Luis 2026-09-30, hallazgo M-1).
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPut(string $key, string $contentType, int $contentLength, int $ttlSeconds): array;

    public function presignGet(string $key, int $ttlSeconds, string $contentType, string $disposition): string;

    /**
     * Copia server-side con el cliente S3 directo — nunca Storage::copy() (pide GetObjectAcl).
     * Con $versionId (o, sin versionado, $etag vía CopySourceIfMatch) copia exactamente lo validado.
     * Con $contentType reemplaza los metadatos por el tipo canónico (no el que eligió el cliente).
     */
    public function copy(string $from, string $to, ?string $versionId = null, ?string $etag = null, ?string $contentType = null): void;

    /** Con versionado activo solo crea un delete marker (recuperable 90 días). */
    public function delete(string $key): void;
}
