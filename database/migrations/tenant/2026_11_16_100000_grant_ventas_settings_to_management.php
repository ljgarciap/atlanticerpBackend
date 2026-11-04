<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 23 (#9 y #10) — Gerencia opera el negocio pero no podía ver ni editar precios, tramos de comisión ni metas, y nadie
 * podía marcar como pagada la comisión de los vendedores sin una concesión a mano. Capacidad de rol completo → `security_level`:
 *   - `ventas_diseno.pricing.configure` (precios y umbral de margen de Pedidos),
 *   - `ventas_diseno.reports.configure` (metas, probabilidades y, desde esta fase, tramos de comisión),
 *   - `ventas_diseno.commissions.mark_paid` (pagar la comisión de vendedores).
 * Chequeo de fuga: el nivel 9 («Admin») es exclusivo del rol `management` (verificado en 2026_08_30_100003), así que no se
 * filtra a ningún otro rol.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEYS = ['ventas_diseno.pricing.configure', 'ventas_diseno.reports.configure', 'ventas_diseno.commissions.mark_paid'];

    public function up(): void
    {
        $row = DB::table($this->table('security_levels'))->where('level', 9)->first();
        if ($row === null) {
            return;
        }
        /** @var list<string> $current */
        $current = json_decode((string) $row->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $row->id)
            ->update(['permissions' => json_encode(array_values(array_unique(array_merge($current, self::KEYS))))]);
    }

    public function down(): void
    {
        $row = DB::table($this->table('security_levels'))->where('level', 9)->first();
        if ($row === null) {
            return;
        }
        /** @var list<string> $current */
        $current = json_decode((string) $row->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $row->id)
            ->update(['permissions' => json_encode(array_values(array_diff($current, self::KEYS)))]);
    }
};
