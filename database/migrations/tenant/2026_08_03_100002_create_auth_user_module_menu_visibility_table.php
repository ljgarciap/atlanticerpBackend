<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-724, segunda capa: visibilidad de un ítem de menú puntual dentro de un
 * módulo, por usuario. `menu_item_key` es una llave de string opaca para el
 * backend — el catálogo real de llaves por módulo vive solo en el frontend
 * (src/config/menuItemCatalog.ts), este backend no lo valida contra nada.
 *
 * Ausencia de fila = visible (default) — solo una fila con `visible=false`
 * oculta el ítem, nunca al revés, para que el estado por defecto de un usuario
 * nuevo sea "ve todo lo que su rol/módulo ya le da", nunca "oculto hasta que un
 * superadmin lo prenda a mano".
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('user_module_menu_visibility'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('menu_item_key', 60);
            $table->boolean('visible')->default(true);
            $table->unique(['user_id', 'module', 'menu_item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('user_module_menu_visibility'));
    }
};
