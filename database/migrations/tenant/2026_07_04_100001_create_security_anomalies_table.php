<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('security_anomalies'), function (Blueprint $table) {
            $table->id();
            $table->timestampTz('detected_at')->useCurrent();
            $table->string('severity', 20);
            $table->text('reasoning');
            $table->jsonb('raw_response');
            $table->foreignId('affected_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Ventana de audit_logs analizada — usada para dedupe (ver ADR-004): evita que
            // correr el job dos veces sobre la misma ventana duplique anomalías.
            $table->timestampTz('range_start');
            $table->timestampTz('range_end');
            $table->string('status', 20)->default('pending'); // pending | confirmed | dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        $table = $this->table('security_anomalies');

        DB::statement("CREATE INDEX idx_security_anomalies_status_date ON {$table} (status, detected_at DESC)");
        DB::statement("CREATE INDEX idx_security_anomalies_affected_user ON {$table} (affected_user_id)");
        DB::statement("CREATE INDEX idx_security_anomalies_range ON {$table} (range_start, range_end)");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('security_anomalies'));
    }
};
