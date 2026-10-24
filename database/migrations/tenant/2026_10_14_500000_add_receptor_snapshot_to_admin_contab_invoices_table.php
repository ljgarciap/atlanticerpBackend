<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1140 — receptor congelado de la factura (docs/specs/receptor-tipo-documento-al-facturar.md, "Diseño técnico").
 *
 * Hasta ahora `FiscalDocumentBuilder` leía el receptor EN VIVO del cliente (pedido -> tarjeta -> `sub_clients`) cada vez que
 * armaba el documento fiscal, también para notas de crédito y recertificaciones. Para que "la razón social de la DGI viaje
 * siempre" y "consumidor final solo para esta factura" sobrevivan a un cambio posterior del cliente, la factura guarda lo que
 * se envió al PAC. Mismo criterio de snapshot que `cliente`/`monto`/`pac_provider_usado` en esta misma tabla.
 *
 *  - `receptor_tipo`: `01` contribuyente (validado contra la DGI) o `02` consumidor final. NULL = factura anterior a este
 *    cambio o creada por un llamador sin guarda: el builder sigue leyendo el cliente en vivo. Sin backfill.
 *  - `receptor_ruc` / `receptor_dv`: documento enviado; NULL en `02`. `dv` solo si la DGI lo trajo.
 *  - `receptor_nombre`: razón social validada por la DGI, o la de AtlanticERP cuando no hubo validación.
 *  - `receptor_validado_at`: momento de la validación contra la DGI; NULL si no se validó (PAC apagado) o es `02`.
 *
 * Todo nullable y sin tocar `sub_clients`. El congelado se puede reemplazar mientras la factura no esté certificada ni
 * pendiente (regla del servicio, no de la BD); una vez certificada queda inmutable.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->string('receptor_tipo', 2)->nullable();
            $table->string('receptor_ruc', 100)->nullable();
            $table->string('receptor_dv', 5)->nullable();
            $table->string('receptor_nombre')->nullable();
            $table->timestamp('receptor_validado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn(['receptor_tipo', 'receptor_ruc', 'receptor_dv', 'receptor_nombre', 'receptor_validado_at']);
        });
    }
};
