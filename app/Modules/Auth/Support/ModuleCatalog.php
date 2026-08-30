<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * Catálogo de 7 módulos de negocio (SCRUM-144), estático en código — ver ADR-006, Decisión 6.
 * "Ventas & Diseño" = App\Modules\VentasDiseno exclusivamente; App\Modules\CRM queda fuera de
 * este catálogo por ahora (confirmado por Luis, 2026-07-11). Reportes/métricas de CRM NO son un
 * módulo más de esta lista — cruzan todos los módulos, ver ADR-006 Decisión 6 nota de extensión,
 * sin resolver todavía.
 */
final class ModuleCatalog
{
    public const VENTAS_DISENO = 'ventas_diseno';
    public const COMPRAS       = 'compras';
    public const BODEGA        = 'bodega';
    public const SERVICIOS     = 'servicios';
    public const ADMIN_CONTAB  = 'admin_contab';
    public const GERENCIA      = 'gerencia';
    public const OPERACIONES   = 'operaciones';

    /** @return list<string> */
    public static function keys(): array
    {
        return [
            self::VENTAS_DISENO,
            self::COMPRAS,
            self::BODEGA,
            self::SERVICIOS,
            self::ADMIN_CONTAB,
            self::GERENCIA,
            self::OPERACIONES,
        ];
    }
}
