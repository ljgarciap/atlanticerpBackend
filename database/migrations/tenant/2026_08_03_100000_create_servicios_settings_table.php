<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 2 (REQ-222 RN4). Tabla key/value de umbrales configurables de
 * Servicios, mismo patrón que `bodega_settings`
 * (2026_07_22_100009_create_bodega_settings_table.php) — ningún umbral de negocio se hardcodea
 * como constante en código (regla global, CLAUDE.md § "Nunca"). Primer valor: días sin agendar
 * para la tarjeta "Sin agendar" (REQ-222) — compartido a futuro con el panel Pendientes de Inicio
 * (REQ-210, Batch 15/16, todavía no construido) para que ambas pantallas lean el mismo umbral en
 * vez de definirlo dos veces por separado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('servicios_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique('servicios_settings_key_uq');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('servicios_settings'));
    }
};
