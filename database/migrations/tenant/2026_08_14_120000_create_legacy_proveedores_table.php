<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-756 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de PROVEEDORES
 * (ver Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.proveedores` es el equivalente
 * staged de PROVEEDORES (114 filas), con trazabilidad completa vía `icg_codproveedor` (llave
 * natural de ICG, UNIQUE — idempotencia del Loader-A).
 *
 * A diferencia de `legacy.productos` (SCRUM-750) y `legacy.clientes` (SCRUM-755), esta entidad NO
 * tiene Loader-B NI Matcher — es catálogo de referencia standalone puro (§5 del diseño: "se
 * importan como catálogo de referencia standalone, SIN intentar fusionar automáticamente con
 * ventas_diseno.catalog_products ni compras.providers — esas tablas ya tienen datos curados en
 * vivo, mezclarlas automáticamente arriesga duplicar o pisar algo real. Fusión manual queda como
 * decisión aparte, fuera de este alcance"). Sin columnas de linkeo (`catalog_product_id`,
 * `sub_client_id`/`match_status`) — nunca se agregan a propósito.
 *
 * Sin columna booleana — a diferencia de `legacy.clientes.bloqueado` (gotcha NDBOOLEANF, §13.2 del
 * diseño), el subconjunto documentado de columnas de `PROVEEDORES` (§2/§4) no incluye ninguna
 * columna de ese tipo. No se inventa una — si aparece una al confirmar el shape completo contra
 * ICG real, se agrega en un batch aparte.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('proveedores'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('icg_codproveedor')->unique('legacy_proveedores_codproveedor_uq');

            $table->string('nombre');
            $table->string('nombre_comercial')->nullable();
            $table->string('cif')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono1')->nullable();
            $table->string('email')->nullable();

            $table->timestamps();

            $table->index('cif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('proveedores'));
    }
};
