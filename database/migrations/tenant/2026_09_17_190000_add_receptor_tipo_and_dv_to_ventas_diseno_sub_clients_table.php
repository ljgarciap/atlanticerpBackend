<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prep — Facturación electrónica PAC (sin ticket todavía, ver
 * docs/architecture/facturacion-electronica-pac-diseno.md). Ambos esquemas de proveedor evaluados
 * (MSL y Digifact directo) exigen clasificar al receptor por tipo DGI (01 contribuyente · 02
 * consumidor final · 03 gobierno · 04 extranjero) y el RUC con su dígito verificador (DV) separado
 * — ninguno de los dos existe hoy en `sub_clients` (`tax_id` es texto libre sin DV). Aditivo y
 * nullable a propósito: no rompe ningún flujo de Ventas & Diseño existente, y ningún adapter real
 * está wireado todavía — se llena recién cuando exista el flujo de captura real (fuera de alcance
 * de este prep).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('sub_clients'), function (Blueprint $table): void {
            $table->string('receptor_tipo', 2)->nullable()->after('tax_id');
            $table->string('tax_id_dv', 4)->nullable()->after('receptor_tipo');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('sub_clients'), function (Blueprint $table): void {
            $table->dropColumn(['receptor_tipo', 'tax_id_dv']);
        });
    }
};
