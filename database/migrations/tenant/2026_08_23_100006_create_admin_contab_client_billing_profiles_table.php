<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8 de Admin&Cont — Estado de Cuenta (SCRUM-529→533, REQ-452→456). REQ-455 RN1/RN2 pide
 * mostrar el régimen fiscal y los términos de pago VIGENTES de cada cliente en el encabezado del
 * estado de cuenta — ninguno de los 2 existía como atributo por cliente en ningún lado del sistema
 * (la tarifa sí, `ventas_diseno.master_clients.default_price_type`; el "régimen tributario" que ya
 * existe es de la EMPRESA — `admin_contab.fiscal_settings.regimen_tributario` — no del cliente).
 *
 * Decisión de Luis (2026-08-23): tabla propia de `admin_contab`, sin tocar el schema de
 * `ventas_diseno` — mismo límite modular que el resto de este módulo (lee `master_clients`
 * directo, pero nunca lo altera). `master_client_id` es FK "suelta" (cross-schema, mismo criterio
 * que `admin_contab.invoices.master_client_id`). Sin pantalla de CRUD propia todavía — ningún
 * ticket la pidió — se edita por seeder/tinker hasta que un batch futuro pida esa UI (mismo
 * criterio que `bank_accounts.ultimos_4_digitos` en Batch 5 de Cobros). `AdminContClientBillingProfile::forMasterClient()`
 * devuelve un default razonable (Con ITBMS / Neto 30) para cualquier cliente sin fila propia —
 * cubre clientes nuevos automáticamente, sin depender de un seeder de backfill.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('client_billing_profiles'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id')->unique();
            $table->string('regimen_fiscal', 20)->default('con_itbms');
            $table->string('terminos_pago')->default('Neto 30');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('client_billing_profiles'));
    }
};
