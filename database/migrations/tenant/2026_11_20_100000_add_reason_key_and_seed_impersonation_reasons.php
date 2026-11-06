<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 27b (b2) — el motivo de «Ver como usuario» pasa a ser una lista por empresa (dominio `impersonation_reason`). Aditiva: la sesión
 * guarda además la clave elegida (`reason_key`, nula en las sesiones anteriores, cuyo motivo era texto libre) y el texto sigue en `reason`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{0: string, 1: string, 2: int, 3: bool}> clave, etiqueta, orden, requiere texto */
    private array $seed = [
        ['soporte', 'Soporte a la persona', 100, false],
        ['verificar_permisos', 'Verificar permisos o visibilidad', 200, false],
        ['investigar_incidente', 'Investigar un incidente', 300, false],
        ['auditoria', 'Revisión de auditoría', 400, false],
        ['otro', 'Otro (explicar)', 900, true],
    ];

    public function up(): void
    {
        Schema::table($this->table('impersonation_sessions'), function (Blueprint $table): void {
            $table->string('reason_key', 60)->nullable()->after('reason');
        });

        $now = now();
        foreach ($this->seed as [$key, $label, $order, $texto]) {
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'impersonation_reason', 'key' => $key, 'label' => $label, 'sort_order' => $order, 'is_active' => true,
                'is_system' => $key === 'otro', 'props' => json_encode(['requiere_texto' => $texto]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'impersonation_reason')->delete();
        Schema::table($this->table('impersonation_sessions'), function (Blueprint $table): void {
            $table->dropColumn('reason_key');
        });
    }
};
