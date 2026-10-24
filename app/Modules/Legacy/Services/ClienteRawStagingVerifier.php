<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Support\ClienteRawEntidades;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1137 F1 — lado staging de `legacy:verify-clientes-raw` + comparación pura contra ICG. SOLO LEE y solo
 * maneja conteos y llaves naturales (CODCLIENTE / ID): nunca selecciona columnas de datos. Requiere tenant activo.
 */
final class ClienteRawStagingVerifier
{
    /** @return array<string, int> entidad => filas en staging */
    public function conteos(): array
    {
        $out = [];
        foreach (ClienteRawEntidades::todas() as $entidad) {
            $out[$entidad] = DB::table($this->table($entidad))->count();
        }

        return $out;
    }

    /** @return list<int> llaves naturales presentes en staging */
    public function llaves(string $entidad): array
    {
        $key = ClienteRawEntidades::keys($entidad)[0];

        $llaves = [];
        foreach (DB::table($this->table($entidad))->orderBy($key)->pluck($key) as $llave) {
            $llaves[] = (int) $llave;
        }

        return $llaves;
    }

    /**
     * Pura. Compara conteos y llaves de una entidad.
     *
     * @param list<int> $icgKeys
     * @param list<int> $stagingKeys
     * @return array{icg: int, staging: int, solo_en_staging: list<int>, solo_en_icg: list<int>, ok: bool}
     */
    public static function compare(int $icgCount, int $stagingCount, array $icgKeys, array $stagingKeys): array
    {
        $soloStaging = array_values(array_diff($stagingKeys, $icgKeys));
        $soloIcg     = array_values(array_diff($icgKeys, $stagingKeys));
        sort($soloStaging);
        sort($soloIcg);

        return [
            'icg'             => $icgCount,
            'staging'         => $stagingCount,
            'solo_en_staging' => $soloStaging,
            'solo_en_icg'     => $soloIcg,
            'ok'              => $icgCount === $stagingCount && $soloStaging === [] && $soloIcg === [],
        ];
    }

    private function table(string $entidad): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('ClienteRawStagingVerifier requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy." . ClienteRawEntidades::tabla($entidad);
    }
}
