<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-033: los porcentajes/divisores de las fórmulas de Tipo de Precio eran
 * constantes PHP hardcodeadas — Luis pidió (2026-07-12) que cualquier
 * porcentaje/fórmula susceptible de cambiar en el tiempo viva en una tabla
 * paramétrica con CRUD admin/superadmin, no en código. Mismo patrón que
 * report_settings (REQ-075/076): fila única (id=1, sembrada acá), sin "una
 * fila por entidad".
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('pricing_settings'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('project_discount_percent', 5, 2)->default(15);
            $table->decimal('special_additional_discount_percent', 5, 2)->default(5);
            $table->decimal('partner_divisor', 4, 2)->default(0.85);
            $table->decimal('premium_divisor', 4, 2)->default(0.65);
            $table->timestamps();
        });

        DB::table($this->table('pricing_settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pricing_settings'));
    }
};
