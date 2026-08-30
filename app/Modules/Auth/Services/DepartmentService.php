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
    public function listAll(): Collection
    {
        return Department::where('is_active', true)->orderBy('name')->get();
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
