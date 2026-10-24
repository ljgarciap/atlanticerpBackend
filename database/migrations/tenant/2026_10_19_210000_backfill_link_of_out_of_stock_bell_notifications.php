<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1179 (seguimiento de SCRUM-1167) — los avisos "Pedido X sin stock disponible" creados ANTES de SCRUM-1153 (16/10) quedaron sin
 * `link_url`: al hacer clic en la campana no llevan a ningún lado. El backfill de SCRUM-1167 no los cubría (solo Zona Libre, propuestas,
 * listo para facturar, ya tiene stock y sin asistente). El aviso viejo no dice qué producto faltó, así que el enlace al producto
 * (`/inventario?search=REF`, el que ya llevan los nuevos) no se puede reconstruir; sí el pedido, con el mismo destino que los avisos
 * "ya tiene stock" y "sin asistente asignado". Solo toca filas sin enlace (ni `link_url` ni `cta_url`); es idempotente y no modifica
 * los avisos nuevos que ya traen enlace al producto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'notifications';

    public function up(): void
    {
        $t   = $this->table('notifications');
        $url = "'/bodega/pedidos/status?search=' || substring(content->>'subject' from '^Pedido ([A-Za-z0-9-]+) sin stock disponible$')";

        DB::statement(
            "UPDATE {$t}
                SET link_url = {$url},
                    content  = content || jsonb_build_object('cta_url', {$url})
              WHERE link_url IS NULL AND content->>'cta_url' IS NULL
                AND content->>'subject' ~ '^Pedido [A-Za-z0-9-]+ sin stock disponible$'"
        );
    }

    public function down(): void
    {
        // Datos corregidos: no se vuelven a dejar inertes.
    }
};
