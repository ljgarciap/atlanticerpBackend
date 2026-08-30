<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, label: string, module: string, sort_order: int}> */
    private array $permissions = [
        ['key' => 'crm.read',         'label' => 'Lectura CRM',                      'module' => 'crm',        'sort_order' => 10],
        ['key' => 'crm.write',        'label' => 'Crear en CRM',                     'module' => 'crm',        'sort_order' => 11],
        ['key' => 'crm.edit',         'label' => 'Editar en CRM',                    'module' => 'crm',        'sort_order' => 12],
        ['key' => 'crm.delete',       'label' => 'Eliminar en CRM',                  'module' => 'crm',        'sort_order' => 13],
        ['key' => 'reports.read',     'label' => 'Ver reportes',                     'module' => 'reports',    'sort_order' => 20],
        ['key' => 'reports.export',   'label' => 'Exportar reportes',                'module' => 'reports',    'sort_order' => 21],
        ['key' => 'ai.use',           'label' => 'Usar asistente AI',                'module' => 'ai',         'sort_order' => 30],
        ['key' => 'security.users',   'label' => 'Gestionar usuarios',               'module' => 'security',   'sort_order' => 40],
        ['key' => 'security.levels',  'label' => 'Gestionar niveles de seguridad',   'module' => 'security',   'sort_order' => 41],
        ['key' => 'settings.global',  'label' => 'Configuración global',             'module' => 'settings',   'sort_order' => 50],
        ['key' => 'superadmin.all',   'label' => 'Acceso total',                     'module' => 'superadmin', 'sort_order' => 99],
    ];

    public function up(): void
    {
        Schema::create($this->table('permission_catalog'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->string('module', 40);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table($this->table('permission_catalog'))->insert([
                'key'        => $permission['key'],
                'label'      => $permission['label'],
                'module'     => $permission['module'],
                'sort_order' => $permission['sort_order'],
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('permission_catalog'));
    }
};
