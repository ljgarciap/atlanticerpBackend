<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserAdditionalRole;
use Illuminate\Database\Eloquent\Collection;

/**
 * ADR-006, Decisión 10 — CRUD del catálogo de roles + matriz de visibilidad por módulo, exclusivo
 * de `permission:superadmin.all` (cerrado 2026-07-11, pregunta técnica (b)2: NO comparte
 * `security.levels` como proponía la versión anterior del ADR).
 */
final class RoleService
{
    /** @return Collection<int, Role> */
    public function listAll(): Collection
    {
        return Role::where('is_active', true)->orderBy('name')->get();
    }

    public function findOrFail(int $id): Role
    {
        return Role::findOrFail($id);
    }

    /** @param array{key: string, name: string, default_security_level_id?: int|null} $data */
    public function create(array $data): Role
    {
        return Role::create([
            'key'                       => $data['key'],
            'name'                      => $data['name'],
            'is_superadmin'             => false,
            'is_system'                 => false,
            'is_active'                 => true,
            'default_security_level_id' => $data['default_security_level_id'] ?? null,
        ]);
    }

    /** @param array{name?: string, default_security_level_id?: int|null} $data */
    public function update(Role $role, array $data): Role
    {
        if ($role->is_system) {
            throw new \DomainException('No se puede editar un rol de sistema.');
        }

        if (isset($data['name'])) {
            $role->name = $data['name'];
        }
        if (array_key_exists('default_security_level_id', $data)) {
            $role->default_security_level_id = $data['default_security_level_id'];
        }
        $role->save();

        return $role;
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw new \DomainException('No se puede borrar un rol de sistema.');
        }
        if (User::where('role_id', $role->id)->exists()) {
            throw new \DomainException('No se puede borrar un rol con usuarios asignados.');
        }
        if (UserAdditionalRole::where('role_id', $role->id)->exists()) {
            throw new \DomainException('No se puede borrar un rol asignado como rol adicional de algún usuario.');
        }

        $role->delete();
    }

    /** @return Collection<int, RoleModuleVisibility> */
    public function getVisibility(Role $role): Collection
    {
        return RoleModuleVisibility::where('role_id', $role->id)->get();
    }

    /**
     * @param list<array{module: string, can_view: int, can_view_team: bool}> $rows
     * @return Collection<int, RoleModuleVisibility>
     */
    public function upsertVisibility(Role $role, array $rows): Collection
    {
        foreach ($rows as $row) {
            RoleModuleVisibility::updateOrCreate(
                ['role_id' => $role->id, 'module' => $row['module']],
                ['can_view' => $row['can_view'], 'can_view_team' => $row['can_view_team']],
            );
        }

        return $this->getVisibility($role);
    }
}
