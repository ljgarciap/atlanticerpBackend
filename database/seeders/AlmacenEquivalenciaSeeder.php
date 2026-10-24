<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Models\AlmacenEquivalencia;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SCRUM-1136 F6 — siembra `legacy.almacen_equivalencias` con el mapeo CONFIRMADO por Luis (2026-10-15): AL2/B1/B2 (Excel de inventario del 13-oct) y ALX/ALY/ALR.
 * La bodega se resuelve por NOMBRE (los ids difieren entre dev/test/prod); si falta o es ambigua el seeder falla ANTES de escribir nada.
 *
 * Idempotente y «solo-si-no-existe» (como `CoreUserSeeder`): nunca pisa una fila existente de `codalmacen_icg`, así un mapeo corregido a mano no se deshace.
 *
 * NO está en `DatabaseSeeder` a propósito: falla ruidosamente si una bodega no existe, y un `db:seed` de cada deploy no debe romperse por eso; es una acción
 * puntual por entorno (`php artisan db:seed --class=AlmacenEquivalenciaSeeder`), antes de `legacy:cargar-stock-bodegas`. Sin A1/ALM/AL1/AL3: sin equivalente (decisión de Luis).
 */
class AlmacenEquivalenciaSeeder extends Seeder
{
    /** @var array<string, string> codalmacen ICG => nombre de bodega en AtlanticERP */
    public const MAPA = [
        'AL2' => 'Showroom Obarrio',
        'B1'  => 'Showroom Cliente',
        'B2'  => 'Showroom SM',
        'ALX' => 'Merma',
        'ALY' => 'Reclamos y Devoluciones',
        'ALR' => 'Reserva Servicios',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant === null) {
            throw new RuntimeException('Tenant "atlantic" no existe.');
        }

        $tenant->makeCurrent();
        try {
            $ids = [];
            foreach (self::MAPA as $codigo => $nombre) {
                $bodegas = Warehouse::query()->where('name', $nombre)->get();
                if ($bodegas->count() !== 1) {
                    throw new RuntimeException("Se esperaba exactamente UNA bodega \"{$nombre}\" (para {$codigo}) y hay {$bodegas->count()}: no se siembra nada.");
                }
                $ids[$codigo] = (int) $bodegas->first()->id;
            }

            foreach (self::MAPA as $codigo => $nombre) {
                AlmacenEquivalencia::query()->firstOrCreate(
                    ['codalmacen_icg' => $codigo],
                    ['warehouse_id' => $ids[$codigo], 'estado' => AlmacenEquivalencia::ESTADO_CONFIRMADO, 'nota' => 'Confirmado por Luis 2026-10-15 (SCRUM-1136 F6)'],
                );
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
