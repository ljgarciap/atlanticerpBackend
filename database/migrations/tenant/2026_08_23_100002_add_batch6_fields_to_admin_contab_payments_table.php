<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6 de Cobros (SCRUM-545→548, REQ-468→471).
 *
 * `numero_recibo` (REQ-469 RN1, folio REC-XXX) — nullable en la columna a propósito, igual que
 * `invoices.numero`/`tickets.numero`, para poder crearla en una migración separada de la tabla
 * original sin romper filas ya insertadas por Batch 5 en dev/test (backfill abajo, en orden de
 * `created_at`, y el contador de `payment_folio_counters` arranca en la cantidad ya backfillada
 * para que el próximo `AdminContPaymentFolioService::next()` no colisione).
 *
 * `referencia` (REQ-470/471) — campo "Referencia / N° de transacción" del mockup
 * (`4C__Admin_Contabilidad_Cobros.html`, input `#ncpRef`) que Batch 5 no capturó pese a estar en
 * el formulario "Registrar nuevo cobro" del mismo mockup — queda recién como carga real en este
 * batch porque es Cobros (REQ-470) e Historial (REQ-471) quienes lo necesitan primero (búsqueda de
 * texto libre y detalle). Siempre opcional, sin validación de formato (texto libre del usuario).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->string('numero_recibo', 20)->nullable()->after('id');
            $table->string('referencia')->nullable()->after('metodo_pago');
        });

        $table = $this->table('payments');
        $rows  = DB::table($table)->orderBy('created_at')->orderBy('id')->pluck('id');

        foreach ($rows as $index => $id) {
            $numero = sprintf('REC-%03d', $index + 1);
            DB::table($table)->where('id', $id)->update(['numero_recibo' => $numero]);
        }

        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->unique('numero_recibo', 'admin_contab_payments_numero_recibo_uq');
        });

        $counterTable = $this->table('payment_folio_counters');
        DB::statement(
            "INSERT INTO {$counterTable} (id, last_number, created_at, updated_at) VALUES (1, ?, now(), now())
             ON CONFLICT (id) DO UPDATE SET last_number = GREATEST({$counterTable}.last_number, EXCLUDED.last_number)",
            [$rows->count()],
        );
    }

    public function down(): void
    {
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->dropUnique('admin_contab_payments_numero_recibo_uq');
            $table->dropColumn(['numero_recibo', 'referencia']);
        });
    }
};
