<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Services;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use App\Modules\Biblioteca\Exceptions\LibraryObjectChangedException;
use App\Modules\Biblioteca\Exceptions\LibraryStorageUnavailableException;
use Aws\CommandPool;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * SCRUM-984 — implementación real sobre el bucket compartido (disco `s3-library`). Usa el cliente
 * del SDK directo y no Flysystem: Storage::copy() dispara GetObjectAcl, permiso que el usuario
 * IAM de la biblioteca no tiene (hallazgo registrado en la memoria del proyecto).
 */
final class S3LibraryStore implements LibraryStore
{
    private const ROOT = 'library/';

    private ?S3Client $client = null;

    /** Inyecta el cliente del SDK (los tests usan un MockHandler; en producción se construye con la config). */
    public function withClient(S3Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Bucket Y credenciales propias: sin las dos keys AWS_LIBRARY_* el SDK caería a su cadena por
     * defecto (AWS_ACCESS_KEY_ID del proceso o instance profile) y escribiría con credenciales que
     * no son las del usuario dedicado de la biblioteca — se prefiere el 503 (hallazgo B-7).
     */
    public function isConfigured(): bool
    {
        return (string) config('filesystems.disks.s3-library.bucket') !== ''
            && (string) config('filesystems.disks.s3-library.key') !== ''
            && (string) config('filesystems.disks.s3-library.secret') !== '';
    }

    public function listKeys(string $prefix): array
    {
        $keys = [];

        try {
            $pages = $this->client()->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket(),
                'Prefix' => self::ROOT . ltrim($prefix, '/'),
            ]);

            foreach ($pages as $page) {
                /** @var list<array{Key: string}> $contents */
                $contents = $page['Contents'] ?? [];
                foreach ($contents as $object) {
                    $keys[] = substr($object['Key'], strlen(self::ROOT));
                }
            }
        } catch (AwsException $e) {
            throw $this->translate($e);
        }

