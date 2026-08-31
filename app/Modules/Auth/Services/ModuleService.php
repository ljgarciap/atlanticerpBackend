<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\PermissionCatalog;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\SecurityLevelModulePermission;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD del catálogo de módulos de negocio, exclusivo de `permission:superadmin.all` — mismo
 * criterio que `RoleService` (ADR-006, Decisión 10): crear/borrar un módulo es una operación de
 * mayor blast radius que editar una fila de matriz, afecta a todos los roles/niveles a la vez.
 */
final class ModuleService
{
    /** @return Collection<int, Module> */
    public function listAll(): Collection
    {
        return Module::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findOrFail(int $id): Module
    {
        return Module::findOrFail($id);
    }

    /** @param array{key: string, name: string, sort_order?: int} $data */
    public function create(array $data): Module
    {
        return Module::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'is_system' => false,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /** @param array{name?: string, sort_order?: int, is_active?: bool} $data */
    public function update(Module $module, array $data): Module
    {
        if ($module->is_system && array_key_exists('is_active', $data) && $data['is_active'] === false) {
            throw new \DomainException('No se puede desactivar un módulo de sistema.');
        }

        if (isset($data['name'])) {
            $module->name = $data['name'];
        }
        if (isset($data['sort_order'])) {
            $module->sort_order = $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $module->is_active = $data['is_active'];
        }
        $module->save();

        return $module;
    }

    public function delete(Module $module): void
    {
        if ($module->is_system) {
            throw new \DomainException('No se puede borrar un módulo de sistema.');
        }
        if (RoleModuleVisibility::where('module', $module->key)->exists()) {
            throw new \DomainException('No se puede borrar un módulo con visibilidad de rol asignada.');
        }
        if (SecurityLevelModulePermission::where('module', $module->key)->exists()) {
            throw new \DomainException('No se puede borrar un módulo con permisos de nivel asignados.');
        }
        if (PermissionCatalog::where('module', $module->key)->exists()) {
            throw new \DomainException('No se puede borrar un módulo con permisos en el catálogo.');
        }

        $module->delete();
    }
}
