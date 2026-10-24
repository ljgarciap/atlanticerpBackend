<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\CRM\Services\CrmSettingsService;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Jobs\GenerateCatalogPdfVolumesJob;
use App\Modules\VentasDiseno\Models\CatalogPdfExport;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * SCRUM-998 (auditoría SCRUM-995, sitio E; docs/specs/scrum998-catalog-pdf-background.md) — PDF de Catálogo demasiado
 * grande para una petición (más de `catalog_pdf_max_single_products` productos, configurable): se genera en segundo
 * plano como un ZIP de volúmenes (un PDF por tanda de `catalog_pdf_volume_size` productos) que se guarda en S3
 * privado y se entrega con una presigned URL de 15 min.
 *
 * Medido en dev con el catálogo real: 500 productos = 6,1 s y 84 MB con dompdf; 2.000 = 45 s y 299 MB; ~30K serían ~5.000
 * páginas en un solo documento, inviable. Por eso se parte en volúmenes con el MISMO `CatalogPdfService` (mismo layout y
 * misma RN1: nunca stock/costo/margen) y se lee el catálogo por páginas (`forPage`), nunca `get()->all()`.
 *
 * Idempotente: `run()` toma la exportación con un `UPDATE ... WHERE status = 'queued'` atómico, así que un job repetido
 * (o liberado por `retry_after`) sobre una exportación ya tomada o terminada no hace nada. Cualquier fallo deja la fila en
 * `failed` con un mensaje seguro (sin trazas) y avisa igual: nunca queda un enlace roto ni una exportación colgada.
 */
final class CatalogPdfExportService
{
    private const DISK = 's3-private';

    private const PRESIGNED_URL_TTL_MINUTES = 15;

    /** Mensaje que ve el usuario si el job falla (el detalle real queda solo en el log). */
    public const FAILURE_MESSAGE = 'No se pudo generar el catálogo. Intenta de nuevo; si el problema sigue, avisa a soporte.';

