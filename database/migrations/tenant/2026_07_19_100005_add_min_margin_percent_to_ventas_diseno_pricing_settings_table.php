<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-040/045 (Pre-QA, SCRUM-132/137): margen mínimo por debajo del cual el
 * sistema debe revertir un cambio de precio/descuento/tipo de precio, sin
 * revelar el porcentaje exacto al usuario. Mismo patrón que el resto de
 * `pricing_settings` (REQ-033): configurable sin deploy, nunca hardcodeado
 * (ver feedback_no_hardcoded_business_values — regla ahora también en el
 * CLAUDE.md raíz de Softclass).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pricing_settings'), function (Blueprint $table): void {
            $table->decimal('min_margin_percent', 5, 2)->default(30)->after('premium_divisor');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pricing_settings'), function (Blueprint $table): void {
            $table->dropColumn('min_margin_percent');
        });
    }
};
