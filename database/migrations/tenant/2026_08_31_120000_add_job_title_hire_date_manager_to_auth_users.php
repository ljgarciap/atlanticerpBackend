<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-822 — 3 campos que el ticket original de "Registrar nuevo usuario" (REQ-548) pedía y
 * nunca se implementaron: cargo, fecha de ingreso, jefe directo. `manager_id` autoreferencia
 * `users.id` (nullable — RN2 del ticket: "Ninguno — Socio Director" para el nivel más alto, sin
 * jefe asignado). `nullOnDelete()` en vez de restrict: si el jefe directo se elimina alguna vez,
 * el subordinado no debe quedar bloqueado, solo pierde la referencia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->after('phone');
            $table->date('hire_date')->nullable()->after('job_title');
            $table->foreignId('manager_id')->nullable()->after('hire_date')
                ->constrained($this->table('users'), 'id', 'auth_users_manager_id_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['job_title', 'hire_date']);
        });
    }
};
