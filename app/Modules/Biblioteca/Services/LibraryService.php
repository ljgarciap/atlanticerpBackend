<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Services;

use App\Models\User;
use App\Modules\Biblioteca\Contracts\LibraryStore;
use App\Modules\Biblioteca\Exceptions\LibraryFinalizeInProgressException;
use App\Modules\Biblioteca\Exceptions\LibraryObjectChangedException;
use App\Modules\Biblioteca\Exceptions\LibraryRejectedException;
use App\Modules\Biblioteca\Exceptions\LibraryStorageUnavailableException;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SCRUM-984 — biblioteca de manuales (PDF) y videos sobre el bucket compartido por dev/test/prod.
 *
 * SIN TABLA: el catálogo es el propio bucket. Cada archivo es `{modulo}/{tipo}/{uuid}.{ext}` más
 * un sidecar `{modulo}/{tipo}/{uuid}.json` con los metadatos; un objeto sin sidecar no existe
 * para el catálogo (así un `_pending/` sin finalize queda invisible). Las claves S3 las genera
 * SIEMPRE el servidor (uuid + extensión de la lista blanca); el nombre visible que escribe el
 * usuario vive solo en el sidecar, saneado, y jamás entra en una clave ni en una ruta.
 *
 * Subida en dos pasos (ver docs/architecture/biblioteca-manuales-s3-diseno.md §1):
 *   1) initUpload()     valida y emite un PUT prefirmado a `_pending/{uuid}.{ext}`;
 *   2) finalizeUpload() HeadObject + magic bytes, copia a la clave final y escribe el sidecar.
 */
final class LibraryService
{
    private const PENDING_PREFIX = '_pending/';
    private const CACHE_KEY      = 'biblioteca:catalog:v1:';
    private const UPLOAD_KEY     = 'biblioteca:upload:';
    private const USER_PENDING   = 'biblioteca:pending:';
    private const FINALIZE_LOCK  = 'biblioteca:finalize:';
    private const NAME_MAX       = 120;

