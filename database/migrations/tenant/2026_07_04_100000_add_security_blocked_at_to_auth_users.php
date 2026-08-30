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
            // Gate de login independiente de is_active (ver ADR-004): is_active ya cubre
            // aprobación de registro y desactivación manual; mezclar el bloqueo de seguridad
            // ahí rompería esos dos flujos. NULL = no bloqueado.
            $table->timestampTz('security_blocked_at')->nullable()->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn('security_blocked_at');
        });
    }
};
