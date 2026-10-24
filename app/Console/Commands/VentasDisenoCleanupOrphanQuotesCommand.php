<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\VentasDiseno\Services\OrphanQuoteCleanupService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-912 — "+ Nueva cotización" creaba una fila en `quotes` apenas se abría el
 * formulario, incluso sin llenar nada (ver QuoteController::hasMeaningfulContent(),
 * fix real del bug). Este comando identifica (y, solo con --force, borra) las filas
 * huérfanas que ese bug dejó persistidas ANTES del fix — ver
 * OrphanQuoteCleanupService para el criterio exacto (sin folio, sin ítems).
 *
 * MODO DEFAULT: dry-run (reporte, sin tocar la BD) — mismo patrón que
 * TransversalCleanupTestDataCommand/VentasDisenoCleanupTestDataCommand. --force es la
 * única forma de ejecutar el borrado real, y aun así requiere: (1) revisión humana
 * del reporte dry-run primero, (2) backup previo del entorno objetivo si es test/prod
 * (infra/scripts/backup-postgres.sh), (3) confirmación explícita de Luis — este
 * comando fue diseñado para NUNCA correrse con --force sin esos pasos antes, sin
 * importar qué modo de autonomía esté activo en la sesión que lo invoca.
 */
final class VentasDisenoCleanupOrphanQuotesCommand extends Command
{
    /** @var string */
    protected $signature = 'ventas-diseno:cleanup-orphan-quotes
        {--tenant=atlantic : Slug del tenant a limpiar}
        {--older-than-hours=24 : Antigüedad mínima (horas) para considerar candidato — protege un Borrador real recién tocado}
        {--force : Ejecuta el borrado real — sin esta opción el comando SIEMPRE corre en modo reporte}
        {--json : Emite el reporte en JSON en vez de tablas de consola}';

    /** @var string */
    protected $description = 'SCRUM-912 — identifica (y, con --force, borra) cotizaciones huérfanas (sin folio, sin ítems) dejadas por el bug de creación al abrir "+ Nueva cotización"';

    public function __construct(private readonly OrphanQuoteCleanupService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSlug     = (string) $this->option('tenant');
        $olderThanHours = (int) $this->option('older-than-hours');
        $isForce        = (bool) $this->option('force');
        $asJson         = (bool) $this->option('json');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        try {
            $report = $this->service->buildReport($olderThanHours);

            if ($asJson) {
                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderReport($report);
            }

            if (! $isForce) {
                if (! $asJson) {
                    $this->newLine();
                    $this->comment(
                        'Modo dry-run (default) — no se tocó nada. Antes de correr con --force: '
                        . '(1) revisión humana completa de este reporte, (2) backup del entorno objetivo '
                        . 'si es test/prod (infra/scripts/backup-postgres.sh), (3) confirmación explícita de Luis.'
                    );
                }

                return self::SUCCESS;
            }

            if (! $asJson) {
                $this->newLine();
                $this->warn('--force: ejecutando el borrado real de los candidatos de arriba.');
            }

            $deleted = $this->service->execute($report);

            if ($asJson) {
                $this->line((string) json_encode(['report' => $report, 'deleted' => $deleted], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(['Operación', 'Filas'], [['Cotizaciones huérfanas borradas', $deleted]]);
            }

            return self::SUCCESS;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * @param array{
     *   older_than_hours: int,
     *   candidates: list<array{id: int, description: ?string, master_client_id: ?int, sub_client_id: ?int, created_at: string}>,
     * } $report
     */
    private function renderReport(array $report): void
    {
        $this->info("Antigüedad mínima considerada: {$report['older_than_hours']}h — criterio: sin folio, sin confirmar, sin ningún ítem cargado.");

        $this->newLine();
        $this->line('<comment>Cotizaciones huérfanas candidatas a borrar</comment>');
        $this->table(
            ['ID', 'Descripción', 'Master Client ID', 'Sub Client ID', 'Creada'],
            array_map(fn (array $c): array => [
                $c['id'], $c['description'] ?? '—', $c['master_client_id'] ?? '—', $c['sub_client_id'] ?? '—', $c['created_at'],
            ], $report['candidates']),
        );

        $this->newLine();
        $this->line('Total candidatas: ' . count($report['candidates']));
    }
}
