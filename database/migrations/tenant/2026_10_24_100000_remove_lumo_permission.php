<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 del aislamiento del núcleo (docs/specs/fase1-limpieza-nucleo.md, bloque A) — Lumo sale del producto.
 * Retira el permiso `lumo.view` del catálogo, de los niveles de seguridad y de los permisos individuales de los
 * usuarios. IRREVERSIBLE en datos: `down()` solo restaura la fila del catálogo (no a quién se había concedido).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'lumo.view';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->where('key', self::KEY)->delete();

        foreach (['security_levels' => 'permissions', 'users' => 'extra_permissions'] as $table => $column) {
            DB::statement(sprintf(
                'UPDATE %1$s SET %2$s = COALESCE((SELECT jsonb_agg(e) FROM jsonb_array_elements(%2$s) e WHERE e <> to_jsonb(?::text)), \'[]\'::jsonb) WHERE %2$s @> to_jsonb(?::text)::jsonb',
                $this->table($table),
                $column,
            ), [self::KEY, self::KEY]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => self::KEY, 'module' => 'lumo', 'label' => 'Ver bitácoras de LUMO',
            'sort_order' => 101, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