    public function __construct(
        private readonly CatalogPdfService $pdf,
        private readonly CrmSettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Crea la exportación y encola el job. `$total` ya lo contó el llamador (`CatalogPdfService::countProducts()`).
     *
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds Solo se guarda en `seleccionados`.
     */
    public function start(User $user, string $mode, array $productIds, int $total): CatalogPdfExport
    {
        $volumeSize = $this->settings->catalogPdfVolumeSize();

        $export = CatalogPdfExport::create([
            'uuid'           => (string) Str::uuid(),
            'requested_by'   => $user->id,
            'mode'           => $mode,
            'product_ids'    => $mode === CatalogPdfExport::MODE_SELECCIONADOS ? array_values($productIds) : null,
            'total_products' => $total,
            'volume_size'    => $volumeSize,
            'volumes'        => max(1, (int) ceil($total / $volumeSize)),
            'status'         => CatalogPdfExport::STATUS_QUEUED,
        ]);

        $tenant = Tenant::current();
        GenerateCatalogPdfVolumesJob::dispatch($export->id, (string) $tenant?->slug);

        return $export;
    }

    /** Ejecuta la exportación (llamado por el job, con el tenant ya activo). */
    public function run(int $exportId): void
    {
        /** @var CatalogPdfExport|null $export */
        $export = CatalogPdfExport::find($exportId);
        if ($export === null) {
            return;
        }

        // Toma atómica: solo UNA ejecución pasa de queued a running; cualquier otra sale sin tocar nada.
        $claimed = CatalogPdfExport::where('id', $exportId)
            ->where('status', CatalogPdfExport::STATUS_QUEUED)
            ->update(['status' => CatalogPdfExport::STATUS_RUNNING, 'started_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $export->refresh();
        $dir = sys_get_temp_dir().'/catalog-pdf-'.$export->uuid;
        $key = $this->storageKey($export);
        $uploaded = false;

        try {
            File::ensureDirectoryExists($dir);
            $files = $this->generateVolumes($export, $dir);

            $zipPath = "{$dir}/catalogo.zip";
            $this->zip($files, $zipPath);
            // Los PDF sueltos ya están dentro del ZIP: se liberan antes de subirlo (con fotos son cientos de MB en disco temporal).
            foreach ($files as $file) {
                @unlink($file);
            }

            (new CircuitBreaker(self::DISK))->call(function () use ($key, $zipPath): void {
                $stream = fopen($zipPath, 'rb');
                if ($stream === false || Storage::disk(self::DISK)->put($key, $stream) === false) {
                    throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
                }
                if (is_resource($stream)) {
                    fclose($stream);
                }
            });
            $uploaded = true;

            $export->update([
                'status'      => CatalogPdfExport::STATUS_DONE,
                'storage_key' => $key,
                'size_bytes'  => (int) filesize($zipPath),
                'finished_at' => now(),
                'expires_at'  => now()->addDays($this->settings->catalogPdfExportRetentionDays()),
            ]);

            $this->notify($export, ready: true);
        } catch (\Throwable $e) {
            $this->fail($export, $e, $uploaded ? $key : null);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Marca la exportación como fallida (también lo llama `GenerateCatalogPdfVolumesJob::failed()` si Horizon mata el job por
     * timeout) y avisa al solicitante con un mensaje seguro. No pisa una exportación ya terminada.
     */
    public function fail(CatalogPdfExport $export, \Throwable $e, ?string $uploadedKey = null): void
    {
        Log::error('catalog_pdf_export.failed', [
            'export_id' => $export->id, 'uuid' => $export->uuid, 'error' => $e->getMessage(), 'class' => $e::class,
        ]);

        $updated = CatalogPdfExport::where('id', $export->id)
            ->whereIn('status', [CatalogPdfExport::STATUS_QUEUED, CatalogPdfExport::STATUS_RUNNING])
            ->update([
                'status'        => CatalogPdfExport::STATUS_FAILED,
                'error_message' => self::FAILURE_MESSAGE,
                'finished_at'   => now(),
            ]);

        if ($uploadedKey !== null) {
            try {
                Storage::disk(self::DISK)->delete($uploadedKey);
            } catch (\Throwable $se) {
                Log::error('catalog_pdf_export.storage_cleanup_failed', ['key' => $uploadedKey, 'error' => $se->getMessage()]);
            }
        }

        if ($updated > 0) {
            $export->refresh();
            $this->notify($export, ready: false);
        }
    }

    /** Presigned URL de 15 min del ZIP, o `null` si la exportación no está lista (o ya venció y se purgó). */
    public function downloadUrl(CatalogPdfExport $export): ?string
    {
        if ($export->status !== CatalogPdfExport::STATUS_DONE || $export->storage_key === null) {
            return null;
        }
        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl($export->storage_key, now()->addMinutes(self::PRESIGNED_URL_TTL_MINUTES));
    }

    /**
     * Borra los ZIP vencidos (S3 y fila) según `catalog_pdf_export_retention_days`; devuelve cuántos.
     * También cierra como `failed` las exportaciones que llevan horas en `queued`/`running` sin terminar (job perdido).
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        CatalogPdfExport::whereNotNull('expires_at')->where('expires_at', '<', now())->orderBy('id')->each(function (CatalogPdfExport $export) use (&$purged): void {
            if ($export->storage_key !== null) {
                try {
                    Storage::disk(self::DISK)->delete($export->storage_key);
                } catch (\Throwable $e) {
                    Log::error('catalog_pdf_export.purge_storage_failed', ['export_id' => $export->id, 'error' => $e->getMessage()]);

                    return; // no se borra la fila si el archivo sigue en S3: se reintenta en la próxima corrida
                }
            }
            $export->delete();
            $purged++;
        });

        CatalogPdfExport::whereIn('status', [CatalogPdfExport::STATUS_QUEUED, CatalogPdfExport::STATUS_RUNNING])
            ->where('created_at', '<', now()->subHours(6))
            ->orderBy('id')
            ->each(fn (CatalogPdfExport $export) => $this->fail($export, new \RuntimeException('exportación sin terminar tras 6 horas')));

        return $purged;
    }

    /**
     * @return list<string> rutas de los PDF generados (un archivo por volumen), en orden
     */
    private function generateVolumes(CatalogPdfExport $export, string $dir): array
    {
        /** @var 'completo'|'seleccionados' $mode */
        $mode = $export->mode;
        $ids = $export->product_ids ?? [];
        $date = now()->format('Y-m-d');
        $files = [];

        for ($volume = 1; $volume <= $export->volumes; $volume++) {
            $pdf = $this->pdf->generateVolume($mode, $ids, $volume, $export->volumes, $export->volume_size);
            $path = sprintf('%s/Catalogo-Atlantic-%s-vol-%02d-de-%02d.pdf', $dir, $date, $volume, $export->volumes);
            file_put_contents($path, $pdf->output());
            $files[] = $path;
            unset($pdf);
            gc_collect_cycles();
        }

        return $files;
    }

    /** @param list<string> $files */
    private function zip(array $files, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el ZIP del catálogo.');
        }
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
            $zip->setCompressionName(basename($file), ZipArchive::CM_STORE); // un PDF ya viene comprimido: no gastar CPU
        }
        if (! $zip->close()) {
            throw new \RuntimeException('No se pudo cerrar el ZIP del catálogo.');
        }
    }

    /** Convención `<modulo>/<entidad>/<uuid>.<ext>` (atlanticerp/CLAUDE.md), con el uuid de la exportación. */
    private function storageKey(CatalogPdfExport $export): string
    {
        return "ventas-diseno/catalog-exports/{$export->uuid}/Catalogo-Atlantic-".now()->format('Y-m-d').'.zip';
    }

    private function notify(CatalogPdfExport $export, bool $ready): void
    {
        try {
            $this->notifications->send(
                audience: [$export->requested_by],
                content: [
                    'subject'  => $ready ? 'Tu catálogo en PDF está listo' : 'No se pudo generar tu catálogo en PDF',
                    'body'     => $ready
                        ? "El catálogo ({$export->total_products} productos, {$export->volumes} volúmenes en un ZIP) ya se puede descargar. El enlace vence en {$this->settings->catalogPdfExportRetentionDays()} días."
                        : (string) $export->error_message,
                    'cta_url'  => '/ventas-diseno/catalog?export='.$export->uuid,
                ],
                channels: [NotificationSend::CHANNEL_IN_APP],
                source: NotificationSend::SOURCE_CATALOG_PDF_EXPORT,
                sourceRef: $export->uuid,
                createdBy: $export->requested_by,
            );
        } catch (\Throwable $e) {
            // El aviso nunca debe tumbar la exportación ya hecha: el estado sigue consultable por GET.
            Log::error('catalog_pdf_export.notify_failed', ['export_id' => $export->id, 'error' => $e->getMessage()]);
        }
    }
}
