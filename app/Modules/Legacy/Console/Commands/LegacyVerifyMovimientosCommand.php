<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Contracts\MovimientoVerificationExtractorContract;
use App\Modules\Legacy\Services\MovimientoStagingVerifier;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1136 F2 — verificación de la carga de MOVIMENTS: compara total de filas, `MIN/MAX(ID)` y, por `TIPO`, filas
 * y suma de UNIDADES entre ICG y `legacy.movimientos` (tolerancia de UNIDADES como `legacy:verify-almacenes`).
 * Comando aparte de `legacy:verify-almacenes` a propósito: así no cambia la salida ni los contratos de F1. NO escribe
 * nada (ni en ICG ni en Postgres, ni `sync_runs`). Exit 1 si algo difiere (p. ej. la carga aún no terminó).
 */
final class LegacyVerifyMovimientosCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:verify-movimientos
        {--tenant=atlantic : Slug del tenant a verificar}';

    /** @var string */
    protected $description = 'SCRUM-1136 F2 — compara total, rango de ID y suma de UNIDADES por TIPO entre ICG y legacy.movimientos (solo lectura)';

    public function handle(): int
    {
        // Resueltas al ejecutar (no en el constructor), igual que legacy:verify-almacenes.
        $icg     = app(MovimientoVerificationExtractorContract::class);
        $staging = app(MovimientoStagingVerifier::class);

        $tenantSlug = (string) $this->option('tenant');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            try {
                $icgResumen = $icg->resumen();
            } catch (Throwable $e) {
                $this->error("No se pudo leer ICG: {$e->getMessage()}");

                return self::FAILURE;
            }

            $result = MovimientoStagingVerifier::compare($icgResumen, $staging->resumen());

            $this->info('legacy:verify-movimientos — total y rango de ID (ICG vs staging)');
            $this->table(
                ['Concepto', 'ICG', 'Staging', 'OK'],
                array_map(static fn (array $r): array => [$r['concepto'], $r['icg'] ?? '-', $r['staging'] ?? '-', $r['ok'] ? 'si' : 'NO'], $result['global']),
            );

            $this->info('Filas y suma de UNIDADES por TIPO');
            $this->table(
                ['Tipo', 'Filas ICG', 'Filas staging', 'UNIDADES ICG', 'UNIDADES staging', 'OK'],
                array_map(static fn (array $r): array => [
                    $r['tipo'] === '' ? '(vacío)' : $r['tipo'], $r['icg_filas'], $r['staging_filas'],
                    number_format($r['icg_unidades'], 4, '.', ''), number_format($r['staging_unidades'], 4, '.', ''),
                    $r['ok'] ? 'si' : 'NO',
                ], $result['tipos']),
            );

            if (! $result['ok']) {
                $this->error('Hay diferencias entre ICG y staging.');

                return self::FAILURE;
            }

            $this->info('Verificación OK: total, rango de ID y UNIDADES por tipo coinciden.');

            return self::SUCCESS;
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
