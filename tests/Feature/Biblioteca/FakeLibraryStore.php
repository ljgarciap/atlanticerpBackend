<?php

declare(strict_types=1);

namespace Tests\Feature\Biblioteca;

use App\Modules\Biblioteca\Contracts\LibraryStore;

/**
 * SCRUM-984 — bucket en memoria para los Feature tests: nunca se toca S3 real desde PHPUnit.
 * `$objects` guarda el contenido crudo por clave lógica; `$presigned*` registra lo que el servicio
 * pidió firmar para poder asertar TTL, clave y headers.
 */
final class FakeLibraryStore implements LibraryStore
{
    public bool $configured = true;

    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> key => content-type declarado al subir/copiar */
    public array $contentTypes = [];

    /** @var array<string, array<string, string>> key => [versionId => contenido]: simula el versionado del bucket */
    public array $versions = [];

    /** @var array<string, string> key => versionId vigente */
    public array $latestVersion = [];

    private int $versionSeq = 0;

    /** Gancho que corre justo antes de copiar: reproduce un re-PUT del cliente entre validar y copiar (M-2). */
    public ?\Closure $beforeCopy = null;

    /** Si se asigna, presignPut lo lanza (simula S3 caído al firmar). */
    public ?\Throwable $presignPutFails = null;

    /** @var array<string, \Throwable> operación (head|readRange|copy|delete|putJson) => excepción que lanza (simula fallos de S3) */
    public array $failures = [];

    /** @var array<string, \Throwable> clave => excepción SOLO al borrar esa clave (p. ej. sidecar OK y objeto fallido) */
    public array $deleteFailures = [];

    /** @var list<string> claves en el orden en que se intentó borrarlas (incluye los intentos que fallaron) */
    public array $deleteLog = [];

    public int $getJsonCalls = 0;
    public int $getJsonManyCalls = 0;

    /** @var list<array{key: string, content_type: string, length: int, ttl: int}> */
    public array $presignedPuts = [];

    /** @var list<array{key: string, ttl: int, content_type: string, disposition: string}> */
    public array $presignedGets = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function listKeys(string $prefix): array
    {
        $keys = array_keys(array_filter(
            $this->objects,
            static fn (string $_, string $k): bool => $prefix === '' || str_starts_with($k, $prefix),
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($keys);

        return array_values($keys);
    }

    public function getJsonMany(array $keys): array
    {
        $this->getJsonManyCalls++;
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = isset($this->objects[$key]) ? $this->decode($this->objects[$key]) : null;
        }

        return $out;
    }

    public function getJson(string $key): ?array
    {
        $this->getJsonCalls++;
        if (! isset($this->objects[$key])) {
            return null;
        }
        return $this->decode($this->objects[$key]);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function maybeFail(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }

    public function putJson(string $key, array $data): void
    {
        $this->maybeFail('putJson');
        $this->write($key, (string) json_encode($data), 'application/json');
    }

    public function putFile(string $key, string $localPath, string $contentType): void
    {
        $this->write($key, (string) file_get_contents($localPath), $contentType);
    }

    private function write(string $key, string $content, string $contentType): void
    {
        $version                     = 'v' . (++$this->versionSeq);
        $this->objects[$key]         = $content;
        $this->contentTypes[$key]    = $contentType;
        $this->versions[$key][$version] = $content;
        $this->latestVersion[$key]   = $version;
    }

    public function head(string $key): ?array
    {
        $this->maybeFail('head');
        if (! isset($this->objects[$key])) {
            return null;
        }

        $version = $this->latestVersion[$key] ?? null;

        return [
            'size'         => strlen($this->objects[$key]),
            'content_type' => $this->contentTypes[$key] ?? '',
            'version_id'   => $version,
            'etag'         => '"' . md5($this->objects[$key]) . '"',
        ];
    }

    public function readRange(string $key, int $offset, int $length, ?string $versionId = null): string
    {
        $this->maybeFail('readRange');
        $content = $versionId !== null ? ($this->versions[$key][$versionId] ?? '') : ($this->objects[$key] ?? '');

        return substr($content, $offset, $length);
    }

    public function presignPut(string $key, string $contentType, int $contentLength, int $ttlSeconds): array
    {
        if ($this->presignPutFails !== null) {
            throw $this->presignPutFails;
        }
        $this->presignedPuts[] = ['key' => $key, 'content_type' => $contentType, 'length' => $contentLength, 'ttl' => $ttlSeconds];

        return ['url' => "https://fake-s3.test/{$key}?sig=put", 'headers' => ['Content-Type' => $contentType]];
    }

    public function presignGet(string $key, int $ttlSeconds, string $contentType, string $disposition): string
    {
        $this->presignedGets[] = ['key' => $key, 'ttl' => $ttlSeconds, 'content_type' => $contentType, 'disposition' => $disposition];

        return "https://fake-s3.test/{$key}?sig=get";
    }

    /** @var list<array{from: string, to: string, version_id: ?string, content_type: ?string}> */
    public array $copies = [];

    public function copy(string $from, string $to, ?string $versionId = null, ?string $etag = null, ?string $contentType = null): void
    {
        $this->maybeFail('copy');
        if ($this->beforeCopy !== null) {
            ($this->beforeCopy)($this);
        }

        $this->copies[] = ['from' => $from, 'to' => $to, 'version_id' => $versionId, 'content_type' => $contentType];

        $content = $versionId !== null ? $this->versions[$from][$versionId] : $this->objects[$from];
        $this->write($to, $content, $contentType ?? ($this->contentTypes[$from] ?? ''));
    }

    public function delete(string $key): void
    {
        $this->deleteLog[] = $key;
        if (isset($this->deleteFailures[$key])) {
            throw $this->deleteFailures[$key];
        }
        $this->maybeFail('delete');
        unset($this->objects[$key], $this->contentTypes[$key]);
    }

    /** Simula el PUT directo del navegador a `_pending/` (lo que hace el front con la URL prefirmada). */
    public function simulateBrowserPut(string $key, string $content, string $contentType): void
    {
        $this->write($key, $content, $contentType);
    }
}
