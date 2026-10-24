<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-944/945 — auditoría de valores de negocio hardcodeados (docs/specs/
 * auditoria-valores-parametricos-superadmin.md). `CRM\Services\DashboardService` y
 * `CRM\Services\ProjectService` tenían, duplicados en ambos archivos, 3 umbrales de días
 * (urgencia de entrega, "reciente"/"frío" de actividad) hardcodeados como `addDays(3)`/
 * `subDays(7)`/`subDays(21)` en vez de vivir en una tabla paramétrica — regla global del
 * proyecto (CLAUDE.md § "Nunca"). Mismo patrón key/value que `bodega_settings`
 * (`BodegaSetting`/`BodegaSettingsService`), sin filas sembradas acá — el Service siembra/lee
 * su propia key con un default razonable si falta (igual que Bodega).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('crm_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique('crm_settings_key_uq');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('crm_settings'));
    }
};
