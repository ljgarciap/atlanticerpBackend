<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7c — siembra el dominio `pipeline_stage` con las 6 etapas que eran constantes repetidas. Aditiva: no toca `pipeline_cards`.
 * Las categorías son las que hoy tenían implícitas (`approved` = ganada, `lost` = perdida, el resto abiertas). El orden va de 100 en 100
 * para dejar sitio a las etapas propias, que se intercalan entre Lead (100) y Aprobado (500).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{0: string, 1: string, 2: int, 3: string}> clave, etiqueta, orden, categoría */
    private array $seed = [
        ['lead', 'Lead', 100, 'abierta'],
        ['design', 'Diseño', 200, 'abierta'],
        ['quote', 'Cotización', 300, 'abierta'],
        ['proposal', 'Propuesta', 400, 'abierta'],
        ['approved', 'Aprobado', 500, 'ganada'],
        ['lost', 'Perdido', 900, 'perdida'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->seed as [$key, $label, $order, $categoria]) {
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'pipeline_stage', 'key' => $key, 'label' => $label, 'sort_order' => $order, 'is_active' => true, 'is_system' => true,
                'props' => json_encode(['categoria' => $categoria]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'pipeline_stage')->delete();
    }
};
