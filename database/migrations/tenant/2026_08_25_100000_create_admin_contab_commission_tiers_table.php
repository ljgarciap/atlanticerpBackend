<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-498→502) — tabla paramétrica de tramos de comisión
 * escalonada interna (TRAMO_COMISION_INTERNA del Excel de requerimientos). Ver
 * docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md §1-2 — deliberadamente separada de la
 * tabla escalonada hardcodeada en `App\Modules\VentasDiseno\Services\SalesTotalsService` (REQ-073,
 * otro módulo, otra decisión de negocio, coexisten por decisión explícita de Luis).
 *
 * `monto_maximo` nulo = último tramo (sin techo). CRUD gateado por `mark_only`
 * (`AdminContInternalCommissionController`), igual que el resto de configuración fiscal del
 * módulo (ver `AdminContItbmsRateController`) — nunca hardcodear estos valores en código.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('commission_tiers'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('monto_minimo', 12, 2);
            $table->decimal('monto_maximo', 12, 2)->nullable();
            $table->decimal('porcentaje', 5, 2);
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        // Seed inicial — los 6 tramos del mockup adjunto a SCRUM-575→579
        // (4E__Admin_Contabilidad_ComisionesInt.html), corte único en cada umbral.
        $now = now();
        DB::table($this->table('commission_tiers'))->insert([
            ['monto_minimo' => 0,      'monto_maximo' => 20000,  'porcentaje' => 1.5, 'orden' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 20001,  'monto_maximo' => 35000,  'porcentaje' => 2.0, 'orden' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 35001,  'monto_maximo' => 60000,  'porcentaje' => 3.0, 'orden' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 60001,  'monto_maximo' => 81000,  'porcentaje' => 4.0, 'orden' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 81001,  'monto_maximo' => 120000, 'porcentaje' => 4.5, 'orden' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 120001, 'monto_maximo' => null,   'porcentaje' => 5.0, 'orden' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('commission_tiers'));
    }
};
