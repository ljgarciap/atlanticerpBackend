<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * Claves (`roles.key`) del catálogo de roles de sistema. ÚNICO lugar del código de producción donde puede aparecer el literal de una clave de rol
 * (lo verifica `RoleKeysUsageTest`): así renombrar un rol es cambiar una línea acá + una migración de datos, no buscar strings por el repo.
 *
 * Fase 3 (2026-10-25, docs/specs/fase3-roles-genericos.md): catálogo genérico, sin vocabulario del cliente anterior.
 * `vendedor_disenador→vendedor`, `ayudante_general_bodega→auxiliar_bodega`, `tecnico_servicios→tecnico`, `garantias_servicios→tecnico_garantias`
 * (migración 2026_10_25_200000). El resto de las claves ya era genérico.
 */
final class RoleKeys
{
    public const SUPERADMIN = 'superadmin';

    public const MANAGEMENT = 'management';

    public const LIDER_ADMIN_CONTAB = 'lider_admin_contab';

    public const ASISTENTE_ADMINISTRATIVA = 'asistente_administrativa';

    public const LIDER_COMPRAS = 'lider_compras';

    public const LIDER_BODEGA = 'lider_bodega';

    public const ASISTENTE_BODEGA = 'asistente_bodega';

    public const AUXILIAR_BODEGA = 'auxiliar_bodega';

    public const TRANSPORTE = 'transporte';

    public const LIDER_SERVICIOS = 'lider_servicios';

    public const TECNICO = 'tecnico';

    public const TECNICO_GARANTIAS = 'tecnico_garantias';

    public const VENDEDOR = 'vendedor';

    public const LIDER_OPERACIONES = 'lider_operaciones';

    /** @return list<string> las 14 claves del catálogo. */
    public static function all(): array
    {
        return [
            self::SUPERADMIN, self::MANAGEMENT, self::LIDER_ADMIN_CONTAB, self::ASISTENTE_ADMINISTRATIVA, self::LIDER_COMPRAS, self::LIDER_BODEGA,
            self::ASISTENTE_BODEGA, self::AUXILIAR_BODEGA, self::TRANSPORTE, self::LIDER_SERVICIOS, self::TECNICO, self::TECNICO_GARANTIAS,
            self::VENDEDOR, self::LIDER_OPERACIONES,
        ];
    }
}