        return $keys;
    }

    public function getJson(string $key): ?array
    {
        try {
            $result = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $this->physical($key)]);
        } catch (AwsException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }
            throw $this->translate($e);
        }

        $decoded = json_decode((string) $result['Body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getJsonMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // OJO: CommandPool::batch() indexa los resultados por POSICIÓN (0..n-1, tras un ksort), NO por la clave
        // con la que se arma el arreglo de comandos (sin `preserve_iterator_keys` el generador no entrega claves).
        // Se arma una lista y se guarda el mapeo posición -> clave lógica; leer `$results[$key]` daba
        // "Undefined array key" contra S3 real (bug en dev, 2026-09-30).
        $commands = [];
        $ordered  = [];
        foreach (array_values($keys) as $position => $key) {
            $ordered[$position]  = $key;
            $commands[$position] = $this->client()->getCommand('GetObject', [
                'Bucket' => $this->bucket(),
                'Key'    => $this->physical($key),
            ]);
        }

        $results = CommandPool::batch($this->client(), $commands, ['concurrency' => 10]);

        $out = [];
        foreach ($ordered as $position => $key) {
            $result = $results[$position] ?? null;
            if ($result instanceof AwsException) {
                if ($this->isNotFound($result)) {
                    $out[$key] = null;
                    continue;
                }
                throw $this->translate($result);
            }
            if (! $result instanceof \Aws\ResultInterface) {
                // El pool no devolvió nada para esta posición: no se adivina, se trata como fallo del almacenamiento.
                throw new LibraryStorageUnavailableException('missing_batch_result');
            }
            $decoded   = json_decode((string) $result['Body'], true);
            $out[$key] = is_array($decoded) ? $decoded : null;
        }

        return $out;
    }

    public function putJson(string $key, array $data): void
    {
        try {
            $this->client()->putObject([
                'Bucket'      => $this->bucket(),
                'Key'         => $this->physical($key),
                'Body'        => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ContentType' => 'application/json',
            ]);
        } catch (AwsException $e) {
            throw $this->translate($e);
        }
    }

    public function putFile(string $key, string $localPath, string $contentType): void
    {
        try {
            $this->client()->putObject([
                'Bucket'      => $this->bucket(),
                'Key'         => $this->physical($key),
                'SourceFile'  => $localPath,
                'ContentType' => $contentType,
            ]);
        } catch (AwsException $e) {
            throw $this->translate($e);
        }
    }

    public function head(string $key): ?array
    {
        try {
            $result = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $this->physical($key)]);
        } catch (AwsException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }
            throw $this->translate($e);
        }

        return [
            'size'         => (int) $result['ContentLength'],
            'content_type' => (string) ($result['ContentType'] ?? ''),
            'version_id'   => isset($result['VersionId']) ? (string) $result['VersionId'] : null,
            'etag'         => isset($result['ETag']) ? (string) $result['ETag'] : null,
        ];
    }

    public function readRange(string $key, int $offset, int $length, ?string $versionId = null): string
    {
        $args = [
            'Bucket' => $this->bucket(),
            'Key'    => $this->physical($key),
            'Range'  => 'bytes=' . $offset . '-' . ($offset + max(1, $length) - 1),
        ];
        if ($versionId !== null) {
            $args['VersionId'] = $versionId;
        }

        try {
            return (string) $this->client()->getObject($args)['Body'];
        } catch (S3Exception $e) {
            // Un Range que empieza pasado el final del objeto (416) equivale a "no hay más bytes".
            if ($e->getStatusCode() === 416) {
                return '';
            }
            throw $this->translate($e);
        } catch (AwsException $e) {
            throw $this->translate($e);
        }
    }

    public function presignPut(string $key, string $contentType, int $contentLength, int $ttlSeconds): array
    {
        $command = $this->client()->getCommand('PutObject', [
            'Bucket'        => $this->bucket(),
            'Key'           => $this->physical($key),
            'ContentType'   => $contentType,
            'ContentLength' => $contentLength, // el SDK NO lo firma (ver contrato): el tope lo impone finalize
        ]);
        $request = $this->client()->createPresignedRequest($command, "+{$ttlSeconds} seconds");

        return [
            'url'     => (string) $request->getUri(),
            // El navegador manda Content-Length solo a partir del cuerpo; Content-Type lo fija el cliente
            // (no va firmado: por eso finalize reemplaza el tipo por el canónico al copiar).
            'headers' => ['Content-Type' => $contentType],
        ];
    }

    public function presignGet(string $key, int $ttlSeconds, string $contentType, string $disposition): string
    {
        $command = $this->client()->getCommand('GetObject', [
            'Bucket'                     => $this->bucket(),
            'Key'                        => $this->physical($key),
            'ResponseContentType'        => $contentType,
            'ResponseContentDisposition' => $disposition,
        ]);

        return (string) $this->client()->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri();
    }

    public function copy(string $from, string $to, ?string $versionId = null, ?string $etag = null, ?string $contentType = null): void
    {
        $source = $this->bucket() . '/' . str_replace('%2F', '/', rawurlencode($this->physical($from)));
        $args   = [
            'Bucket'     => $this->bucket(),
            'Key'        => $this->physical($to),
            'CopySource' => $versionId !== null ? $source . '?versionId=' . rawurlencode($versionId) : $source,
        ];
        if ($versionId === null && $etag !== null) {
            $args['CopySourceIfMatch'] = $etag; // sin versionado: falla si el objeto cambió tras validarlo
        }
        if ($contentType !== null) {
            $args['MetadataDirective'] = 'REPLACE';
            $args['ContentType']       = $contentType;
        }

        try {
            $this->client()->copyObject($args);
        } catch (AwsException $e) {
            throw $this->translate($e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $this->physical($key)]);
        } catch (AwsException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * Fallo de S3 => excepción del dominio. "El objeto/versión ya no está o cambió" se limita a los códigos
     * NoSuchVersion, NoSuchKey y PreconditionFailed (412): la subida no se puede publicar y hay que repetirla.
     * Todo lo demás es un fallo del almacenamiento, reintentable o de configuración — AccessDenied, 5xx,
     * throttling, timeouts, errores de red y también un 404 con otro código (p. ej. NoSuchBucket por un
     * AWS_LIBRARY_BUCKET mal puesto): NO se le dice al usuario "vuelve a subirlo" ni se descarta su pending.
     */
    private function translate(AwsException $e): \RuntimeException
    {
        $code = (string) $e->getAwsErrorCode();
        if (in_array($code, ['NoSuchVersion', 'NoSuchKey', 'PreconditionFailed'], true) || $e->getStatusCode() === 412) {
            return new LibraryObjectChangedException($code !== '' ? $code : 'object_changed', 0, $e);
        }

        return new LibraryStorageUnavailableException($code !== '' ? $code : 'storage_unavailable', 0, $e);
    }

    private function physical(string $key): string
    {
        return self::ROOT . ltrim($key, '/');
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.s3-library.bucket');
    }

    /**
     * "La clave no existe" y nada más: NoSuchKey / NotFound (el HEAD 404 no trae cuerpo y el SDK lo mapea a
     * NotFound), o un 404 sin código. Un 404 NoSuchBucket (AWS_LIBRARY_BUCKET mal puesto) NO es "no existe":
     * antes se leía como null y un bucket equivocado parecía un catálogo vacío en vez de un fallo visible.
     */
    private function isNotFound(AwsException $e): bool
    {
        $code = (string) $e->getAwsErrorCode();

        return in_array($code, ['NoSuchKey', 'NotFound'], true) || ($code === '' && $e->getStatusCode() === 404);
    }

    private function client(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var array<string, mixed> $disk */
        $disk = (array) config('filesystems.disks.s3-library');
        $args = [
            'version'     => 'latest',
            'region'      => (string) ($disk['region'] ?? 'us-east-1'),
            // Siempre las credenciales propias de la biblioteca; isConfigured() ya exige que existan.
            'credentials' => ['key' => (string) ($disk['key'] ?? ''), 'secret' => (string) ($disk['secret'] ?? '')],
        ];
        if (! empty($disk['endpoint'])) {
            $args['endpoint'] = $disk['endpoint'];
        }
        if (! empty($disk['use_path_style_endpoint'])) {
            $args['use_path_style_endpoint'] = true;
        }

        return $this->client = new S3Client($args);
    }
}
