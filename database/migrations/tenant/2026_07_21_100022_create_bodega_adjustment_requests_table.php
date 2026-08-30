<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-428/429/446/447 (REQ-358/359/376/377, entidad SOLICITUD_AJUSTE) — la solicitud
 * "contenedora": un producto, quién la pidió, cuándo. El estado real (Pendiente/Aprobada/
 * Rechazada) vive en `adjustment_request_lines`, no acá — el Modelo de Datos original ponía
 * `aprobado_por`/`fecha_resolucion` en este nivel, pero REQ-378/379/447 RN1 son explícitos: se
 * aprueba/rechaza LÍNEA por línea (una solicitud multi-bodega puede tener una línea aprobada y
 * otra rechazada al mismo tiempo) — los criterios de aceptación tienen precedencia sobre el
 * Modelo de Datos cuando entran en conflicto (mismo criterio ya aplicado con el catálogo de
 * bodegas 5→7).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('adjustment_requests'), function (Blueprint $table) use ($ventasDisenoSchema, $authSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'adjustment_requests_product_fk');
            $table->foreignId('solicitado_por')
                ->constrained("{$authSchema}.users", 'id', 'adjustment_requests_user_fk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('adjustment_requests'));
    }
};
