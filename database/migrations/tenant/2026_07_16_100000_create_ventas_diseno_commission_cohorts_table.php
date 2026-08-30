<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-073: comisión mensual agregada por vendedor — el % se fija al "cerrar" el
 * mes (una vez que el mes ya pasó) sobre el total de pedidos Aprobados de ese
 * mes, y queda inmutable de ahí en más (ver CommissionService::cohortFor()). El
 * mes en curso se calcula en vivo sin persistir (todavía no "cerró"). `paid_at`
 * es el marcado manual de Gerencia/quien tenga el permiso
 * ventas_diseno.commissions.mark_paid — AtlanticERP no tiene módulo de facturación,
 * solo registra el hecho de que ya se pagó.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('commission_cohorts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('commission_percent', 4, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('commission_cohorts'));
    }
};
