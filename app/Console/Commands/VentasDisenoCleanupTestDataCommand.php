<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\VentasDiseno\Services\TestDataCleanupService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SCRUM-735 — "Eliminación de información generada durante PM Review en Ventas & Diseño".
 *
 * Identifica (y, solo con --force, borra) la data de prueba que Daniela Amaya generó en
 * test.atlanticerp.ai probando manualmente el módulo Ventas & Diseño — sin tocar la data DEMO
 * marcada con [DEMO-711]/[QA-VD]/[QA-VD-409] (protegida siempre, sin importar quién la
 * creó). Ver App\Modules\VentasDiseno\Services\TestDataCleanupService para el diseño
 * completo del algoritmo de candidatos.
 *
 * GUARD DE ENTORNO: solo corre contra `qa` (test.atlanticerp.ai) por default — --env-override
 * existe ÚNICAMENTE para poder testear el comando en local/CI, nunca para correrlo contra
 * dev/production.
 *
 * MODO DEFAULT: dry-run (reporte, sin tocar la BD). --force es la única forma de ejecutar
 * el borrado real, y aun así requiere revisión humana del reporte antes de correrlo así —
 * este comando nunca se corrió con --force fuera de la suite de tests automatizada.
 */
final class VentasDisenoCleanupTestDataCommand extends Command
{
    /** @var string */
    protected $signature = 'ventas-diseno:cleanup-test-data
        {--tenant=illuminations : Slug del tenant a limpiar}
        {--user=daniela@illuminations.com.pa : Email del usuario cuya data de prueba se identifica}
        {--force : Ejecuta el borrado real — sin esta opción el comando SIEMPRE corre en modo reporte}
        {--json : Emite el reporte en JSON en vez de tablas de consola}
        {--env-override : Permite correr fuera de app()->environment("qa") — SOLO para testing local/CI}';

    /** @var string */
    protected $description = 'SCRUM-735 — identifica (y, con --force, borra) la data de prueba de Daniela Amaya en Ventas & Diseño, preservando [DEMO-711]/[QA-VD]/[QA-VD-409]';

    public function __construct(private readonly TestDataCleanupService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('qa') && ! $this->option('env-override')) {
            $this->error(
                'Este comando solo corre en el entorno "qa" (test.atlanticerp.ai). '
                . 'Usá --env-override para correrlo en local/testing — nunca contra dev/production.'
            );

            return self::FAILURE;
        }

        $tenantSlug = (string) $this->option('tenant');
        $userEmail  = (string) $this->option('user');
        $isForce    = (bool) $this->option('force');
        $asJson     = (bool) $this->option('json');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        try {
            $report = $this->service->buildReport($userEmail);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Tenant::forgetCurrent();
        }

        if ($asJson) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        if (! $isForce) {
            $this->newLine();
            $this->comment(
                'Modo dry-run (default) — no se borró nada. Pasá --force para ejecutar el '
                . 'borrado real, después de revisión humana del reporte de arriba.'
            );

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('--force: ejecutando el borrado real de los candidatos de arriba.');

        $tenant->makeCurrent();
        try {
            $deleted = $this->service->execute($report);
        } finally {
            Tenant::forgetCurrent();
        }

        if ($asJson) {
            $this->line((string) json_encode(['deleted' => $deleted], JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Entidad', 'Filas borradas'],
                collect($deleted)->map(fn (int $count, string $type): array => [$type, $count])->values()->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param array{
     *   user: array{id: int, email: string},
     *   audit_window: array{from: ?string, to: ?string, rows_considered: int},
     *   candidates: array<string, list<int>>,
     *   excluded: list<array{entity_type: string, id: int, reason: string, label: ?string}>,
     * } $report
     */
    private function renderReport(array $report): void
    {
        $this->info("Usuario objetivo: {$report['user']['email']} (id={$report['user']['id']})");
        $this->line(
            "Ventana de audit_logs consideradas: {$report['audit_window']['rows_considered']} filas "
            . "[{$report['audit_window']['from']} .. {$report['audit_window']['to']}]"
        );

        $this->newLine();
        $this->line('<comment>Candidatos a borrar</comment>');
        $this->table(
            ['Entidad', 'Cantidad', 'IDs'],
            collect($report['candidates'])->map(fn (array $ids, string $type): array => [
                $type,
                count($ids),
                implode(', ', $ids),
            ])->values()->all(),
        );

        if ($report['excluded'] !== []) {
            $this->newLine();
            $this->line('<comment>Excluidos (protegidos)</comment>');
            $this->table(
                ['Entidad', 'ID', 'Motivo', 'Label'],
                array_map(
                    fn (array $row): array => [$row['entity_type'], $row['id'], $row['reason'], $row['label'] ?? '—'],
                    $report['excluded'],
                ),
            );
        }
    }
}
