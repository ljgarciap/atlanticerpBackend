<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Fase 12b — ¿quién es «el aprobador»? El titular de cada módulo (`primary_approver_user_id` de Compras o de Admin & Contab) o cualquiera de
 * los suplentes (`primary_approver_substitutes`, compartidos). Único punto que decide: ningún gate compara ya contra un id suelto.
 *
 * Los suplentes se leen una vez por petición/job (memo por tenant) y la consulta tolera que la tabla aún no exista en un tenant sin migrar.
 */
final class PrimaryApprovers
{
    /** @var array<string, list<int>> */
    private static array $memo = [];

    /** ¿`$userId` es el titular (`$titularId`) o un suplente? Sin titular configurado, nadie lo es (cada llamador decide qué hace en ese caso). */
    public static function isApprover(?int $userId, ?int $titularId): bool
    {
        if ($userId === null) {
            return false;
        }

        return ($titularId !== null && $userId === $titularId) || in_array($userId, self::substituteIds(), true);
    }

    /** @return list<int> */
    public static function substituteIds(): array
    {
        $tenant = Tenant::current();
        $slug = $tenant === null ? '' : $tenant->slug;
        if (! isset(self::$memo[$slug])) {
            try {
                $table = $slug === '' ? 'primary_approver_substitutes' : "{$slug}_auth.primary_approver_substitutes";
                self::$memo[$slug] = array_values(array_map('intval', DB::table($table)->pluck('user_id')->all()));
            } catch (\Throwable) {
                self::$memo[$slug] = [];
            }
        }

        return self::$memo[$slug];
    }

    /** Tras cambiar la lista, en la misma petición o en tests. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
