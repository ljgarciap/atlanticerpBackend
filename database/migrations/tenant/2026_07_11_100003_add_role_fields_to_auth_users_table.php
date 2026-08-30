<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006, Decisiones 1 y 8 — Fase A. `role_id` es nullable y coexiste con `user_roles` (el
 * enum viejo) — el cutover real (backfill + dejar de usar `user_roles`) es Fase C, no acá.
 * `approve_large_amounts`/`manage_users` son los 2 flags transversales de SCRUM-145, columnas
 * planas en `users` sin depender de rol ni nivel (ver ADR-006 Decisión 8 para el porqué).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('security_level_id')
                ->constrained('roles')->nullOnDelete();
            $table->boolean('approve_large_amounts')->default(false)->after('extra_permissions');
            $table->boolean('manage_users')->default(false)->after('approve_large_amounts');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'approve_large_amounts', 'manage_users']);
        });
    }
};
