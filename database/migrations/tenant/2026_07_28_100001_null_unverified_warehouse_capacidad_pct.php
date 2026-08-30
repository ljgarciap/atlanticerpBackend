<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-452 — QA (marly.rangel, 2026-07-22) rechazó el KPI "% de capacidad usada" por
 * contradicción visible: Bodega Central mostraba 68% con 0 productos/0 unidades (mismo patrón en
 * Showroom Obarrio 40%, Showroom SM 25%, Merma 12%, Reclamos y Devoluciones 18%).
 *
 * Causa raíz (Pre-QA 2026-07-28): estos valores fueron hardcodeados como constantes de ejemplo
 * directamente en la migración 2026_07_21_100019_replace_bodega_warehouses_with_physical_catalog
 * — no derivan de stock real, no vienen de ninguna medición física registrada, y no existe (ni
 * existió nunca) un endpoint de escritura para `capacidad_pct` (`WarehouseController` es
 * solo-lectura, confirmado). Son indistinguibles de un número inventado, y el propio ticket (RN1)
 * ya define el patrón correcto para "capacidad sin base confiable": "Sin dato (pendiente)", igual
 * que Zona Libre/Showroom Cliente. Esta migración extiende ese mismo tratamiento a las 5 bodegas
 * con valor no verificable, en vez de forzar 0% (que sería inventar una correlación con stock que
 * el ticket nunca pidió) o dejar un número que nadie puede respaldar.
 *
 * `responsable` (aparece "—" en las 7 bodegas) NO se toca acá — es un NULL intencional, ya
 * documentado en la migración de origen: Analista todavía no decidió si la columna es FK a
 * usuario o texto libre. Queda como decisión pendiente de Analista/PM, no como bug de este fix.
 *
 * Seguimiento real pendiente (fuera de alcance de esta migración): construir el CRUD de admin
 * para que un superadmin pueda configurar `capacidad_pct` con un valor real y auditable — hoy no
 * existe ningún camino legítimo para setearlo, ver hallazgo completo en el comentario de Jira.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    private const UNVERIFIED_NAMES = [
        'Bodega Central', 'Showroom Obarrio', 'Showroom SM', 'Merma', 'Reclamos y Devoluciones',
    ];

    public function up(): void
    {
        DB::table($this->table('warehouses'))
            ->whereIn('name', self::UNVERIFIED_NAMES)
            ->update(['capacidad_pct' => null]);
    }

    public function down(): void
    {
        $restore = [
            'Bodega Central'          => 68,
            'Showroom Obarrio'        => 40,
            'Showroom SM'             => 25,
            'Merma'                   => 12,
            'Reclamos y Devoluciones' => 18,
        ];

        foreach ($restore as $name => $pct) {
            DB::table($this->table('warehouses'))->where('name', $name)->update(['capacidad_pct' => $pct]);
        }
    }
};
