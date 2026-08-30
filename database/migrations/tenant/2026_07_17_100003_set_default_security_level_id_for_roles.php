<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — mapeo rol→nivel (2026-07-09). `roles.default_security_level_id` quedó NULL en Fase A
 * a propósito (2026_07_11_100000) — este es el mapeo que faltaba, ya aprobado por Luis.
 *
 * Es el DEFAULT del rol, no una asignación forzada por usuario — Decisión 5: sirve para
 * pre-llenar la UI y para la vista de "excepciones" (usuario.security_level_id !=
 * rol.default_security_level_id). El caso de Whil (mismo rol Gerencia, nivel Gerencia
 * Restringida en vez del default Gerencia Total) se resuelve en la migración de backfill de
 * usuarios, no acá — este archivo solo fija el default por rol.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    // Nivel 12 (Gerencia Restringida) no aparece acá a propósito: no es default de ningún rol,
    // solo override individual de Whil dentro del rol Gerencia (ver migración de backfill).
    private const GERENCIA_TOTAL     = 11;
    private const EDITOR_SIN_APROBAR = 13;
    private const SOLO_VER           = 14;

    /** @var array<string, int> rol key => level */
    private array $defaults = [
        'gerencia'                 => self::GERENCIA_TOTAL,
        'lider_admin_contab'       => self::EDITOR_SIN_APROBAR,
        'asistente_administrativa' => self::SOLO_VER,
        'lider_compras'            => self::SOLO_VER,
        'lider_bodega'             => self::EDITOR_SIN_APROBAR,
        'asistente_bodega'         => self::SOLO_VER,
        'ayudante_general_bodega'  => self::SOLO_VER,
        'transporte'               => self::SOLO_VER,
        'lider_servicios'          => self::EDITOR_SIN_APROBAR,
        'tecnico_servicios'        => self::SOLO_VER,
        'garantias_servicios'      => self::SOLO_VER,
        'vendedor_disenador'       => self::EDITOR_SIN_APROBAR,
        'lider_operaciones'        => self::SOLO_VER,
        // 'superadmin' usa el Nivel 10 existente (Super Admin), no uno de los 4 nuevos.
    ];

    public function up(): void
    {
        $levelIds = DB::table($this->table('security_levels'))
            ->whereIn('level', [11, 12, 13, 14, 10])
            ->pluck('id', 'level');

        foreach ($this->defaults as $roleKey => $level) {
            DB::table($this->table('roles'))
                ->where('key', $roleKey)
                ->update(['default_security_level_id' => $levelIds[$level]]);
        }

        DB::table($this->table('roles'))
            ->where('key', 'superadmin')
            ->update(['default_security_level_id' => $levelIds[10]]);
    }

    public function down(): void
    {
        DB::table($this->table('roles'))->update(['default_security_level_id' => null]);
    }
};
