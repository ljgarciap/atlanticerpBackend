<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-955 — Recibo de Abono. `numero_recibo` (folio ABN-XXX, ver
 * `AdminContAbonoFolioService`) — nullable en la columna a propósito, mismo criterio que
 * `payments.numero_recibo` (2026_08_23_100002), para poder backfillar filas ya insertadas en
 * dev/test sin romperlas. El contador arranca en la cantidad ya backfillada para que el próximo
 * `AdminContAbonoFolioService::next()` no colisione.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('abonos'), function (Blueprint $table): void {
            $table->string('numero_recibo', 20)->nullable()->after('id');
        });

        $table = $this->table('abonos');
        $rows  = DB::table($table)->orderBy('created_at')->orderBy('id')->pluck('id');

        foreach ($rows as $index => $id) {
            $numero = sprintf('ABN-%03d', $index + 1);
            DB::table($table)->where('id', $id)->update(['numero_recibo' => $numero]);
        }

        Schema::table($this->table('abonos'), function (Blueprint $table): void {
            $table->unique('numero_recibo', 'admin_contab_abonos_numero_recibo_uq');
        });

        $counterTable = $this->table('abono_folio_counters');
        DB::statement(
            "INSERT INTO {$counterTable} (id, last_number, created_at, updated_at) VALUES (1, ?, now(), now())
             ON CONFLICT (id) DO UPDATE SET last_number = GREATEST({$counterTable}.last_number, EXCLUDED.last_number)",
            [$rows->count()],
        );
    }

    public function down(): void
    {
        Schema::table($this->table('abonos'), function (Blueprint $table): void {
            $table->dropUnique('admin_contab_abonos_numero_recibo_uq');
            $table->dropColumn('numero_recibo');
        });
    }
};
