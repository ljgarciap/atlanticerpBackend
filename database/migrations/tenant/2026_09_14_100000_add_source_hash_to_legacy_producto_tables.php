<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 (épica SCRUM-749 — Migración ICG→AtlanticERP). `source_hash` (SHA-256 hex, 64 chars) de las
 * columnas de negocio de cada fila extraída de ICG — usado por los comandos incrementales nuevos
 * (`legacy:sync-incremental-*`, separados de los `legacy:sync-*` existentes) para saltar filas sin
 * cambios reales sin necesitar una ventana de fecha (ver App\Modules\Legacy\Support\RowHasher y
 * Illumination/migracion-icg-diseno.md, ticket SCRUM-838, punto 2 del alcance).
 *
 * Nullable — filas cargadas por los comandos full-sync existentes (legacy:sync-productos) nunca
 * setean esta columna; solo el pipeline incremental la puebla y la lee.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('descatalogado');
        });

        Schema::table($this->table('producto_precios'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('descatalogado_fila');
        });

        Schema::table($this->table('producto_costos'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('costo_neto');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('producto_precios'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('producto_costos'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
