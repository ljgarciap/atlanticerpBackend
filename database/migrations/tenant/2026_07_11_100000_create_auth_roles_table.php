<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006, Decisión 1 — Fase A. Reemplaza únicamente al enum `user_roles`/CHECK, NO a
 * `security_levels` (los dos ejes coexisten, ver Reconsideración del ADR). Aditiva: no toca
 * `user_roles` todavía (Fase C hace el cutover real y el backfill de `users.role_id`).
 *
 * `default_security_level_id` queda NULL para los 13 roles nuevos — todavía no hay mapeo
 * confirmado entre estos roles y los 10 security_levels existentes (1-10 son genéricos, no
 * corresponden 1:1 a "Líder Bodega", "Vendedor/Diseñador", etc.). Resolver ese mapeo (crear
 * niveles nuevos vía el POST /api/security-levels que agrega esta misma Fase A, o reutilizar
 * alguno existente) es un paso pendiente antes de Fase B — no se adivina acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, name: string, is_superadmin: bool, is_system: bool}> */
    private array $roles = [
        // Rol técnico de plataforma, fuera de los 13 de negocio — ver ADR-006 Decisión 3.
        ['key' => 'superadmin', 'name' => 'Superadmin', 'is_superadmin' => true, 'is_system' => true],

        // Los 13 roles de negocio de SCRUM-144. is_system=true en todos: son el catálogo que
        // definió el cliente, no se borran desde la UI (sí se pueden editar sus visibilidades).
        ['key' => 'gerencia',                   'name' => 'Gerencia',                       'is_superadmin' => false, 'is_system' => true],
        ['key' => 'lider_admin_contab',         'name' => 'Líder de Administración y Contabilidad', 'is_superadmin' => false, 'is_system' => true],
        ['key' => 'asistente_administrativa',   'name' => 'Asistente Administrativa',       'is_superadmin' => false, 'is_system' => true],
        ['key' => 'lider_compras',              'name' => 'Líder de Compras',               'is_superadmin' => false, 'is_system' => true],
        ['key' => 'lider_bodega',               'name' => 'Líder de Bodega',                'is_superadmin' => false, 'is_system' => true],
        ['key' => 'asistente_bodega',           'name' => 'Asistente de Bodega',            'is_superadmin' => false, 'is_system' => true],
        ['key' => 'ayudante_general_bodega',    'name' => 'Ayudante General de Bodega',     'is_superadmin' => false, 'is_system' => true],
        ['key' => 'transporte',                 'name' => 'Transporte',                     'is_superadmin' => false, 'is_system' => true],
        ['key' => 'lider_servicios',            'name' => 'Líder de Servicios',             'is_superadmin' => false, 'is_system' => true],
        ['key' => 'tecnico_servicios',          'name' => 'Técnico de Servicios',           'is_superadmin' => false, 'is_system' => true],
        ['key' => 'garantias_servicios',        'name' => 'Garantías de Servicios',         'is_superadmin' => false, 'is_system' => true],
        ['key' => 'vendedor_disenador',         'name' => 'Vendedor/Diseñador',             'is_superadmin' => false, 'is_system' => true],
        ['key' => 'lider_operaciones',          'name' => 'Líder de Operaciones',           'is_superadmin' => false, 'is_system' => true],
    ];

    public function up(): void
    {
        Schema::create($this->table('roles'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->boolean('is_superadmin')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('default_security_level_id')->nullable()
                ->constrained('security_levels')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach ($this->roles as $role) {
            DB::table($this->table('roles'))->insert([
                'key'           => $role['key'],
                'name'          => $role['name'],
                'is_superadmin' => $role['is_superadmin'],
                'is_system'     => $role['is_system'],
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('roles'));
    }
};
