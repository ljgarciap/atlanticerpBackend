<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /**
     * Fix de revisión de código (Senior Reviewer, Epic 11): la migración original de
     * security_anomalies dejó reviewed_by sin nullOnDelete() — a diferencia de
     * affected_user_id, que sí lo tiene. Eliminar a un superadmin que revisó alguna
     * anomalía (ej. DemoCleanupController::__invoke() borra usuarios demo) rompía con
     * una violación de constraint. Migración aparte en vez de editar la original porque
     * ya corrió en local y en dev.
     */
    public function up(): void
    {
        Schema::table($this->table('security_anomalies'), function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('security_anomalies'), function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->foreign('reviewed_by')->references('id')->on('users');
        });
    }
};
