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
            // Momento del último reset ADMINISTRATIVO de contraseña (docs/runbooks/reset-password.md). Lo setea solo
            // AdminPasswordResetService: AuthenticateJwt rechaza (401) todo access token emitido (`iat`) antes de este
            // instante, para que una sesión previa al reset no pueda tomar la cuenta mientras la contraseña es el correo.
            // El cambio de contraseña propio NO lo toca. Precisión de microsegundos: el `iat` del JWT es de segundos.
            $table->timestampTz('password_reset_at', 6)->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn('password_reset_at');
        });
    }
};
