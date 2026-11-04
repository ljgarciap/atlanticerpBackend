<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 22 (B5) — siembra el dominio `crm_stage` con las 6 etapas que eran una lista fija. Aditiva: no toca `projects`. Las claves se
 * conservan (ningún dato cambia); el orden va de 100 en 100 para dejar sitio a las etapas propias, entre Lead (100) y Cerrado (500).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{0: string, 1: string, 2: int, 3: string}> clave, etiqueta, orden, categoría */
    private array $seed = [
        ['lead', 'Lead', 100, 'abierta'],
        ['diseno', 'Diseño', 200, 'abierta'],
        ['cotizacion', 'Cotización', 300, 'abierta'],
        ['propuesta', 'Propuesta', 400, 'abierta'],
        ['cerrado', 'Cerrado', 500, 'ganada'],
        ['perdido', 'Perdido', 900, 'perdida'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->seed as [$key, $label, $order, $categoria]) {
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'crm_stage', 'key' => $key, 'label' => $label, 'sort_order' => $order, 'is_active' => true, 'is_system' => true,
                'props' => json_encode(['categoria' => $categoria]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'crm_stage')->delete();
    }
};
