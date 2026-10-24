<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1121 — `architects` pasa a ser la base única de ASOCIADOS (arquitectos, diseñadores, contratistas, ingenieros,
 * consultores y otros), reutilizable entre Cotizaciones y Pipeline. Decisión de Luis: se EXTIENDE esta tabla en vez de crear
 * una nueva, para no tocar los ids ni las comisiones de arquitecto de Admin&Cont (que dependen de `architects` y de
 * `quotes.architect_id`).
 *
 * Todas las filas existentes quedan `type = architect` (default): ni una fila ni un id cambian. `document` es la
 * identificación opcional (insumo de la detección de duplicados); `created_by` registra quién lo dio de alta.
 * `sub_client_id` se conserva como dato histórico de origen: deja de acotar la búsqueda.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->string('type', 20)->default('architect')->after('id');
            $table->string('document', 100)->nullable()->after('email');
            $table->unsignedBigInteger('created_by')->nullable()->after('document');
            $table->index('type');
        });

        $architects = $this->table('architects');
        DB::statement("ALTER TABLE {$architects} DROP CONSTRAINT IF EXISTS architects_type_check");
        DB::statement("ALTER TABLE {$architects} ADD CONSTRAINT architects_type_check CHECK (type IN ('architect','designer','contractor','engineer','consultant','other'))");
    }

    public function down(): void
    {
        $architects = $this->table('architects');
        DB::statement("ALTER TABLE {$architects} DROP CONSTRAINT IF EXISTS architects_type_check");

        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'document', 'created_by']);
        });
    }
};