    public function __construct(
        private readonly LibraryStore $store,
        private readonly LibrarySettings $settings,
        private readonly AuditService $audit,
        private readonly PdfActiveContentScanner $scanner,
    ) {}

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}>
     */
    public function list(?string $module, ?string $type): array
    {
        $items = $this->catalog($module);

        if ($type !== null) {
            $items = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === $type));
        }

        return $items;
    }

    /**
     * URL prefirmada para ver o descargar. Devuelve null si el archivo no existe.
     *
     * @return array{url: string, expires_at: string}|null
     */
    public function presignedUrl(string $id, string $action, User $actor): ?array
    {
        $item = $this->find($id, fresh: false);
        if ($item === null) {
            return null;
        }

        $ttl = $item['type'] === 'video' && $action === 'ver'
            ? $this->settings->int(LibrarySettings::TTL_VIDEO_SECONDS)
            : $this->settings->int(LibrarySettings::TTL_VIEW_SECONDS);

        // Content-Type SIEMPRE derivado de la extensión validada, nunca del valor almacenado.
        $mime = (string) config("biblioteca.extensions.{$item['ext']}.mime");
        $url  = $this->store->presignGet(
            $this->objectKey($item),
            $ttl,
            $mime,
            $action === 'descargar' ? $this->attachmentDisposition($item['name'], $item['ext']) : 'inline',
        );

        if ($action === 'descargar') {
            $this->audit->log(AuditService::BIBLIOTECA_FILE_DOWNLOADED, 'library_file', $id, null, null, [
                'module' => $item['module'], 'type' => $item['type'],
            ], $actor->id);
        }

        return ['url' => $url, 'expires_at' => now()->addSeconds($ttl)->toIso8601String()];
    }

    // ── Subida ────────────────────────────────────────────────────────────────

    /**
     * @return array{upload_id: string, url: string, headers: array<string, string>, expires_at: string}
     */
    public function initUpload(User $actor, string $module, string $name, string $filename, string $contentType, int $sizeBytes): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->assertModule($module);
        $meta = $this->assertExtension($ext);
        $this->assertMime($contentType, $meta['mime']);
        $this->assertSize($meta['type'], $sizeBytes);
        $cleanName = $this->sanitizeName($name);

        $uploadId = (string) Str::uuid();
        $ttl      = $this->settings->int(LibrarySettings::TTL_UPLOAD_SECONDS);

        // Reserva ATÓMICA del cupo (INCR y luego comparar): dos solicitudes paralelas no pueden pasar el máximo.
        $this->reservePending($actor->id, $ttl + 600);

        try {
            $presigned = $this->store->presignPut(
                self::PENDING_PREFIX . "{$uploadId}.{$ext}",
                $meta['mime'],
                $sizeBytes,
                $ttl,
            );
        } catch (\Throwable $e) {
            $this->releasePending($actor->id);
            throw $e;
        }

        // Solo el finalize de ESTE usuario y ESTE uuid puede publicar lo subido (no se aceptan claves ajenas).
        Cache::put(self::UPLOAD_KEY . $uploadId, [
            'user_id' => $actor->id, 'module' => $module, 'type' => $meta['type'], 'ext' => $ext,
            'mime' => $meta['mime'], 'size' => $sizeBytes, 'name' => $cleanName,
        ], $ttl + 600);

        return [
            'upload_id'  => $uploadId,
            'url'        => $presigned['url'],
            'headers'    => $presigned['headers'],
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}
     *
     * @throws LibraryRejectedException si lo subido no coincide con lo declarado (el pending se borra)
     */
    public function finalizeUpload(User $actor, string $uploadId): array
    {
        /** @var array{user_id: int, module: string, type: string, ext: string, mime: string, size: int, name: string}|null $upload */
        $upload = Cache::get(self::UPLOAD_KEY . $uploadId);
        if ($upload === null || $upload['user_id'] !== $actor->id) {
            throw new \DomainException('upload_not_found');
        }

        // Exclusión mutua por upload_id: un finalize doble/concurrente no publica dos veces ni libera el cupo dos veces.
        $lock = Cache::lock(self::FINALIZE_LOCK . $uploadId, 600);
        if (! $lock->get()) {
            throw new LibraryFinalizeInProgressException(trans('biblioteca.finalize_in_progress'));
        }

        try {
            return $this->finalizeLocked($actor, $uploadId, $upload);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{user_id: int, module: string, type: string, ext: string, mime: string, size: int, name: string} $upload
     * @return array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}
     */
    private function finalizeLocked(User $actor, string $uploadId, array $upload): array
    {
        $pendingKey = self::PENDING_PREFIX . "{$uploadId}.{$upload['ext']}";

        try {
            return $this->publishPending($actor, $uploadId, $upload, $pendingKey);
        } catch (LibraryObjectChangedException $e) {
            // La versión validada ya no existe o cambió antes de copiarla: no se puede publicar tal cual.
            // Se limpia el pending y se libera el cupo; el usuario debe subir de nuevo.
            $this->rejectUpload($actor, $uploadId, $pendingKey, $upload, 'object_changed:' . $e->getMessage());
            throw new LibraryRejectedException(trans('biblioteca.rejected_changed'), 0, $e);
        } catch (LibraryStorageUnavailableException $e) {
            // AccessDenied/5xx/timeout: nada se descarta. El upload sigue registrado (con su cupo) hasta su TTL,
            // así que el cliente puede reintentar el finalize cuando el almacenamiento responda.
            $this->logStorageFailure('biblioteca.storage_unavailable', $e, ['upload_id' => $uploadId, 'user_id' => $actor->id, 'operation' => 'finalize']);
            $this->auditRejection($actor, $uploadId, $upload, 'storage_unavailable:' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array{user_id: int, module: string, type: string, ext: string, mime: string, size: int, name: string} $upload
     * @return array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}
     */
    private function publishPending(User $actor, string $uploadId, array $upload, string $pendingKey): array
    {
        $head = $this->store->head($pendingKey);
        if ($head === null) {
            throw new LibraryRejectedException(trans('biblioteca.rejected_missing'));
        }

        // El tope real no depende del cliente: se re-verifica con el tamaño que S3 tiene de verdad.
        if ($head['size'] !== $upload['size'] || $head['size'] > $this->settings->maxBytesFor($upload['type'])) {
            $this->rejectUpload($actor, $uploadId, $pendingKey, $upload, 'size');
            throw new LibraryRejectedException(trans('biblioteca.rejected_size'));
        }

        // Todo lo que sigue (magic bytes, escaneo, copia) queda atado a ESTA versión: si el cliente
        // re-sube la clave después de validar (la URL de PUT sigue vigente), lo nuevo no se publica (M-2).
        $version = $head['version_id'];
        $reader  = fn (int $offset, int $length): string => $this->store->readRange($pendingKey, $offset, $length, $version);

        if (! $this->magicBytesMatch($upload['ext'], $reader(0, 16))) {
            $this->rejectUpload($actor, $uploadId, $pendingKey, $upload, 'magic_bytes');
            throw new LibraryRejectedException(trans('biblioteca.rejected_type'));
        }

        if ($upload['ext'] === 'pdf') {
            $found = $this->scanner->scan($reader, $head['size']);
            if ($found !== null) {
                $this->rejectUpload($actor, $uploadId, $pendingKey, $upload, 'pdf_scan:' . $found);
                throw new LibraryRejectedException($this->scanRejectionMessage($found));
            }
        }

        $item = [
            'id' => $uploadId, 'name' => $upload['name'], 'module' => $upload['module'],
            'type' => $upload['type'], 'ext' => $upload['ext'], 'size_bytes' => $head['size'],
            'uploaded_by' => $actor->id, 'created_at' => now()->toIso8601String(),
        ];

        $finalKey = $this->objectKey($item);
        // Content-Type canónico de la extensión validada, no el que eligió el cliente en el PUT (B-3).
        $this->store->copy($pendingKey, $finalKey, $version, $head['etag'], $upload['mime']);

        $copied = $this->store->head($finalKey);
        if ($copied === null || $copied['size'] !== $head['size']) {
            $this->store->delete($finalKey);
            $this->rejectUpload($actor, $uploadId, $pendingKey, $upload, 'copy_size');
            throw new LibraryRejectedException(trans('biblioteca.rejected_size'));
        }

        try {
            $this->store->putJson($this->sidecarKey($item), $item);
        } catch (\Throwable $e) {
            $this->store->delete($finalKey); // sin sidecar el objeto sería basura invisible
            throw $e;
        }

        // Ya publicado: que el pending no se pueda borrar no debe fallar la subida (el lifecycle expira el objeto de `_pending/` a los 2 días).
        $this->deletePendingQuietly($pendingKey);

        $this->forgetUpload($uploadId, $actor->id);
        $this->invalidateCatalog();

        $this->audit->log(AuditService::BIBLIOTECA_FILE_UPLOADED, 'library_file', $uploadId, null, [
            'name' => $item['name'], 'module' => $item['module'], 'type' => $item['type'], 'size_bytes' => $item['size_bytes'],
        ], null, $actor->id);

        return $this->publicShape($item);
    }

    // ── Carga inicial por comando (B5) ────────────────────────────────────────

    /**
     * Importa un archivo local al catálogo (biblioteca:seed-manuales). Idempotente: si ya hay un
     * archivo con el mismo módulo + nombre visible + tipo, no hace nada y devuelve null.
     *
     * @return array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}|null
     */
    public function importLocalFile(string $path, string $module, string $name, ?User $actor): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $this->assertModule($module);
        $meta      = $this->assertExtension($ext);
        $size      = (int) filesize($path);
        $cleanName = $this->sanitizeName($name);
        $this->assertSize($meta['type'], $size);

        $handle = fopen($path, 'rb');
        $head   = $handle !== false ? (string) fread($handle, 16) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if (! $this->magicBytesMatch($ext, $head)) {
            throw new LibraryRejectedException(trans('biblioteca.rejected_type'));
        }
        if ($ext === 'pdf') {
            $found = $this->scanner->scan($this->localReader($path), $size);
            if ($found !== null) {
                throw new LibraryRejectedException($this->scanRejectionMessage($found));
            }
        }

        foreach ($this->readSidecars($module) as $existing) {
            if ($existing['name'] === $cleanName && $existing['type'] === $meta['type']) {
                return null;
            }
        }

        $item = [
            'id' => (string) Str::uuid(), 'name' => $cleanName, 'module' => $module,
            'type' => $meta['type'], 'ext' => $ext, 'size_bytes' => $size,
            'uploaded_by' => $actor?->id, 'created_at' => now()->toIso8601String(),
        ];

        $this->store->putFile($this->objectKey($item), $path, $meta['mime']);
        $this->store->putJson($this->sidecarKey($item), $item);
        $this->invalidateCatalog();

        $this->audit->log(AuditService::BIBLIOTECA_FILE_UPLOADED, 'library_file', $item['id'], null, [
            'name' => $cleanName, 'module' => $module, 'type' => $meta['type'], 'size_bytes' => $size, 'source' => 'seed-command',
        ], null, $actor?->id);

        return $this->publicShape($item);
    }

    // ── Borrado (lógico: con versionado solo crea delete markers) ─────────────

    /**
     * @throws LibraryStorageUnavailableException|LibraryObjectChangedException si S3 falla (el controlador responde 503)
     */
    public function delete(string $id, User $actor): bool
    {
        $item = $this->find($id, fresh: true);
        if ($item === null) {
            return false;
        }

        $context = ['file_id' => $id, 'user_id' => $actor->id, 'operation' => 'delete'];

        // Primero el SIDECAR (es lo que define qué existe para el catálogo) y después el objeto: un fallo a
        // medias deja un objeto huérfano e invisible (inocuo), nunca un ítem visible que apunta a un objeto
        // ya borrado. Si falla el primer borrado no pasó nada: catálogo intacto y sin auditar.
        try {
            $this->store->delete($this->sidecarKey($item));
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException $e) {
            $this->logStorageFailure('biblioteca.storage_unavailable', $e, $context + ['step' => 'sidecar']);
            throw $e;
        }

        // El ítem ya no existe para el catálogo: la caché se invalida pase lo que pase con el objeto.
        $this->invalidateCatalog();

        $objectFailure = null;
        try {
            $this->store->delete($this->objectKey($item));
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException $e) {
            $objectFailure = $e;
            $this->logStorageFailure('biblioteca.orphan_object', $e, $context + ['step' => 'object', 'key' => $this->objectKey($item)]);
        }

        $this->audit->log(AuditService::BIBLIOTECA_FILE_DELETED, 'library_file', $id, [
            'name' => $item['name'], 'module' => $item['module'], 'type' => $item['type'],
        ], $objectFailure === null ? null : ['object_delete_failed' => true], null, $actor->id);

        if ($objectFailure !== null) {
            throw $objectFailure; // 503: el borrado quedó a medias (objeto huérfano); reintentar da 404 porque el ítem ya no está
        }

        return true;
    }

    // ── Catálogo ──────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}>
     */
    private function catalog(?string $module): array
    {
        $ttl = (int) config('biblioteca.catalog_cache_seconds', 60);

        /** @var list<array<string, mixed>> $raw */
        $raw = Cache::remember(
            self::CACHE_KEY . ($module ?? 'all'),
            $ttl,
            fn (): array => $this->readSidecars($module),
        );

        return array_map(fn (array $i): array => $this->publicShape($i), $raw);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readSidecars(?string $module): array
    {
        $sidecarKeys = array_values(array_filter(
            $this->store->listKeys($module !== null ? "{$module}/" : ''),
            static fn (string $key): bool => str_ends_with($key, '.json') && ! str_starts_with($key, self::PENDING_PREFIX),
        ));

        $items = [];
        // Un solo pase (en paralelo en S3) en vez de un GetObject secuencial por sidecar.
        foreach ($this->store->getJsonMany($sidecarKeys) as $sidecar) {
            if ($sidecar === null || ! $this->isValidSidecar($sidecar)) {
                continue;
            }
            // El sidecar puede venir de otro entorno: el nombre se re-sanea al leer, nunca se confía tal cual.
            try {
                $sidecar['name'] = $this->sanitizeName((string) $sidecar['name']);
            } catch (ValidationException) {
                continue;
            }
            $items[] = $sidecar;
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $items;
    }

    /**
     * @return array<string, mixed>|null el sidecar completo (incluye ext)
     */
    private function find(string $id, bool $fresh): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $fresh
            ? $this->readSidecars(null)
            : Cache::remember(self::CACHE_KEY . 'all', (int) config('biblioteca.catalog_cache_seconds', 60), fn (): array => $this->readSidecars(null));

        foreach ($raw as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $s */
    private function isValidSidecar(array $s): bool
    {
        return isset($s['id'], $s['name'], $s['module'], $s['type'], $s['ext'], $s['size_bytes'], $s['created_at'])
            && is_string($s['id']) && Str::isUuid($s['id'])
            && in_array($s['module'], (array) config('biblioteca.modules'), true)
            && array_key_exists((string) $s['ext'], (array) config('biblioteca.extensions'))
            && in_array($s['type'], ['pdf', 'video'], true)
            && $s['type'] === config("biblioteca.extensions.{$s['ext']}.type");
    }

    /**
     * @param  array<string, mixed> $item
     * @return array{id: string, name: string, module: string, type: string, size_bytes: int, created_at: string}
     */
    private function publicShape(array $item): array
    {
        return [
            'id'         => (string) $item['id'],
            'name'       => (string) $item['name'],
            'module'     => (string) $item['module'],
            'type'       => (string) $item['type'],
            'size_bytes' => (int) $item['size_bytes'],
            'created_at' => (string) $item['created_at'],
        ];
    }

    /** @param array<string, mixed> $item */
    private function objectKey(array $item): string
    {
        return "{$item['module']}/{$item['type']}/{$item['id']}.{$item['ext']}";
    }

    /** @param array<string, mixed> $item */
    private function sidecarKey(array $item): string
    {
        return "{$item['module']}/{$item['type']}/{$item['id']}.json";
    }

    private function invalidateCatalog(): void
    {
        Cache::forget(self::CACHE_KEY . 'all');
        foreach ((array) config('biblioteca.modules') as $module) {
            Cache::forget(self::CACHE_KEY . $module);
        }
    }

    // ── Validación ────────────────────────────────────────────────────────────

    private function assertModule(string $module): void
    {
        if (! in_array($module, (array) config('biblioteca.modules'), true)) {
            throw ValidationException::withMessages(['module' => [trans('validation.in', ['attribute' => 'module'])]]);
        }
    }

    /** @return array{type: string, mime: string} */
    private function assertExtension(string $ext): array
    {
        $allowed = $this->settings->allowedExtensions();
        $known   = (array) config('biblioteca.extensions');

        if (! in_array($ext, $allowed, true) || ! isset($known[$ext])) {
            throw ValidationException::withMessages(['file' => [trans('biblioteca.extension_denied', ['allowed' => implode(', ', $allowed)])]]);
        }

        /** @var array{type: string, mime: string} $meta */
        $meta = $known[$ext];

        return $meta;
    }

    private function assertMime(string $declared, string $expected): void
    {
        if (strtolower(trim($declared)) !== $expected) {
            throw ValidationException::withMessages(['content_type' => [trans('biblioteca.mime_mismatch')]]);
        }
    }

    private function assertSize(string $type, int $sizeBytes): void
    {
        $max = $this->settings->maxBytesFor($type);
        if ($sizeBytes < 1 || $sizeBytes > $max) {
            throw ValidationException::withMessages(['size_bytes' => [trans('biblioteca.too_large', ['max' => (string) intdiv($max, 1024 * 1024)])]]);
        }
    }

    /**
     * El nombre visible vive solo en el sidecar: se sanea (caracteres de control, nulos, bidi,
     * separadores de ruta, `..`, longitud) y aun así se escapa al renderizar y en Content-Disposition.
     */
    public function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{2028}\x{2029}\x{200B}-\x{200D}\x{FEFF}]/u', '', $name) ?? '';
        $clean = str_replace(['/', '\\', '..'], ' ', $clean);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $clean = mb_substr($clean, 0, self::NAME_MAX);

        if ($clean === '') {
            throw ValidationException::withMessages(['name' => [trans('biblioteca.invalid_name')]]);
        }

        return $clean;
    }

    private function attachmentDisposition(string $name, string $ext): string
    {
        $file  = $name . '.' . $ext;
        // /u: un carácter multibyte (ñ, tildes) da UN guion bajo, no uno por byte; el nombre completo va en filename*=UTF-8''.
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $file) ?? 'archivo';

        return 'attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($file);
    }

    private function magicBytesMatch(string $ext, string $head): bool
    {
        return match ($ext) {
            'pdf'  => str_starts_with($head, '%PDF-'),
            'mp4'  => strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp',
            'webm' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            default => false,
        };
    }

    // ── Cuota de pendientes por usuario ──────────────────────────────────────

    /** INCR atómico y luego se compara: si se pasó del máximo, se deshace y se rechaza. */
    private function reservePending(int $userId, int $ttl): void
    {
        $key = self::USER_PENDING . $userId;
        Cache::add($key, 0, $ttl);

        if ((int) Cache::increment($key) > $this->settings->int(LibrarySettings::MAX_PENDING_UPLOADS)) {
            Cache::decrement($key);
            throw ValidationException::withMessages(['file' => [trans('biblioteca.too_many_pending')]]);
        }
    }

    private function releasePending(int $userId): void
    {
        $key = self::USER_PENDING . $userId;
        if ((int) Cache::get($key, 0) > 0) {
            Cache::decrement($key);
        }
    }

    /** Solo libera el cupo si el upload seguía registrado (pull): un finalize repetido no decrementa dos veces. */
    private function forgetUpload(string $uploadId, int $userId): void
    {
        if (Cache::pull(self::UPLOAD_KEY . $uploadId) !== null) {
            $this->releasePending($userId);
        }
    }

    private function discardPending(string $uploadId, string $pendingKey, int $userId): void
    {
        $this->deletePendingQuietly($pendingKey);
        $this->forgetUpload($uploadId, $userId);
    }

    /** Borra el pending sin propagar fallos de S3: un rechazo o una publicación no deben depender de esta limpieza (lo expira el lifecycle). */
    private function deletePendingQuietly(string $pendingKey): void
    {
        try {
            $this->store->delete($pendingKey);
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException $e) {
            // No se propaga: el objeto sin sidecar es invisible y el lifecycle lo expira a los 2 días (regla
            // `pending-staging-2d`). Se deja rastro para el operador: un AccessDenied repetido aquí es una política IAM mal aplicada.
            $this->logStorageFailure('biblioteca.pending_cleanup_failed', $e, ['key' => $pendingKey, 'operation' => 'cleanup'], 'warning');
        }
    }

    /**
     * @param  array{user_id: int, module: string, type: string, ext: string, mime: string, size: int, name: string} $upload
     */
    private function rejectUpload(User $actor, string $uploadId, string $pendingKey, array $upload, string $reason): void
    {
        $this->discardPending($uploadId, $pendingKey, $actor->id);
        $this->auditRejection($actor, $uploadId, $upload, $reason);
    }

    /** @param array{user_id: int, module: string, type: string, ext: string, mime: string, size: int, name: string} $upload */
    private function auditRejection(User $actor, string $uploadId, array $upload, string $reason): void
    {
        $this->audit->log(AuditService::BIBLIOTECA_FILE_REJECTED, 'library_file', $uploadId, null, [
            'module' => $upload['module'], 'type' => $upload['type'], 'size_bytes' => $upload['size'], 'reason' => $reason,
        ], null, $actor->id);
    }

    /**
     * Log con contexto para el operador (CloudWatch/laravel.log) cuando S3 falla. Solo código AWS, estado HTTP e
     * id de petición: nunca credenciales, URLs prefirmadas ni el cuerpo de la respuesta.
     *
     * @param array<string, mixed> $context
     */
    private function logStorageFailure(string $event, \Throwable $e, array $context, string $level = 'error'): void
    {
        $previous = $e->getPrevious();
        $context += [
            'aws_code'       => $e->getMessage(),
            'http_status'    => $previous instanceof \Aws\Exception\AwsException ? $previous->getStatusCode() : null,
            'aws_request_id' => $previous instanceof \Aws\Exception\AwsException ? $previous->getAwsRequestId() : null,
        ];

        $level === 'warning' ? Log::warning($event, $context) : Log::error($event, $context);
    }

    private function scanRejectionMessage(string $found): string
    {
        return in_array($found, [PdfActiveContentScanner::TOO_LARGE, PdfActiveContentScanner::SCAN_ERROR], true)
            ? trans('biblioteca.rejected_unscannable')
            : trans('biblioteca.rejected_active_content');
    }

    /** @return \Closure(int, int): string */
    private function localReader(string $path): \Closure
    {
        return static function (int $offset, int $length) use ($path): string {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return '';
            }
            fseek($handle, $offset);
            $data = (string) fread($handle, max(1, $length));
            fclose($handle);

            return $data;
        };
    }
}
