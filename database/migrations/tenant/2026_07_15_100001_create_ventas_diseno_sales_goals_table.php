<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-146 (REQ-086 del ticket, no confundir con el REQ-086 real de SCRUM-143
 * "Guardar borrador" — colisión de numeración del cliente, ya señalada en el
 * comentario de SCRUM-146). REQ-072: la Meta de Reportes NO es un promedio
 * automático, es un valor que Gerencia configura a mano por vendedor — "Meta" y
 * "Meta Plus" son 2 umbrales (no un kicker/comisión, ver el propio comentario de
 * SCRUM-146 para el detalle de esa interpretación). `user_id` es FK real contra
 * `auth.users`, no el nombre en texto plano que traía el ticket original.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('sales_goals'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('goal_amount', 12, 2);
            $table->decimal('goal_plus_amount', 12, 2)->nullable();
            $table->timestamps();

            // Una meta activa por vendedor — actualizar es un UPDATE, no crear filas
            // nuevas por período (la Meta es "el valor actual configurado", no un
            // historial versionado; REQ-072 solo pide que se pueda cambiar sin deploy).
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sales_goals'));
    }
};
