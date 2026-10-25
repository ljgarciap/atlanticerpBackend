<?php

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoreUserSeeder extends Seeder
{
    /**
     * Core users that must exist in every environment.
     * Password default = email. Never overwritten if the user already changed it.
     *
     * @var list<array{first_name: string, last_name: string, email: string, role_key: string, level: int}>
     */
    private const USERS = [
        // Owners / Super Admins — level 10
        ['first_name' => 'Superadmin',   'last_name' => 'Test',      'email' => 'superadmin@test.com', 'role_key' => 'superadmin', 'level' => 10],
        ['first_name' => 'Superadmin',     'last_name' => 'Test 2',   'email' => 'superadmin2@test.com', 'role_key' => 'superadmin', 'level' => 10],
        ['first_name' => 'Superadmin',   'last_name' => 'Test 3', 'email' => 'superadmin3@test.com',          'role_key' => 'superadmin', 'level' => 10],
        // Managers — level 9
        ['first_name' => 'Gerencia',  'last_name' => 'Test',    'email' => 'gerencia@test.com',    'role_key' => 'management', 'level' => 9],
        ['first_name' => 'Gerencia',    'last_name' => 'Test 2',  'email' => 'gerencia2@test.com',        'role_key' => 'management', 'level' => 9],
        ['first_name' => 'Gerencia',     'last_name' => 'Test 3',   'email' => 'gerencia3@test.com',    'role_key' => 'management', 'level' => 9],
        ['first_name' => 'Gerencia',    'last_name' => 'Test 4',   'email' => 'gerencia4@test.com', 'role_key' => 'management', 'level' => 9],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CoreUserSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $rolesTable     = "{$tenant->slug}_auth.roles";
            $securityLevels = "{$tenant->slug}_auth.security_levels";
            $fallbackLevelId = (int) (DB::table($securityLevels)->where('level', 5)->value('id') ?? 1);

            foreach (self::USERS as $data) {
                $levelId = (int) (DB::table($securityLevels)->where('level', $data['level'])->value('id') ?? $fallbackLevelId);

                /** @var User|null $existing */
                $existing = User::query()->where('email', $data['email'])->first();

                if ($existing === null) {
                    /** @var User $user */
                    $user = User::query()->create([
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'email' => $data['email'],
                        'password_hash' => $data['email'],
                        'is_active' => true,
                        'security_level_id' => $levelId,
                        // Fase 2: `role_id` es el único mecanismo de rol; solo para usuarios nuevos (los existentes gestionan su rol por la UI).
                        'role_id' => DB::table($rolesTable)->where('key', $data['role_key'])->value('id'),
                        'extra_permissions' => [],
                    ]);
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
