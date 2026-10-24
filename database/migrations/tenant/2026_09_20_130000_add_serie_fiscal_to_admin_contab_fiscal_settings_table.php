<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Épica SCRUM-941 — resuelve el "Abierto #2" de docs/architecture/facturacion-electronica-pac-diseno.md:
 * `serie` era nullable sin default, sin decidir si es constante por tenant o configurable. Se
 * decide configurable en `fiscal_settings` (mismo patrón que `pac_provider`/`pac_ambiente`) en vez
 * de hardcodeada en el adapter — nunca un valor de negocio fijo en código.
 *
 * Default `'ILU'` — el mismo valor que MSL usó en TODOS sus ejemplos para este cliente específico
 * en GUIA_ILUMINATION.md/README-ENTREGA.md (no un placeholder genérico, MSL ya lo eligió para
 * Illumination). Notas de crédito/débito derivan su propia serie con prefijo (`NC-{serie}`/
 * `ND-{serie}`) en el momento del wiring real, no se guarda un campo separado por tipo de
 * documento — mismo patrón ya usado en los ejemplos de MSL ("ILU" / "NC-ILU").
 *
 * A propósito NO editable desde el panel de Configuración Fiscal (mismo criterio que `pac_provider`
 * en UpdateFiscalSettingsRequest) — es un identificador registrado ante el PAC/DGI, no un valor de
 * negocio que Mark deba poder cambiar sin coordinar con el proveedor.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->string('serie_fiscal')->default('ILU')->after('pac_ambiente');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('serie_fiscal');
        });
    }
};
