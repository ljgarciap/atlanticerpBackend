<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Department;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class DepartmentService
{
    /** @return LengthAwarePaginator<int, Department> */
    public function list(string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $query = Department::orderBy('name');
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }
        return $query->paginate($perPage);
    }

    /** @return Collection<int, Department> */
    public function listAll(?int $incluirId = null): Collection
    {
        // Fase 31b — el selector conserva el departamento propio del usuario aunque esté desactivado.
        return Department::where(function ($q) use ($incluirId): void {
            $q->where('is_active', true);
            if ($incluirId !== null) {
                $q->orWhere('id', $incluirId);
            }
        })->orderBy('name')->get();
    }

    /** Fase 31b — mueve (o deja sin departamento) a los usuarios de uno; devuelve cuántos se movieron. */
    public function reassignUsers(Department $dept, ?int $toId): int
    {
        return \App\Models\User::where('department_id', $dept->id)->update(['department_id' => $toId]);
    }

    public function userCount(Department $dept): int
    {
        return \App\Models\User::where('department_id', $dept->id)->count();
    }

    public function findOrFail(int $id): Department
    {
        return Department::findOrFail($id);
    }

    public function create(string $name): Department
    {
        /** @var Department $dept */
        $dept = Department::create(['name' => $name, 'is_active' => true]);
        return $dept;
    }

    public function update(Department $dept, string $name): Department
    {
        $dept->name = $name;
        $dept->save();
        return $dept;
    }

    public function setActive(Department $dept, bool $active): Department
    {
        $dept->is_active = $active;
        $dept->save();
        return $dept;
    }
}
