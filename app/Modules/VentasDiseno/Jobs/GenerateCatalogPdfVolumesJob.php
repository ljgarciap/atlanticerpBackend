<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Jobs;

use App\Shared\Support\MemoryLimit;
use App\Modules\VentasDiseno\Models\CatalogPdfExport;
use App\Modules\VentasDiseno\Services\CatalogPdfExportService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SCRUM-998 — genera en segundo plano el ZIP de volúmenes del Catálogo (ver `CatalogPdfExportService` y
 * docs/specs/scrum998-catalog-pdf-background.md). Vive en su propia conexión/cola (`redis-long` / `pdf`) porque tarda
 * minutos (unos 60 volúmenes con el catálogo completo) y la conexión `redis` normal tiene `retry_after` = 90 s: un job
 * de esa duración se liberaría a otro worker mientras sigue corriendo. Con `redis-long` (`retry_after` mayor que el
 * `$timeout`) eso no pasa, y la toma atómica de `run()` cubre el resto.
 *
 * `$tries = 1`: el estado vive en `catalog_pdf_exports` (queued → running → done|failed) y un fallo se le avisa al usuario;
 * reintentar a ciegas repetiría minutos de trabajo. Igual que `SyncProductoFotoJob`, recibe el slug del tenant y lo activa
 * él mismo (no depende de que el dispatch lo haya inyectado).
 */
final class GenerateCatalogPdfVolumesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CONNECTION = 'redis-long';

    public const QUEUE = 'pdf';

    public int $tries = 1;

    /**
     * 2 horas. Medido en dev con el catálogo completo (29.630 productos): 60 volúmenes en ~8 min sin fotos; con las ~10,9 mil fotos
     * remotas se suman ~15 min más (83 ms/foto con 20 en paralelo). El margen cubre un proveedor de fotos varias veces más lento.
     */
    public int $timeout = 7200;

    public function __construct(
        private readonly int $exportId,
        private readonly string $tenantSlug,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function handle(CatalogPdfExportService $service): void
    {
        // Mismo criterio que `SyncProductoFotoJob`: el worker de Horizon arranca con el `memory_limit` del contenedor.
        MemoryLimit::atLeast('768M');

        $tenant = Tenant::where('slug', $this->tenantSlug)->first();
        if ($tenant === null) {
            return;
        }
        $tenant->makeCurrent();

        $service->run($this->exportId);
    }

    /** Horizon lo mata por timeout o revienta fuera de `run()`: la exportación no puede quedar colgada en `running`. */
    public function failed(\Throwable $exception): void
    {
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();
        if ($tenant === null) {
            return;
        }
        $tenant->makeCurrent();

        /** @var CatalogPdfExport|null $export */
        $export = CatalogPdfExport::find($this->exportId);
        if ($export !== null) {
            app(CatalogPdfExportService::class)->fail($export, $exception);
        }
    }
}
