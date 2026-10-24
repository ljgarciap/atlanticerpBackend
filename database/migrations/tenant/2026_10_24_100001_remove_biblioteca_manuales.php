<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 del aislamiento del núcleo (docs/specs/fase1-limpieza-nucleo.md, bloque A) — la Biblioteca de Manuales sale
 * del producto. Retira el permiso `manuales.manage` (catálogo, niveles, permisos individuales) y los ajustes
 * `library_*` de `system_settings`. IRREVERSIBLE en datos: `down()` solo restaura la fila del catálogo; no devuelve a
 * quién se había concedido el permiso ni los ajustes `library_*` (se pierden, incluidos los valores editados).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'manuales.manage';

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

        DB::table($this->table('system_settings'))->where('key', 'like', 'library\_%')->delete();
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => self::KEY, 'module' => 'manuales', 'label' => 'Gestionar la biblioteca de manuales',
            'sort_order' => 102, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
