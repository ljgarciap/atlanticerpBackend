<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SecurityLevelModulePermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class SecurityLevelService
{
    /** @return LengthAwarePaginator<int, SecurityLevel> */
    public function list(string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $query = SecurityLevel::orderBy('level');
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }
        return $query->paginate($perPage);
    }

    /** @return Collection<int, SecurityLevel> */
    public function listAll(): Collection
    {
        return SecurityLevel::where('is_active', true)->orderBy('level')->get();
    }

    public function findOrFail(int $id): SecurityLevel
    {
        return SecurityLevel::findOrFail($id);
    }

    /**
     * ADR-006, Decisión 9 — sin esto, asignar un nivel de capacidad distinto a un usuario puntual
     * (ej. el caso Whil) requeriría una migración manual por cada excepción.
     *
     * @param array{
     *   name: string,
     *   description?: string|null,
     *   permissions?: list<string>,
     * } $data
     */
    public function create(array $data): SecurityLevel
    {
        $nextLevel = (int) (SecurityLevel::max('level') ?? 0) + 1;

        return SecurityLevel::create([
            'level'       => $nextLevel,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'] ?? [],
            'is_active'   => true,
        ]);
    }

    /**
     * @param array{
     *   name?: string,
     *   description?: string|null,
     *   permissions?: list<string>,
     * } $data
     */
    public function update(SecurityLevel $level, array $data): SecurityLevel
    {
        if (isset($data['name'])) {
            $level->name = (string) $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $level->description = $data['description'] !== null ? (string) $data['description'] : null;
        }
        if (isset($data['permissions'])) {
            $level->permissions = $data['permissions'];
        }
        $level->save();
        return $level;
    }

    public function setActive(SecurityLevel $level, bool $active): SecurityLevel
    {
        $level->is_active = $active;
        $level->save();
        return $level;
    }

    /**
     * ADR-006, Decisión 10 — matriz de capacidad (Crear-Editar/Aprobar) por módulo de un nivel.
     *
     * @return Collection<int, SecurityLevelModulePermission>
     */
    public function getModulePermissions(SecurityLevel $level): Collection
    {
        return SecurityLevelModulePermission::where('security_level_id', $level->id)->get();
    }

    /**
     * @param list<array{module: string, can_edit: bool, can_approve: bool}> $rows
     * @return Collection<int, SecurityLevelModulePermission>
     */
    public function upsertModulePermissions(SecurityLevel $level, array $rows): Collection
    {
        foreach ($rows as $row) {
            SecurityLevelModulePermission::updateOrCreate(
                ['security_level_id' => $level->id, 'module' => $row['module']],
                ['can_edit' => $row['can_edit'], 'can_approve' => $row['can_approve']],
            );
        }

        return $this->getModulePermissions($level);
    }
}
