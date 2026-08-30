<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-433→445 (REQ-363→375) — Bloque B3 "Zona Libre de Colón". Ver
 * docs/adr/ADR-SCRUM433-445-zona-libre-colon.md sección "Aclaración de ubicación de módulo":
 * el modelo vive en `App\Modules\Compras\Models` (no `Bodega\Models`) aunque lo cree Bodega —
 * mismo criterio que `ReplacementRequest`: la autoridad de resolución (Yirena/Compras) y el
 * artefacto final (`PurchaseOrder`, propiedad de Compras) son los que deciden el módulo, no quién
 * lo crea. Mismo patrón de forma que `create_compras_replacement_requests_table` (2026_07_21_100008).
 *
 * `order_number` es un consecutivo plano (sin prefijo ni año) — ver
 * `App\Modules\Compras\Services\ZonaLibreFolioService` para el mecanismo de asignación atómica
 * (mismo lockForUpdate() que `OrderFolioService`/`QuoteFolioService`, sin la dimensión de año
 * porque el mockup solo pide un número incremental plano, ver desglose de PM
 * `docs/backlog/bloque-b3-desglose-20260724.md` sección "Puntos abiertos").
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('bodega_zona_libre_requests'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('order_number')->unique();
            $table->string('status')->default('pendiente');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('generated_order_id')->nullable()
                ->constrained($this->table('purchase_orders'), 'id', 'bodega_zona_libre_requests_order_fk')
                ->nullOnDelete();
            $table->string('shipping_type')->default('terrestre');
            $table->date('estimated_arrival_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bodega_zona_libre_requests'));
    }
};
