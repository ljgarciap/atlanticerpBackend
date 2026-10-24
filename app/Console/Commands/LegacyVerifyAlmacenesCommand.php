<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\AlmacenVerificationExtractorContract;
use App\Modules\Legacy\Services\AlmacenStagingVerifier;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1136 F1 — verificación de la carga de almacenes/stock/costos/traspasos/inventarios: compara
 * conteos y suma de STOCK por almacén ICG vs staging, y lista los almacenes huérfanos (con stock pero sin
 * fila en `legacy.almacenes`, caso A1). NO escribe nada (ni en ICG ni en Postgres, ni `sync_runs`).
 *
 * Exit 1 si algún conteo o suma difiere; los huérfanos son informativos (se cargan igual, criterio 7).
 */
final class LegacyVerifyAlmacenesCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:verify-almacenes
        {--tenant=atlantic : Slug del tenant a verificar}';

    /** @var string */
    protected $description = 'SCRUM-1136 — compara conteos y suma de STOCK por almacén entre ICG y legacy.*, y lista huérfanos (solo lectura)';

    private AlmacenVerificationExtractorContract $icg;

    private AlmacenStagingVerifier $staging;

    public function handle(): int
    {
        // Dependencias resueltas al ejecutar (no en el constructor): el kernel de consola instancia los
        // comandos al arrancar, antes de que un test (o un binding tardío) pueda reemplazar el extractor.
        $this->icg     = app(AlmacenVerificationExtractorContract::class);
        $this->staging = app(AlmacenStagingVerifier::class);

        $tenantSlug = (string) $this->option('tenant');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->verify();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function verify(): int
    {
        try {
            $icgStock   = $this->icg->stockPorAlmacen();
            $icgConteos = $this->icg->conteos();
        } catch (Throwable $e) {
            $this->error("No se pudo leer ICG: {$e->getMessage()}");

            return self::FAILURE;
        }

        $stagingConteos = $this->staging->conteos();
        $result         = AlmacenStagingVerifier::compare(
            $icgStock,
            $this->staging->stockPorAlmacen(),
            $icgConteos,
            $stagingConteos,
        );

        $this->info('legacy:verify-almacenes — STOCK por almacén (ICG vs staging)');
        $this->table(
            ['Almacén', 'Filas ICG', 'Filas staging', 'STOCK ICG', 'STOCK staging', 'OK'],
            array_map(static fn (array $r): array => [
                $r['codalmacen'], $r['icg_filas'], $r['staging_filas'],
                number_format($r['icg_stock'], 4, '.', ''), number_format($r['staging_stock'], 4, '.', ''),
                $r['ok'] ? 'si' : 'NO',
            ], $result['stock']),
        );

        $this->info('Conteos por entidad');
        $this->table(
            ['Entidad', 'ICG', 'Staging', 'OK'],
            array_map(static fn (array $r): array => [$r['entidad'], $r['icg'], $r['staging'], $r['ok'] ? 'si' : 'NO'], $result['conteos']),
        );

        $huerfanos = $this->staging->huerfanos();
        if ($huerfanos === []) {
            $this->info('Huérfanos: ninguno.');
        } else {
            $this->warn('Almacenes con stock pero sin fila en legacy.almacenes (huérfanos, se cargaron igual):');
            $this->table(
                ['Almacén', 'Filas de stock'],
                array_map(static fn (string $code, int $filas): array => [$code, $filas], array_keys($huerfanos), array_values($huerfanos)),
            );
        }

        if (! $result['ok']) {
            $this->error('Hay diferencias entre ICG y staging.');

            return self::FAILURE;
        }

        $this->info('Verificación OK: conteos y suma de STOCK coinciden.');

        return self::SUCCESS;
    }
}
