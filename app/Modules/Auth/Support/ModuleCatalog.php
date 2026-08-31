<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Models\Module;

/**
 * Los 7 módulos originales de negocio (SCRUM-144) como constantes con nombre — siguen existiendo
 * porque hay código real acoplado a su clave específica (ej. `ModuleCatalog::BODEGA` en
 * `BodegaHomeController`/`BodegaHomeService`, `ModuleCatalog::COMPRAS` en
 * `UatVisibilityService::isModuleHidden()`) — eso no cambia con esta clase, un módulo nuevo que
 * se agregue después no necesita (ni debería necesitar) una constante propia salvo que también
 * gane lógica de negocio dedicada.
 *
 * `keys()` dejó de ser un array hardcodeado (hallazgo de la auditoría del esquema de permisos,
 * ver Atlantic/CLAUDE.md sesión 2026-08-30, y ADR-006 Decisión 6 que documentaba la limitación
 * original) — ahora lee la tabla `modules` (ver ModuleController/ModuleService), así que un
 * módulo de negocio nuevo puede agregarse por configuración, sin deploy, mientras no necesite
 * controllers/rutas propios todavía (solo participar en la matriz de permisos vía
 * `role_module_visibility`/`security_level_module_permissions`/rutas `permission:{modulo}.*`).
 */
final class ModuleCatalog
{
    public const VENTAS_DISENO = 'ventas_diseno';

    public const COMPRAS = 'compras';

    public const BODEGA = 'bodega';

    public const SERVICIOS = 'servicios';

    public const ADMIN_CONTAB = 'admin_contab';

    public const GERENCIA = 'gerencia';

    public const OPERACIONES = 'operaciones';

    /** @return list<string> */
    public static function keys(): array
    {
        /** @var list<string> */
        return Module::where('is_active', true)->orderBy('sort_order')->pluck('key')->all();
    }
}
