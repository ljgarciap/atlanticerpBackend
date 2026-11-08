<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 29 (grupo 2) — escribir ubicaciones físicas de una bodega dejaba de ser «cualquiera con `bodega.write`» (incluidos el
 * auxiliar y el repartidor). Pasa a un permiso angosto, asignado por `security_level`: Líder de Bodega y Asistente de Bodega
 * (superadmin entra por `superadmin.all`).
 *
 * Chequeo de fuga (lección de la Fase 2): el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo.
 * El Líder de Bodega tiene un nivel propio; el Asistente comparte el suyo con el Auxiliar y el Transporte, así que recibe un nivel
 * nuevo clonado del compartido antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'bodega.locations.manage';

    private const NIVEL_ASISTENTE = 'Team — Asistente de Bodega';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => self::KEY, 'module' => 'bodega', 'label' => 'Crear y desactivar ubicaciones físicas de una bodega (Líder y Asistente de Bodega)',
            'sort_order' => 48, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Líder de Bodega: nivel propio (se verifica).
        $lider = DB::table($this->table('roles'))->where('key', 'lider_bodega')->first();
        if ($lider !== null && $lider->default_security_level_id !== null) {
            $compartido = DB::table($this->table('roles'))
                ->where('default_security_level_id', $lider->default_security_level_id)->where('key', '!=', 'lider_bodega')->count();
            if ($compartido > 0) {
                throw new RuntimeException('El nivel de «lider_bodega» lo comparte otro rol: darle un nivel propio antes de escribir permisos finos.');
            }
            $this->mergeInto((int) $lider->default_security_level_id, [self::KEY]);
        }

        // Asistente de Bodega: nivel propio clonado del que comparte con Auxiliar y Transporte.
        $asistente = DB::table($this->table('roles'))->where('key', 'asistente_bodega')->first();
        if ($asistente === null || $asistente->default_security_level_id === null) {
            return;
        }
        $origen = DB::table($this->table('security_levels'))->where('id', $asistente->default_security_level_id)->first();
        if ($origen === null || DB::table($this->table('security_levels'))->where('name', self::NIVEL_ASISTENTE)->exists()) {
            return;
        }

        /** @var list<string> $permisos */
        $permisos = json_decode($origen->permissions, true) ?? [];
        $nuevoId = DB::table($this->table('security_levels'))->insertGetId([
            'level' => ((int) DB::table($this->table('security_levels'))->max('level')) + 1,
            'name' => self::NIVEL_ASISTENTE,
            'description' => "Igual que «{$origen->name}», con la capacidad de administrar ubicaciones propia del Asistente de Bodega (no compartida con Auxiliar ni Transporte).",
            'permissions' => json_encode(array_values(array_unique([...$permisos, self::KEY]))),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (DB::table($this->table('security_level_module_permissions'))->where('security_level_id', $origen->id)->get() as $row) {
            DB::table($this->table('security_level_module_permissions'))->insert([
                'security_level_id' => $nuevoId, 'module' => $row->module, 'can_edit' => $row->can_edit, 'can_approve' => $row->can_approve,
            ]);
        }

        DB::table($this->table('roles'))->where('key', 'asistente_bodega')->update(['default_security_level_id' => $nuevoId]);
        DB::table($this->table('users'))
            ->where('role_id', $asistente->id)->where('security_level_id', $origen->id)
            ->update(['security_level_id' => $nuevoId]);
    }

    public function down(): void
    {
        $nivel = DB::table($this->table('security_levels'))->where('name', self::NIVEL_ASISTENTE)->first();
        $asistente = DB::table($this->table('roles'))->where('key', 'asistente_bodega')->first();
        if ($nivel !== null && $asistente !== null) {
            $origen = DB::table($this->table('roles'))->where('key', 'auxiliar_bodega')->value('default_security_level_id');
            if ($origen !== null) {
                DB::table($this->table('users'))->where('role_id', $asistente->id)->where('security_level_id', $nivel->id)->update(['security_level_id' => $origen]);
                DB::table($this->table('roles'))->where('key', 'asistente_bodega')->update(['default_security_level_id' => $origen]);
            }
            DB::table($this->table('security_level_module_permissions'))->where('security_level_id', $nivel->id)->delete();
            DB::table($this->table('security_levels'))->where('id', $nivel->id)->delete();
        }

        $lider = DB::table($this->table('roles'))->where('key', 'lider_bodega')->first();
        if ($lider !== null && $lider->default_security_level_id !== null) {
            $row = DB::table($this->table('security_levels'))->where('id', $lider->default_security_level_id)->first();
            /** @var list<string> $current */
            $current = json_decode($row->permissions, true) ?? [];
            DB::table($this->table('security_levels'))->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_diff($current, [self::KEY])))]);
        }

        DB::table($this->table('permission_catalog'))->where('key', self::KEY)->delete();
    }

    /** @param list<string> $keys */
    private function mergeInto(int $levelId, array $keys): void
    {
        $row = DB::table($this->table('security_levels'))->where('id', $levelId)->first();
        /** @var list<string> $current */
        $current = json_decode($row->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $levelId)->update(['permissions' => json_encode(array_values(array_unique([...$current, ...$keys])))]);
    }
};
