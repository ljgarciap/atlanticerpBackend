<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-075/076: umbral+meses de la alerta de "Top de vendedores" y probabilidades
 * de "Forecast" — Gerencia los configura sin deploy (ver ADR informal en
 * memory/project_backlog_ventas_diseno_req.md, sección Reportes-B). Fila única
 * (id=1, sembrada en la propia migración) — a diferencia de sales_goals no hay
 * "una fila por entidad", es un único set de parámetros para todo el tenant.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('report_settings'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('low_sales_threshold', 12, 2)->default(20000);
            $table->unsignedTinyInteger('low_sales_months')->default(3);
            $table->decimal('quote_probability_percent', 5, 2)->default(40);
            $table->decimal('proposal_probability_percent', 5, 2)->default(70);
            $table->timestamps();
        });

        DB::table($this->table('report_settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('report_settings'));
    }
};
