<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\VentasDiseno\Services\CatalogPdfExportService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-998 — borra los ZIP de volúmenes del Catálogo vencidos (S3 y fila, según `catalog_pdf_export_retention_days` de
 * `CrmSettingsService`) y cierra como `failed` las exportaciones que llevan horas sin terminar. Corre por tenant, síncrono:
 * es una limpieza chica, no justifica un job.
 */
final class CatalogPdfPurgeExportsCommand extends Command
{
    protected $signature   = 'catalog-pdf:purge-exports';
    protected $description = 'Borra las exportaciones de catálogo en PDF vencidas de cada tenant';

    public function handle(CatalogPdfExportService $exports): int
    {
        $purged = 0;

        foreach (Tenant::all() as $tenant) {
            $tenant->makeCurrent();

            try {
                $purged += $exports->purgeExpired();
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("Exportaciones de catálogo vencidas borradas: {$purged}.");

        return self::SUCCESS;
    }
}
