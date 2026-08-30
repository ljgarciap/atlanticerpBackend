<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, string> */
    private const ROLE_MAP = [
        'gerencia'  => 'management',
        'disenador' => 'designer',
        'lider'     => 'supervisor',
        'electrico' => 'electrician',
    ];

    public function up(): void
    {
        $userRolesTable = $this->table('user_roles');
        $usersTable     = $this->table('users');

        Schema::create($userRolesTable, function (Blueprint $table) use ($usersTable) {
            $table->unsignedBigInteger('user_id');
            $table->string('role', 30);
            $table->primary(['user_id', 'role']);
            $table->foreign('user_id')->references('id')->on($usersTable)->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE {$userRolesTable} ADD CONSTRAINT user_roles_role_check CHECK (role IN ('superadmin','management','designer','supervisor','electrician'))");

        // Migrate existing rol values (Spanish) → user_roles (English)
        foreach (self::ROLE_MAP as $old => $new) {
            DB::statement("INSERT INTO {$userRolesTable} (user_id, role) SELECT id, '{$new}' FROM {$usersTable} WHERE rol = '{$old}'");
        }

        // Drop the now-redundant rol column
        Schema::table($usersTable, function (Blueprint $table) {
            $table->dropColumn('rol');
        });
    }

    public function down(): void
    {
        $userRolesTable = $this->table('user_roles');
        $usersTable     = $this->table('users');

        // Re-add rol as varchar before dropping the pivot
        Schema::table($usersTable, function (Blueprint $table) {
            $table->string('rol', 30)->nullable();
        });

        // Populate rol from the first assigned role per user (reverse map)
        $reverseMap = array_flip(self::ROLE_MAP);
        foreach ($reverseMap as $new => $old) {
            DB::statement("UPDATE {$usersTable} u SET rol = '{$old}' FROM {$userRolesTable} ur WHERE ur.user_id = u.id AND ur.role = '{$new}' AND u.rol IS NULL");
        }

        Schema::dropIfExists($userRolesTable);
    }
};
