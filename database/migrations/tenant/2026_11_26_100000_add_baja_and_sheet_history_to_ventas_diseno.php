<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 31a (Ventas) — clientes maestros y asociados/arquitectos se dan de baja (se desactivan, nunca se borran: tienen
 * historial) y las fichas técnicas del catálogo conservan su historial de versiones (la que se reemplaza o se quita
 * deja de ser la vigente pero su archivo ya no se borra). Todo lo existente queda activo y sin versiones previas:
 * nada cambia al desplegar. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('master_clients'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::create($this->table('catalog_technical_sheet_versions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('catalog_product_id')->index();
            $table->string('storage_key');
            $table->string('filename')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('retired_at')->useCurrent();
            $table->string('retired_reason', 20); // replaced | removed
            $table->unsignedBigInteger('retired_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_technical_sheet_versions'));
        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
        Schema::table($this->table('master_clients'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
