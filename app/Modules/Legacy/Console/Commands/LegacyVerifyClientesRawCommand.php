<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Contracts\ClienteRawVerificationExtractorContract;
use App\Modules\Legacy\Services\ClienteRawStagingVerifier;
use App\Modules\Legacy\Support\ClienteRawEntidades;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1137 F1 — verificación de la copia cruda de clientes: compara conteos y llaves (CODCLIENTE / ID) entre
 * ICG y staging, y reporta las filas que existen en staging y ya no en ICG (el staging es upsert-only, no las
 * borra) y las que existen en ICG y faltan en staging. NO escribe nada (ni en ICG ni en Postgres, ni
 * `sync_runs`) y NUNCA imprime valores de columnas: solo conteos y llaves (REVIEW-icg-clientes-staging-f1.md,
 * condición 2).
 *
 * Exit 1 si algún conteo o llave difiere.
 */
final class LegacyVerifyClientesRawCommand extends Command
{
    /** Máximo de llaves listadas por diferencia (el resto se resume en un conteo). Presentación, no negocio. */
    private const MAX_LLAVES_LISTADAS = 50;

    /** @var string */
    protected $signature = 'legacy:verify-clientes-raw
        {--tenant=atlantic : Slug del tenant a verificar}';

    /** @var string */
    protected $description = 'SCRUM-1137 — compara conteos y llaves de CLIENTES/CONTACTOSCLIENTES entre ICG y legacy.*_raw (solo lectura, sin imprimir datos)';

    public function handle(): int
    {
        // Dependencias resueltas al ejecutar (no en el constructor), igual que `legacy:verify-almacenes`.
        /** @var ClienteRawVerificationExtractorContract $icg */
        $icg     = app(ClienteRawVerificationExtractorContract::class);
        $staging = app(ClienteRawStagingVerifier::class);

        $tenantSlug = (string) $this->option('tenant');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->verify($icg, $staging);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function verify(ClienteRawVerificationExtractorContract $icg, ClienteRawStagingVerifier $staging): int
    {
        try {
            $icgConteos = $icg->conteos();
            $icgLlaves  = [];
            foreach (ClienteRawEntidades::todas() as $entidad) {
                $icgLlaves[$entidad] = $icg->llaves($entidad);
            }
        } catch (Throwable $e) {
            $this->error('No se pudo leer ICG: ' . LegacySyncClientesRawCommand::safeMessage($e));

            return self::FAILURE;
        }

        $stagingConteos = $staging->conteos();
        $ok             = true;
        $filas          = [];
        $diferencias    = [];

        foreach (ClienteRawEntidades::todas() as $entidad) {
            $r = ClienteRawStagingVerifier::compare(
                $icgConteos[$entidad] ?? 0,
                $stagingConteos[$entidad] ?? 0,
                $icgLlaves[$entidad],
                $staging->llaves($entidad),
            );

            $ok      = $ok && $r['ok'];
            $filas[] = [$entidad, $r['icg'], $r['staging'], count($r['solo_en_staging']), count($r['solo_en_icg']), $r['ok'] ? 'si' : 'NO'];

            if ($r['solo_en_staging'] !== []) {
                $diferencias[] = [$entidad, 'en staging y NO en ICG (borradas en ICG)', $r['solo_en_staging']];
            }
            if ($r['solo_en_icg'] !== []) {
                $diferencias[] = [$entidad, 'en ICG y NO en staging', $r['solo_en_icg']];
            }
        }

        $this->info('legacy:verify-clientes-raw — conteos y llaves (ICG vs staging)');
        $this->table(['Entidad', 'Filas ICG', 'Filas staging', 'Solo en staging', 'Solo en ICG', 'OK'], $filas);

        foreach ($diferencias as [$entidad, $que, $llaves]) {
            $mostrar = array_slice($llaves, 0, self::MAX_LLAVES_LISTADAS);
            $resto   = count($llaves) - count($mostrar);
            $this->warn("{$entidad}: llaves {$que}: " . implode(', ', $mostrar) . ($resto > 0 ? " … y {$resto} más" : ''));
        }

        if (! $ok) {
            $this->error('Hay diferencias entre ICG y staging.');

            return self::FAILURE;
        }

        $this->info('Verificación OK: conteos y llaves coinciden.');

        return self::SUCCESS;
    }
}
