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
     * @var list<array{first_name: string, last_name: string, email: string, roles: list<string>, level: int}>
     */
    private const USERS = [
        // Owners / Super Admins — level 10
        ['first_name' => 'Andres',   'last_name' => 'Loi',      'email' => 'andres.loi@atlantic.com.pa', 'roles' => ['superadmin'], 'level' => 10],
        ['first_name' => 'Luis',     'last_name' => 'Garcia',   'email' => 'luis.garcia@atlantic.com.pa','roles' => ['superadmin'], 'level' => 10],
        ['first_name' => 'Luis J',   'last_name' => 'Garcia P', 'email' => 'lujogarpin78@gmail.com',          'roles' => ['superadmin'], 'level' => 10],
        // Managers — level 9
        ['first_name' => 'Daniela',  'last_name' => 'Amaya',    'email' => 'daniela@atlantic.com.pa',    'roles' => ['management'], 'level' => 9],
        ['first_name' => 'David',    'last_name' => 'Bresler',  'email' => 'david@grupolafayette.com',        'roles' => ['management'], 'level' => 9],
        ['first_name' => 'Mark',     'last_name' => 'Bekhar',   'email' => 'mbekhar@atlantic.com.pa',    'roles' => ['management'], 'level' => 9],
        ['first_name' => 'Marly',    'last_name' => 'Rangel',   'email' => 'marly.rangel@atlantic.com.pa','roles' => ['management'], 'level' => 9],
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
            $userRolesTable  = "{$tenant->slug}_auth.user_roles";
            $securityLevels  = "{$tenant->slug}_auth.security_levels";
            $fallbackLevelId = (int) (DB::table($securityLevels)->where('level', 5)->value('id') ?? 1);

            foreach (self::USERS as $data) {
                $levelId = (int) (DB::table($securityLevels)->where('level', $data['level'])->value('id') ?? $fallbackLevelId);

                /** @var User|null $existing */
                $existing = User::query()->where('email', $data['email'])->first();

                if ($existing === null) {
                    /** @var User $user */
                    $user = User::query()->create([
                        'first_name'        => $data['first_name'],
                        'last_name'         => $data['last_name'],
                        'email'             => $data['email'],
                        'password_hash'     => $data['email'],
                        'is_active'         => true,
                        'security_level_id' => $levelId,
                        'extra_permissions' => [],
                    ]);

                    // Seed roles only for new users — existing users manage their own roles via UI
                    foreach ($data['roles'] as $role) {
                        DB::table($userRolesTable)->insertOrIgnore(['user_id' => $user->id, 'role' => $role]);
                    }
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
