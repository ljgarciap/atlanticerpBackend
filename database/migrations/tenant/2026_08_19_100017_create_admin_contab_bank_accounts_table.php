<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-607→609 (REQ-530→532) — Cuentas Bancarias, Batch 1 del cuerpo principal de Admin&Cont
 * (ver docs/architecture/admin-contab-batches.md). RN4 de REQ-530: nunca se captura el número
 * completo de cuenta, solo los últimos 4 dígitos — no hay columna para el número completo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('bank_accounts'), function (Blueprint $table): void {
            $table->id();
            $table->string('banco');
            $table->string('tipo_cuenta')->default('corriente'); // corriente | ahorro | tarjeta_credito
            $table->string('ultimos_4_digitos', 4);
            $table->string('moneda', 3)->default('USD');
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bank_accounts'));
    }
};
