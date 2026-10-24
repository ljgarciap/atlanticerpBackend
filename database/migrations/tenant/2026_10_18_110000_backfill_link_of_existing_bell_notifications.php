<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1167 (rebote) — las notificaciones de la campana creadas ANTES de que el aviso llevara enlace quedaron sin `link_url`: al hacer
 * clic no llevan a ningún lado (Yirena seguía viendo recordatorios de Zona Libre inertes). El arreglo del emisor solo cubre las
 * nuevas; esto completa las que ya existen, con el mismo destino que hoy les da su emisor. Solo toca filas sin enlace; es idempotente.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'notifications';

    public function up(): void
    {
        $t = $this->table('notifications');

        $this->fill($t, "content->>'subject' LIKE 'Recordatorio: solicitud Zona Libre #%'", "'/bodega/ordenes-zona-libre'");
        $this->fill($t, "content->>'subject' = 'Recordatorio: propuestas vencidas pendientes de seguimiento'", "'/ventas-diseno/pipeline'");
        $this->fill($t, "content->>'subject' ~ '^Pedido [A-Za-z0-9-]+ listo para facturar$'", "'/admin-contab/facturacion'");
        foreach (['ya tiene stock disponible', 'sin asistente asignado'] as $suffix) {
            $this->fill(
                $t,
                "content->>'subject' ~ '^Pedido [A-Za-z0-9-]+ {$suffix}$'",
                "'/bodega/pedidos/status?search=' || substring(content->>'subject' from '^Pedido ([A-Za-z0-9-]+) ')"
            );
        }
    }

    public function down(): void
    {
        // Datos corregidos: no se vuelven a dejar inertes.
    }

    private function fill(string $table, string $where, string $urlSql): void
    {
        DB::statement(
            "UPDATE {$table}
                SET link_url = {$urlSql},
                    content  = content || jsonb_build_object('cta_url', {$urlSql})
              WHERE link_url IS NULL AND content->>'cta_url' IS NULL AND {$where}"
        );
    }
};
