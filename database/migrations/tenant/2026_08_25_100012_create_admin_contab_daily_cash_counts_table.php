<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524) — ver
 * docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md. Fila del día creada de forma perezosa (la
 * primera vez que se escribe una observación de fila o la observación general de esa fecha) —
 * REQ-524 no tiene concepto de "cerrar"/aprobar, así que esta tabla no lo modela todavía. Batch 19
 * (REQ-525→529) la extiende con `numero`/`estado`/`cerrado_por`/`cerrado_at`/`aprobado_por`/
 * `aprobado_at` cuando implemente cerrar arqueo + cola de atrasados + aprobación de Gerencia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->id();
            $table->date('fecha')->unique();
            $table->text('observacion_general')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('daily_cash_counts'));
    }
};
