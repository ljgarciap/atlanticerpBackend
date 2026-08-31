<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de módulos de negocio como datos, no como constante PHP — ver
 * app/Modules/Auth/Support/ModuleCatalog.php y el hallazgo de la auditoría del esquema de
 * permisos (Atlantic/CLAUDE.md, sesión 2026-08-30): antes de esta migración, agregar/quitar un
 * módulo de negocio requería código + deploy (ADR-006, Decisión 6, "module es un string validado
 * contra una constante PHP"). Esta tabla reemplaza esa validación estática por una administrable
 * — ver ModuleController/ModuleService.
 *
 * Aditiva, no cambia comportamiento: se siembran EXACTAMENTE las mismas 7 claves que ya usaba
 * `ModuleCatalog::keys()`, en el mismo orden — `role_module_visibility`/
 * `security_level_module_permissions` no se tocan, siguen siendo VARCHAR libres, ahora validados
 * contra esta tabla en vez de contra el array hardcodeado. `is_system=true` en los 7: tienen
 * controllers/servicios reales acoplados a su clave (ej. `ModuleCatalog::BODEGA` referenciado
 * directo en `BodegaHomeController`) — no se pueden borrar ni renombrar desde la UI, a diferencia
 * de un módulo nuevo que se cree después (ese sí, mientras no tenga filas de matriz asociadas).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, name: string}> */
    private array $modules = [
        ['key' => 'ventas_diseno', 'name' => 'Ventas & Diseño'],
        ['key' => 'compras',       'name' => 'Compras'],
        ['key' => 'bodega',        'name' => 'Bodega'],
        ['key' => 'servicios',     'name' => 'Servicios'],
        ['key' => 'admin_contab',  'name' => 'Administración y Contabilidad'],
        ['key' => 'gerencia',      'name' => 'Gerencia'],
        ['key' => 'operaciones',   'name' => 'Operaciones'],
    ];

    public function up(): void
    {
        Schema::create($this->table('modules'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach ($this->modules as $i => $module) {
            DB::table($this->table('modules'))->insert([
                'key' => $module['key'],
                'name' => $module['name'],
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('modules'));
    }
};
