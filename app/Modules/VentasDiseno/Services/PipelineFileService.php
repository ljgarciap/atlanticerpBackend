<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardFile;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-052: PNG/JPG/PDF, sin límite de tamaño, retención permanente (nunca se borran) —
 * sin versionado, a diferencia de crm.project_documents (DocumentService).
 */
final class PipelineFileService
{
    private const DISK = 's3-private';

    /** SCRUM-767 — misma TTL que `App\Modules\CRM\Services\DocumentService::PRESIGNED_URL_TTL`. */
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, PipelineCard $card, string $type, int $uploadedBy): PipelineCardFile
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "ventas-diseno/{$card->id}/{$type}/".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($card, $type, $file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(
                "ventas-diseno/{$card->id}/{$type}",
                $file,
                basename($key),
            );

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $card->files()->create([
            'type' => $type,
            'file_name' => $file->getClientOriginalName(),
            'storage_key' => $key,
            'size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Quita un archivo del pipeline: primero la fila (así nunca queda una fila apuntando a un objeto ya borrado) y luego
     * el objeto de S3, que es de mejor esfuerzo: si el storage falla (o su circuit breaker está abierto) el error se
     * registra y la fila ya no existe; el objeto huérfano queda para una limpieza posterior. Las reglas de negocio
     * (etapa del proyecto, tipos protegidos) las aplica el controlador, no este servicio.
     */
    public function delete(PipelineCardFile $file): void
    {
        $storageKey = $file->storage_key;

        $file->delete();

        try {
            (new CircuitBreaker(self::DISK))->call(fn () => Storage::disk(self::DISK)->delete($storageKey));
        } catch (\Throwable $e) {
            Log::error('pipeline_file_service.storage_delete_failed', [
                'storage_key' => $storageKey,
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SCRUM-767 — URL presignada de 15 minutos para ver/descargar un archivo ya guardado. Mismo
     * patrón que `App\Modules\CRM\Services\DocumentService::getPresignedUrl()`, sin versionado
     * (este módulo nunca lo tuvo, ver docblock de la clase) — con una corrección real encontrada
     * en Pre-QA (2026-08-15, verificado con Playwright contra S3 real): sin `ResponseContentDisposition`
     * explícito, un `<a download>` hacia una URL cross-origin (el propio bucket S3) no controla el
     * nombre de archivo — el navegador termina guardando el archivo con la KEY interna de S3 (un
     * UUID, `PipelineFileService::upload()`), no `file_name`. REQ-248/AC ("la descarga debe
     * conservar el nombre del archivo") lo exige explícitamente. `inline` (para el visor embebido,
     * REQ-248 previsualización) y `attachment` (para "Descargar") necesitan valores DISTINTOS —
     * pedir `attachment` para el visor forzaría una descarga en vez de renderizar el PDF/imagen
     * dentro del iframe.
     *
     * Segunda corrección real (rebote de Gerencia Test 2026-08-16, SCRUM-767): S3 rechaza
     * `ResponseContentDisposition` con `InvalidArgument — Header value cannot be represented using
     * ISO-8859-1` para nombres de archivo con cualquier carácter fuera de Latin-1 — el caso real
     * que lo disparó fue una captura de pantalla de macOS ("... 2.35.19 p.m..png"), cuyo nombre
     * autogenerado trae un espacio angosto Unicode (U+202F) entre la hora y "p.m.", invisible a
     * simple vista pero fuera de ISO-8859-1. Quitar solo comillas (fix anterior) no alcanza para
     * el caso general. Ahora se arma el header con doble parámetro RFC 6266: `filename="..."`
     * (fallback ASCII, translitera/descarta lo que no entre) + `filename*=UTF-8''...` (nombre real
     * completo, percent-encoded — navegadores modernos lo prefieren sobre el fallback). El string
     * final es siempre ASCII puro, así que nunca vuelve a chocar con la validación de S3, sea cual
     * sea el charset del nombre original.
     *
     * @param  'inline'|'attachment'  $disposition
     */
    public function getPresignedUrl(PipelineCardFile $file, string $disposition = 'inline'): string
    {
        $filename = str_replace(['"', '\\'], '', $file->file_name);

        $asciiFallback = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        if ($asciiFallback === false || trim($asciiFallback) === '') {
            $asciiFallback = 'archivo';
        }

        $contentDisposition = "{$disposition}; filename=\"{$asciiFallback}\"; filename*=UTF-8''".rawurlencode($filename);

        return Storage::disk(self::DISK)->temporaryUrl(
            $file->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
            ['ResponseContentDisposition' => $contentDisposition],
        );
    }
}
