<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Services\TransversalTestDataCleanupService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SCRUM-901 — "Limpieza de datos de prueba": conserva únicamente la data del usuario indicado
 * (--keep-user, default Vendedor Disenador — flujos completos de principio a fin en todos los módulos) y
 * purga la de cualquier otro usuario, transversal a CRM, Ventas & Diseño, Compras, Bodega,
 * Servicios y Administración & Contabilidad. Ver TransversalTestDataCleanupService para el
 * diseño completo del algoritmo.
 *
 * GUARD DE ENTORNO: nunca corre en `production` — `--env-override` existe únicamente para poder
 * testear el comando en local/CI.
 *
 * MODO DEFAULT: dry-run (reporte, sin tocar la BD). `--force` es la única forma de ejecutar el
 * borrado real, y AUN ASÍ requiere: (1) revisión humana del reporte dry-run primero, (2) un
 * respaldo previo del entorno objetivo (`infra/scripts/backup-postgres.sh`), (3) confirmación
 * explícita de Luis — este comando fue diseñado para NUNCA correrse con --force sin esos 3 pasos
 * antes, sin importar qué modo de autonomía esté activo en la sesión que lo invoca.
 */
final class TransversalCleanupTestDataCommand extends Command
{
    /** @var string */
    protected $signature = 'data:cleanup-test-data
        {--tenant=atlantic : Slug del tenant a limpiar}
        {--keep-user=vendedordisenador5@test.com : Email del único usuario cuya data se conserva}
        {--force : Ejecuta el borrado/null-out real — sin esta opción el comando SIEMPRE corre en modo reporte}
        {--json : Emite el reporte en JSON en vez de tablas de consola}
        {--env-override : Permite correr en production — SOLO para testing local/CI, nunca en la operación real}';

    /** @var string */
    protected $description = 'SCRUM-901 — identifica (y, con --force, borra) toda la data de prueba excepto la de un usuario, transversal a todos los módulos';

    public function __construct(private readonly TransversalTestDataCleanupService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('env-override')) {
            $this->error(
                'Este comando nunca corre en production. Usá --env-override SOLO para '
                . 'testing local/CI — la operación real es siempre contra dev o test (qa).'
            );

            return self::FAILURE;
        }

        $tenantSlug    = (string) $this->option('tenant');
        $keepUserEmail = (string) $this->option('keep-user');
        $isForce       = (bool) $this->option('force');
        $asJson        = (bool) $this->option('json');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        try {
            $report = $this->service->buildReport($tenantSlug, $keepUserEmail);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // En modo --json, Artisan::output() debe ser UN SOLO JSON parseable de punta a punta —
        // ningún texto/comentario ni un segundo objeto JSON después (encontrado en el test de
        // este comando: un consumidor automatizado no puede json_decode() dos objetos pegados).
        // Por eso el reporte dry-run se imprime SIEMPRE, salvo cuando --force además pide --json
        // (ahí se imprime un único JSON al final, con el resultado de execute() + el resumen del
        // reporte que lo originó, para no perder ese contexto).
        if ($asJson && ! $isForce) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (! $asJson) {
            $this->renderReport($report);
        }

        if (! $isForce) {
            if (! $asJson) {
                $this->newLine();
                $this->comment(
                    'Modo dry-run (default) — no se tocó nada. Antes de correr con --force: '
                    . '(1) revisión humana completa de este reporte, (2) backup del entorno objetivo '
                    . '(infra/scripts/backup-postgres.sh), (3) confirmación explícita de Luis.'
                );
            }

            return self::SUCCESS;
        }

        if (! $asJson) {
            $this->newLine();
            $this->warn('--force: ejecutando el borrado/null-out real de los candidatos de arriba.');
        }

        $result = $this->service->execute($report);

        if ($asJson) {
            $this->line((string) json_encode(['report' => $report] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Operación', 'Filas afectadas'], [
                ['Referencias puestas en NULL', $result['nulled']],
                ['Filas borradas', $result['deleted']],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param array{
     *   tenant: string,
     *   keep_user: array{id: int, email: string},
     *   other_users_count: int,
     *   delete_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   null_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   no_pk_candidates: list<array{table: string, column: string, count: int, nullable: bool}>,
     *   excluded: list<array{table: string, id: int, reason: string, referenced_by: string}>,
     * } $report
     */
    private function renderReport(array $report): void
    {
        $this->info("Tenant: {$report['tenant']} — se conserva: {$report['keep_user']['email']} (id={$report['keep_user']['id']})");
        $this->line("Otros usuarios (sus CUENTAS se conservan siempre — solo se limpia la data asociada): {$report['other_users_count']}");

        $this->newLine();
        $this->line('<comment>Filas candidatas a BORRAR</comment>');
        $this->table(
            ['Tabla', 'Columna FK', 'Cantidad', 'IDs'],
            array_map(fn (array $c): array => [$c['table'], $c['column'], $c['count'], $this->truncateIds($c['ids'])], $report['delete_candidates']),
        );

        $this->newLine();
        $this->line('<comment>Referencias candidatas a poner en NULL (la fila se conserva)</comment>');
        $this->table(
            ['Tabla', 'Columna FK', 'Cantidad', 'IDs'],
            array_map(fn (array $c): array => [$c['table'], $c['column'], $c['count'], $this->truncateIds($c['ids'])], $report['null_candidates']),
        );

        if ($report['no_pk_candidates'] !== []) {
            $this->newLine();
            $this->line('<comment>Tablas sin columna "id" — REQUIEREN REVISIÓN MANUAL, --force nunca las toca</comment>');
            $this->table(
                ['Tabla', 'Columna FK', 'Cantidad', 'Nullable'],
                array_map(fn (array $c): array => [$c['table'], $c['column'], $c['count'], $c['nullable'] ? 'sí' : 'no'], $report['no_pk_candidates']),
            );
        }

        if ($report['excluded'] !== []) {
            $this->newLine();
            $this->line('<comment>Excluidos de borrar (referencia activa desde una fila sobreviviente)</comment>');
            $this->table(
                ['Tabla', 'ID', 'Motivo', 'Referenciado por'],
                array_map(
                    fn (array $row): array => [$row['table'], $row['id'], $row['reason'], $row['referenced_by']],
                    $report['excluded'],
                ),
            );
        }
    }

    /** @param list<int> $ids */
    private function truncateIds(array $ids): string
    {
        if (count($ids) <= 20) {
            return implode(', ', $ids);
        }

        return implode(', ', array_slice($ids, 0, 20)) . ', … (+' . (count($ids) - 20) . ' más)';
    }
}
