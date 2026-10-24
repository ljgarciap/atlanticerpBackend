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
            // SCRUM-1033 (A-02): momento del último cambio de contraseña PROPIO (PUT /auth/password). AuthenticateJwt rechaza
            // (401) todo access token emitido (`iat`) antes de este instante, igual que con `password_reset_at` (reset
            // administrativo, otra semántica: PasswordResetPolicy la usa para caducar la contraseña temporal, por eso no se
            // reutiliza). Precisión de microsegundos: el `iat` del JWT es de segundos.
            $table->timestampTz('password_changed_at', 6)->nullable()->after('password_reset_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
