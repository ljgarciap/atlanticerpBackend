<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7b (docs/specs/fase7-catalogos.md §6) — siembra el dominio `ticket_type` con los 4 tipos que eran constantes de `Ticket`
 * (`TIPOS`, `PREFIX_BY_TIPO`, `SUBTIPOS_BY_TIPO`) y su SLA, que vivía en 4 ajustes `sla_dias_*`. Aditiva: no toca `tickets.tipo`.
 *
 * El `sla_dias` sembrado es el valor VIGENTE de cada empresa (su ajuste si ya lo había editado, si no el default de siempre), así que
 * ninguna empresa cambia de SLA. Los 4 ajustes `sla_dias_*` quedan en su tabla sin lectores (como `user_roles` tras la Fase 2.1).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: list<string>}> clave, etiqueta, comportamiento, prefijo, SLA por defecto, subtipos */
    private array $seed = [
        ['installation', 'Instalación', 'instalacion', 'INS', 7, ['installation', 'inspection']],
        ['warranty', 'Garantía', 'garantia', 'GAR', 3, ['warranty_generic', 'replacement_inspection']],
        ['claim', 'Reclamo', 'reclamo', 'REC', 2, []],
        ['retrofit', 'Retrofit', 'retrofit', 'RET', 10, []],
    ];

    public function up(): void
    {
        $settings = DB::table(Tenant::current()?->slug.'_servicios.servicios_settings')->pluck('value', 'key');
        $now = now();

        foreach ($this->seed as $i => [$key, $label, $comportamiento, $prefijo, $slaDefault, $subtipos]) {
            $sla = $settings["sla_dias_{$key}"] ?? null;
            $props = [
                'comportamiento' => $comportamiento,
                'prefijo' => $prefijo,
                'sla_dias' => is_numeric($sla) && (int) $sla > 0 ? (int) $sla : $slaDefault,
                'subtipos' => $subtipos,
            ];

            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'ticket_type', 'key' => $key, 'label' => $label, 'sort_order' => $i, 'is_active' => true, 'is_system' => true,
                'props' => json_encode($props), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'ticket_type')->delete();
    }
};
