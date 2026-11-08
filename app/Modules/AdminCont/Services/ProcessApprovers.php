<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 28 (grupo 6) — ¿quién aprueba cada proceso de Admin & Contab? Un proceso con aprobadores propios
 * (`process_approvers`) solo lo deciden ellos; sin filas rige el aprobador primario + suplentes de
 * `PrimaryApprovers` (comportamiento de siempre). `superadmin.all` bypassa, como en todos los gates.
 * Sin memo: una consulta mínima por chequeo, así un cambio de configuración rige de inmediato.
 */
final class ProcessApprovers
{
    public const CAJA_CHICA = 'caja_chica';

    public const ARQUEO = 'arqueo';

    public const ANULACION_COBRO = 'anulacion_cobro';

    public const NOTA_CREDITO = 'nota_credito';

    /** @var list<string> */
    public const PROCESSES = [self::CAJA_CHICA, self::ARQUEO, self::ANULACION_COBRO, self::NOTA_CREDITO];

    public static function isApprover(int $userId, string $process): bool
    {
        $propios = self::idsOf($process);
        if ($propios === []) {
            return PrimaryApprovers::isApprover($userId, AdminContFiscalSettings::current()->primary_approver_user_id);
        }

        return in_array($userId, $propios, true);
    }

    /**
     * Como `isApprover` pero desde el payload del JWT, incluyendo el bypass de `superadmin.all`.
     *
     * @param  array{sub?: int, permissions?: mixed}|null  $jwt
     */
    public static function actorCan(?array $jwt, string $process): bool
    {
        $permissions = is_array($jwt['permissions'] ?? null) ? array_values(array_filter($jwt['permissions'], 'is_string')) : [];
        if (in_array('superadmin.all', $permissions, true)) {
            return true;
        }

        return $jwt !== null && isset($jwt['sub']) && self::isApprover((int) $jwt['sub'], $process);
    }

    /** @return list<int> aprobadores propios del proceso; vacío = sigue el aprobador primario */
    public static function idsOf(string $process): array
    {
        try {
            return array_values(array_map('intval', DB::table(self::table())->where('process', $process)->pluck('user_id')->all()));
        } catch (QueryException $e) {
            // Solo se tolera que la tabla aún no exista (tenant sin migrar). Cualquier otro fallo propaga: fallar cerrado,
            // nunca ensanchar el conjunto de aprobadores por un error de base de datos.
            if ($e->getCode() === '42P01') {
                return [];
            }

            throw $e;
        }
    }

    public static function table(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'process_approvers' : "{$tenant->slug}_admin_contab.process_approvers";
    }
}
