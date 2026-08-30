<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508→513) — Comisiones Externas (arquitectos). Ver
 * docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md.
 *
 * REQ-510 — los 3 datos fiscales del arquitecto (empresa/estudio, RUC, régimen) son por
 * arquitecto, no por proyecto (RN2). Tabla propia de `admin_contab`, mismo criterio ya usado para
 * `client_billing_profiles` (Batch 8, REQ-455): `architect_id` es FK "suelta" (cross-schema hacia
 * `ventas_diseno.architects`, sin constraint real — mismo límite modular del resto de este
 * módulo). Sin default "razonable" tipo `forMasterClient()` acá — a diferencia del régimen del
 * CLIENTE (que sí tiene un default operativo, Con ITBMS/Neto 30), el régimen del arquitecto es
 * información real que hay que pedirle, nunca se puede asumir (RN6 de REQ-511) — la ausencia de
 * fila es la señal de "faltan datos fiscales" (REQ-510 RN4), no una fila con valores por defecto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('architect_fiscal_profiles'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('architect_id')->unique();
            $table->string('empresa');
            $table->string('ruc');
            $table->string('regimen_fiscal', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('architect_fiscal_profiles'));
    }
};
