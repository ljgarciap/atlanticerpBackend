<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-121/122 (KPI/filtro "calificación baja") no traen un número exacto de umbral en el texto
 * del ticket — pedido explícito de Luis (daily sprint2): nada de valores hardcodeados en código,
 * los parámetros de negocio van en tabla configurable. Mismo patrón que `report_settings`
 * (fila única id=1, sembrada acá con el valor de arranque 40 — Luis pidió explícitamente que el
 * default quede por debajo del punto medio de la escala 0-100, corregido 2026-07-16 desde el 60
 * original —, editable después sin deploy).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('settings'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('low_rating_threshold', 5, 2)->default(40);
            $table->timestamps();
        });

        DB::table($this->table('settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('settings'));
    }
};
