<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1150 — override por usuario de edit/approve de un módulo, junto al override de visibilidad (SCRUM-724).
 * NULL = hereda de `security_level_module_permissions` (comportamiento de siempre); true/false lo fija.
 * Solo se aplica a módulos que el usuario ya ve.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('user_module_visibility'), function (Blueprint $table): void {
            $table->boolean('can_edit')->nullable();
            $table->boolean('can_approve')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('user_module_visibility'), function (Blueprint $table): void {
            $table->dropColumn(['can_edit', 'can_approve']);
        });
    }
};
