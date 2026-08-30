<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 22 de Admin&Cont (SCRUM-643→647, REQ-566→570) — tabla paramétrica de tramos de la
 * comisión de Felix por gestión de cartera vencida +90 días (REQ-567). Mismo shape y mismo
 * criterio que `commission_tiers` (ver `2026_08_25_100000_create_admin_contab_commission_tiers_table.php`,
 * Batch 14) — deliberadamente separada de esa tabla (otro concepto de negocio, otro CRUD) en vez
 * de agregarle un discriminador `tipo`, para no tocar los call sites existentes de
 * `CommissionOrderService`/`AdminContInternalCommissionController` que ya asumen que
 * `CommissionTier::all()` es siempre el tramo interno.
 *
 * Los 3 tramos vienen "ya confirmados" en REQ-567 (1% / 1.5% / 2%), pero el ticket solo da los
 * límites en notación redonda ("Menos de $15K", "$15K–$19K", "Más de $20K") sin definir el borde
 * exacto entre tramos — decisión de diseño tomada acá: <15000 → 1%, 15000–19999.99 → 1.5%,
 * ≥20000 → 2% (sin huecos ni solapes). Confirmado con Luis 2026-08-27: tabla paramétrica + CRUD
 * `mark_only` (mismo patrón que `commission_tiers`), sin pantalla de administración dedicada en
 * este batch — ningún ticket del plan de 29 batches la pide.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('cartera_commission_tiers'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('monto_minimo', 12, 2);
            $table->decimal('monto_maximo', 12, 2)->nullable();
            $table->decimal('porcentaje', 5, 2);
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        $now = now();
        DB::table($this->table('cartera_commission_tiers'))->insert([
            ['monto_minimo' => 0,     'monto_maximo' => 14999.99, 'porcentaje' => 1.0, 'orden' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 15000, 'monto_maximo' => 19999.99, 'porcentaje' => 1.5, 'orden' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 20000, 'monto_maximo' => null,     'porcentaje' => 2.0, 'orden' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cartera_commission_tiers'));
    }
};
