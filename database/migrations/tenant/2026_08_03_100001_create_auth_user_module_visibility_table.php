<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-724: override de visibilidad de módulo por usuario puntual, por encima de
 * `role_module_visibility` — mismo shape de fila (can_view 0/1/2 + can_view_team),
 * ver docblock de esa tabla para el porqué de smallint en vez de boolean.
 *
 * Ausencia de fila = el usuario hereda la visibilidad de su rol sin cambios — nunca
 * se siembra nada acá, a diferencia de role_module_visibility (que sí trae seed).
 * `User::modulesPayload()`/`effectivePermissionsV2()` solo escriben una fila cuando
 * el valor pedido difiere del que el rol ya otorgaría (ver
 * `UserService::updateModuleVisibility()`) — así "Guardar sin tocar nada" en el
 * admin nunca deja a alguien con menos acceso del que ya tenía.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('user_module_visibility'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 40);
            $table->smallInteger('can_view')->default(0);
            $table->boolean('can_view_team')->default(false);
            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('user_module_visibility'));
    }
};
