<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            // Reset administrativo de contraseña (docs/runbooks/reset-password.md): mientras sea true,
            // AuthenticateJwt solo deja pasar GET /api/auth/me y PUT /api/auth/password (403
            // `password_change_required` en el resto). Lo limpia el cambio de contraseña propio.
            $table->boolean('must_change_password')->default(false)->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
