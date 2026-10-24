<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-841 (épica SCRUM-749 — Migración ICG→AtlanticERP). Agrega los datos de Facturación Electrónica
 * que ICG guarda en `FACTURASVENTACAMPOSLIBRES` (1:1 con `FACTURASVENTA` por la misma llave natural
 * — `NUMSERIE`+`NUMFACTURA`+`N`), nunca capturados por el import original (SCRUM-760). Relevancia
 * fiscal/legal (estado de autorización ante la DGI, Panamá) — mayor prioridad que otros campos
 * libres encontrados en el mismo discovery (ver SCRUM-842).
 *
 * Todo nullable — no todas las facturas tienen fila en `FACTURASVENTACAMPOSLIBRES` (ej. facturas
 * anteriores a que el cliente adoptara FE), y el JOIN del Extractor es LEFT, nunca INNER, para no
 * perder la fila principal si falta el dato satélite.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('facturas'), function (Blueprint $table): void {
            $table->string('fe_qr')->nullable();
            $table->string('fe_auth_number')->nullable();
            $table->string('fe_serie', 30)->nullable();
            $table->string('fe_numero', 30)->nullable();
            $table->string('fe_fecha_emision', 30)->nullable();
            $table->string('fe_fecha_recepcion', 30)->nullable();
            $table->text('fe_error')->nullable();
            $table->string('fe_estado', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('facturas'), function (Blueprint $table): void {
            $table->dropColumn([
                'fe_qr', 'fe_auth_number', 'fe_serie', 'fe_numero',
                'fe_fecha_emision', 'fe_fecha_recepcion', 'fe_error', 'fe_estado',
            ]);
        });
    }
};
