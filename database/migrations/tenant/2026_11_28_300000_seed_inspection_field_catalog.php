<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 34c (docs/specs/fase34-vocabularios-por-empresa.md) — los campos de sistema del informe de inspección pasan a un catálogo
 * `inspection_field` donde cada empresa edita la ETIQUETA (es/en) y si el campo es OBLIGATORIO al completar el informe. La estructura (qué campos
 * lleva cada tipo de ticket, su control y opciones) sigue en el código; el conjunto es fijo (todas las entradas son de sistema). Por omisión
 * nada es obligatorio: cero cambio de comportamiento. Aditiva; `down()` borra las entradas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, array{string, string}> clave => [etiqueta, inglés] */
    private const FIELDS = [
        'checklist_tecnico' => ['Checklist técnico', 'Technical checklist'],
        'recomendacion_cotizacion' => ['Recomendación para cotización', 'Quote recommendation'],
        'checklist_instalacion' => ['Checklist de instalación', 'Installation checklist'],
        'observaciones_cierre' => ['Observaciones de cierre', 'Closing remarks'],
        'evaluacion_desgaste' => ['Evaluación de desgaste', 'Wear assessment'],
        'evidencia_vida_util' => ['Evidencia de vida útil cumplida', 'Evidence of expired service life'],
        'recomendacion' => ['Recomendación', 'Recommendation'],
        'descripcion_falla' => ['Descripción de la falla', 'Fault description'],
        'diagnostico' => ['Diagnóstico', 'Diagnosis'],
        'aplica_garantia' => ['¿Aplica garantía?', 'Is it under warranty?'],
        'accion_recomendada' => ['Acción recomendada', 'Recommended action'],
        'hallazgos_inspeccion' => ['Hallazgos de la inspección', 'Inspection findings'],
        'detalle_productos_cambiar' => ['Detalle de productos a cambiar', 'Products to replace'],
        'recomendacion_cotizacion_retrofit' => ['Recomendación para cotización', 'Quote recommendation'],
        'estado_actual_producto' => ['Estado actual del producto', 'Current product condition'],
        'observacion_tecnica' => ['Observación técnica', 'Technical remark'],
        'diagnostico_producto' => ['Diagnóstico', 'Diagnosis'],
        'observacion_especifica' => ['Observación específica', 'Specific remark'],
    ];

    public function up(): void
    {
        $table = $this->table('catalog_entries');
        $now = now();
        $orden = 0;
        foreach (self::FIELDS as $key => [$label, $en]) {
            $orden++;
            if (DB::table($table)->where('domain', 'inspection_field')->where('key', $key)->exists()) {
                continue;
            }
            DB::table($table)->insert([
                'domain' => 'inspection_field', 'key' => $key, 'label' => $label, 'labels' => json_encode(['en' => $en]), 'sort_order' => $orden,
                'is_active' => true, 'is_system' => true, 'props' => json_encode(['obligatorio' => false]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'inspection_field')->delete();
    }
};
