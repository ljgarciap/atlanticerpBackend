<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hallazgo MAYOR de Visual Review/Pre-QA + Senior Review (2026-08-19) sobre el batch Configuración
 * Fiscal (SCRUM-634/REQ-557): el mockup adjunto en Jira (`4K__Admin_Contabilidad_Config_Fiscal.html`)
 * muestra un badge de "Estado de conexión" en el panel Facturación electrónica, distinto de
 * `pac_ambiente` (Producción/Pruebas) — el ticket lo pide explícito: "el estado de conexión al
 * Proveedor Autorizado Calificado (PAC)... y el ambiente activo" son 2 datos separados. Sin
 * integración real con Digifact (fuera de alcance, REQ-557 lo dice explícito), este campo es
 * puramente informativo — mismo criterio que `pac_last_sync_at`/`pac_provider`, nunca aceptado
 * desde `UpdateFiscalSettingsRequest`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->string('pac_connection_status')->default('conectado')->after('pac_provider');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('pac_connection_status');
        });
    }
};
