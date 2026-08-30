<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteMatcher;
use App\Modules\Legacy\Services\ClienteStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-755 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging)→Matcher para el batch de Clientes (Illumination/migracion-icg-diseno.md §5). A
 * diferencia de `LegacySyncProductosCommand` (SCRUM-750), esta entidad NO tiene Loader-B — el
 * `ClienteMatcher` persiste el resultado directamente en `legacy.clientes` (ver runbook §12.1).
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Matcher — TODOS escriben exclusivamente
 * dentro de `legacy.*`, upserts idempotentes sobre tablas de solo-lectura de referencia, así que
 * correrlos en modo rehearsal es seguro (mismo criterio que `LegacySyncProductosCommand`, que
 * también deja correr su staging en dry-run). Lo único que NUNCA pasa en dry-run es el registro de
 * la corrida en `legacy.sync_runs` — mismo motivo que Productos: esa tabla es "una fila por
 * corrida REAL" (ver docblock de la migración de `sync_runs`), no se ensucia con rehearsals.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (schema genérico, compartido entre
 * entidades, ver runbook §12.1: "la tabla ya es genérica, no hace falta una nueva por entidad"):
 * como Clientes no tiene una etapa de "mapeo" ni "carga a un destino" separadas de su matching
 * (el Matcher resuelve Y persiste en el mismo paso, dentro de `legacy.clientes`), `mapeados` y
 * `cargados` quedan ambos iguales al conteo de `matched` — no hay una distinción real entre
 * "resuelto" y "cargado" para esta entidad. `excluidos` = `unmatched + ambiguous` (clientes que
 * no terminaron con un `sub_client_id` resuelto). El detalle matched/unmatched/ambiguous
 * diferenciado sí se imprime en el reporte de consola (`renderReport()`), que es donde el ticket
 * lo pide explícitamente.
 *
 * La corrida real contra ICG (gateway VPN / dev.atlanticerp.ai / test.atlanticerp.ai) es explícitamente FUERA
 * de este batch — este comando nunca se corrió contra SQL Server real todavía.
 */
final class LegacySyncClientesCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-clientes
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-755 — extrae CLIENTES/CONTACTOSCLIENTES de ICG, stagea en legacy.clientes/contactos_cliente y resuelve sub_client_id por CIF/email';

    public function __construct(
        private readonly ClienteExtractorContract $extractor,
        private readonly ClienteStagingLoader $stagingLoader,
        private readonly ClienteMatcher $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncProductosCommand (SCRUM-750, Tarea 6): el volumen real de
        // CLIENTES (7,707 filas) + CONTACTOSCLIENTES (821 filas) revienta el memory_limit default
        // de PHP CLI (128M). ini_set() aplica directo porque este comando ES el proceso PHP
        // top-level (no spawnea un hijo separado como `artisan test`).
        ini_set('memory_limit', '512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $startedAt = now();
        $start     = microtime(true);

        $tenant->makeCurrent();
        try {
            return $this->runPipeline($isDryRun, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runPipeline(bool $isDryRun, Carbon $startedAt, float $start): int
    {
        try {
            $extracted = $this->extractor->extract();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }

        // Mismo patrón que LegacySyncProductosCommand (hallazgo Pre-QA SCRUM-750): un segundo
        // try/catch alrededor de staging+matching — sin esto, cualquier falla acá dejaba la
        // corrida sin rastro en legacy.sync_runs (vacío de monitoreo #5 del diseño, §11).
        try {
            return $this->stageAndMatch($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear clientes: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($extracted->clientes), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    private function stageAndMatch(ExtractedClientes $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $stagingCounts = $this->stagingLoader->load($extracted);
        $codigos       = $extracted->icgCodclientes();
        $matchResult   = $this->matcher->match($codigos);

        $this->renderReport($extracted, $stagingCounts, $matchResult);

        if ($isDryRun) {
            $this->newLine();
            $this->comment(
                'Modo --dry-run: staging y matching (legacy.*) actualizados, pero NO se registró '
                . 'la corrida en legacy.sync_runs.'
            );

            return self::SUCCESS;
        }

        $status = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $this->recordRun(
            $startedAt, $start,
            extraidos: count($extracted->clientes),
            staged: $stagingCounts['clientes'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['unmatched'] + $matchResult['ambiguous'],
            errores: $matchResult['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * @param array{clientes: int, contactos: int} $stagingCounts
     * @param array{matched: int, unmatched: int, ambiguous: int, errores: int, details: list<array{icg_codcliente: int, cif: ?string, email: ?string, match_status: string, motivo: ?string}>} $matchResult
     */
    private function renderReport(ExtractedClientes $extracted, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-clientes — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (CLIENTES)', count($extracted->clientes)],
            ['Extraídos (CONTACTOSCLIENTES)', count($extracted->contactos)],
            ['Staged — clientes', $stagingCounts['clientes']],
            ['Staged — contactos', $stagingCounts['contactos']],
            ['Matched (sub_client_id resuelto)', $matchResult['matched']],
            ['Unmatched (sin CIF/email o sin coincidencia)', $matchResult['unmatched']],
            ['Ambiguous (más de 1 sub_client posible)', $matchResult['ambiguous']],
            ['Errores', $matchResult['errores']],
        ]);

        // §9 riesgo #1 del diseño: el reporte de unmatched/ambiguous tiene que tener volumen
        // suficiente para auditar después, no solo el total — se listan hasta 50 filas por
        // categoría acá; el detalle completo siempre queda persistido en legacy.clientes para
        // consulta directa a Postgres (§13.4).
        $auditables = array_values(array_filter(
            $matchResult['details'],
            static fn (array $d): bool => $d['match_status'] !== 'matched',
        ));

        if ($auditables !== []) {
            $shown = array_slice($auditables, 0, 50);

            $this->newLine();
            $this->line('<comment>Unmatched/Ambiguous/Error — motivo (hasta 50 filas, ver legacy.clientes para el resto)</comment>');
            $this->table(
                ['icg_codcliente', 'cif', 'email', 'match_status', 'motivo'],
                array_map(
                    static fn (array $d): array => [$d['icg_codcliente'], $d['cif'], $d['email'], $d['match_status'], $d['motivo']],
                    $shown,
                ),
            );

            if (count($auditables) > 50) {
                $this->comment(sprintf('... y %d fila(s) más — ver legacy.clientes.match_status.', count($auditables) - 50));
            }
        }
    }

    private function recordRun(
        Carbon $startedAt,
        float $start,
        int $extraidos,
        int $staged,
        int $mapeados,
        int $cargados,
        int $excluidos,
        int $errores,
        string $status,
    ): void {
        SyncRun::query()->create([
            'entidad'     => 'clientes',
            'extraidos'   => $extraidos,
            'staged'      => $staged,
            'mapeados'    => $mapeados,
            'cargados'    => $cargados,
            'excluidos'   => $excluidos,
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
