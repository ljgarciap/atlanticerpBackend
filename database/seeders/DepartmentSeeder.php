<?php

namespace Database\Seeders;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    /** @var list<string> */
    private const DEPARTMENTS = [
        'Gerencia',
        'Diseño',
        'Ventas',
        'Operaciones',
        'Instalaciones',
        'Administración',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — DepartmentSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $table = "{$tenant->slug}_auth.departments";

            foreach (self::DEPARTMENTS as $name) {
                DB::table($table)->insertOrIgnore([
                    'name'       => $name,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
