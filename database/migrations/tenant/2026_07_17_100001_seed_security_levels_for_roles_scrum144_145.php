<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — mapeo rol→nivel (2026-07-09, aprobado por Luis). Los 10 security_levels existentes
 * son genéricos (Read Only..Super Admin, vocabulario `crm.*`) y no corresponden 1:1 a los 13
 * roles de negocio de SCRUM-144 — se crean 4 niveles nuevos en vez de uno por rol, apoyándose en
 * que el rol ya es el techo de alcance (Decisión 7, defensa en profundidad): un nivel puede
 * "poder editar" en un módulo que su rol no ve, sin que eso otorgue nada realmente.
 *
 * `permissions` (JSONB, escape hatch legado) queda vacío en los 4 — no se les asigna vocabulario
 * `crm.*`, no son niveles de CRM. Casos individuales (ej. restricción de margen del
 * Vendedor/Diseñador) siguen viviendo en `extra_permissions` por usuario, no acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{level: int, name: string, description: string}> */
    private array $levels = [
        [
            'level'       => 11,
            'name'        => 'Gerencia Total',
            'description' => 'Crear-Editar y Aprobar en los 7 módulos. Mark, David, Daniela.',
        ],
        [
            'level'       => 12,
            'name'        => 'Gerencia Restringida',
            'description' => 'Crear-Editar en los 7 módulos; Aprobar solo en Ventas & Diseño. '
                . 'Excepción individual de Whil dentro del rol Gerencia — ver ADR-006 Decisión 7.',
        ],
        [
            'level'       => 13,
            'name'        => 'Editor sin aprobar',
            'description' => 'Crear-Editar en los 7 módulos, sin Aprobar. El rol del usuario '
                . 'limita a qué módulo aplica realmente (defensa en profundidad).',
        ],
        [
            'level'       => 14,
            'name'        => 'Solo Ver',
            'description' => 'Sin Crear-Editar ni Aprobar en ningún módulo — capacidad de solo '
                . 'lectura sobre lo que el rol del usuario le da visibilidad.',
        ],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->levels as $level) {
            DB::table($this->table('security_levels'))->insert([
                'level'       => $level['level'],
                'name'        => $level['name'],
                'description' => $level['description'],
                'permissions' => json_encode([]),
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('security_levels'))->whereIn('level', [11, 12, 13, 14])->delete();
    }
};
