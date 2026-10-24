<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1168 F2 — marca los cobros registrados con el concepto nuevo (cobro = dinero que entra; la aplicación a facturas es otro acto, el
 * resto queda como saldo a favor del cliente). Para ellos el libro de movimientos es la fuente de verdad del dinero disponible (el saldo
 * sin aplicar de cada cobro); los cobros históricos y los del flujo anterior conservan su comportamiento. FALSE por defecto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->boolean('cobros_v2')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->dropColumn('cobros_v2');
        });
    }
};
