<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006, Decisión 1 revisada (2026-07-09, daily) — Luis pidió mantener la capacidad de
 * multi-rol en vez de eliminarla (más barato tenerla gateada hoy que reconstruirla desde cero si
 * hace falta habilitarla más ampliamente después). `users.role_id` sigue siendo el rol base
 * singular, asignable por cualquier actor con `manage_users`; esta tabla es el 2do rol en
 * adelante, exclusiva de superadmin (mismo patrón ya usado para `extra_permissions`).
 *
 * No reutiliza `user_roles` (el enum legado de 6 roles viejos — concepto distinto al catálogo de
 * 13 roles de negocio de SCRUM-144; mezclarlos sería confuso).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('user_additional_roles'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('user_additional_roles'));
    }
};
