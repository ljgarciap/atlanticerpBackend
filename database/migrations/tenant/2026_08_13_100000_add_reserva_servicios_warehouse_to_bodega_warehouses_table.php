<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-291 RN1/RN2, SCRUM-361) — agrega la 8va bodega física
 * al catálogo de `2026_07_21_100019_replace_bodega_warehouses_with_physical_catalog.php`: una
 * ubicación reservada para materiales/insumos de consumo de visitas técnicas de Servicios, DENTRO
 * de la misma tabla maestra `product_warehouse_stock` que ya usan Bodega y Compras (RN1: no es una
 * tabla de stock propia — "disponible" de Servicios = `product_warehouse_stock` filtrado por esta
 * bodega, RN2).
 *
 * Se inserta la fila (nunca se reemplaza el catálogo, a diferencia de la migración de 2026-07-21
 * que sí reemplazó las 5 bodegas viejas) — mismo criterio que cualquier alta nueva sobre un
 * catálogo ya poblado con datos reales.
 *
 * `capacidad_pct` queda NULL a propósito: a diferencia de las bodegas físicas reales (Bodega
 * Central, los Showrooms), "Reserva Servicios" no tiene una capacidad física propia que medir —
 * es un balde lógico dentro del mismo espacio físico de Bodega Central, igual que "Merma"/
 * "Reclamos y Devoluciones" son conceptualmente sub-cuentas, no vitrinas cuadradas. Se deja
 * `modo_detalle = ubicacion_exacta` (no `pendiente`/`cliente`) porque SÍ tiene una ubicación de
 * negocio bien definida y estable (a diferencia de Zona Libre, en tránsito, o Showroom Cliente,
 * cuya ubicación depende de qué cliente).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        DB::table($this->table('warehouses'))->insert([
            'name'          => 'Reserva Servicios',
            'responsable'   => null,
            'capacidad_pct' => null,
            'modo_detalle'  => 'ubicacion_exacta',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('warehouses'))->where('name', 'Reserva Servicios')->delete();
    }
};
