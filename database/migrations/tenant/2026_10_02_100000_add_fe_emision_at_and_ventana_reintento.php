<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1019 — `fe_emision_at`: instante de emisión fiscal fijado en el PRIMER intento de envío al PAC y nunca
 * regenerado (los reintentos mandan siempre esta `fechaEmision`). `fiscal_settings.fe_reintento_max_horas`: ventana
 * (horas desde `fe_emision_at`) más allá de la cual NO se reenvía (parámetro de negocio, no hardcodeado; default
 * 24 h, decisión de Luis 2026-10-02).
 *
 * Backfill: los documentos que ya se enviaron alguna vez (con `fe_estado` de resultado) se enviaron con su
 * `created_at` como fecha de emisión, así que ese es su `fe_emision_at`. El resto queda NULL y se fija en su primer envío.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        foreach (['invoices', 'credit_notes'] as $tabla) {
            Schema::table($this->table($tabla), function (Blueprint $table): void {
                $table->timestampTz('fe_emision_at')->nullable();
            });

            DB::table($this->table($tabla))
                ->whereIn('fe_estado', ['certificado', 'pendiente', 'rechazado', 'error_configuracion'])
                ->whereNull('fe_emision_at')
                ->update(['fe_emision_at' => DB::raw('created_at')]);
        }

        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('fe_reintento_max_horas')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('fe_reintento_max_horas');
        });

        foreach (['invoices', 'credit_notes'] as $tabla) {
            Schema::table($this->table($tabla), function (Blueprint $table): void {
                $table->dropColumn('fe_emision_at');
            });
        }
    }
};
