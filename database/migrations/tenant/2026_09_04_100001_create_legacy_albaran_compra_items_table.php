<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-840. Staging de ALBCOMPRALIN — mismo patrón que `legacy.albaran_items` (ALBVENTALIN,
 * SCRUM-759): sin llave natural propia declarada en ICG, idempotente por delete-then-insert
 * scoped a las cabeceras de la corrida actual (ver docblock de `AlbaranCompraStagingLoader`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('albaran_compra_items'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legacy_albaran_compra_id')
                ->constrained($this->table('albaranes_compra'), 'id', 'legacy_albaran_compra_items_albaran_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('icg_codarticulo')->nullable();
            $table->string('referencia')->nullable();
            $table->string('descripcion')->nullable();
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();

            $table->index('legacy_albaran_compra_id');
            $table->index('icg_codarticulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('albaran_compra_items'));
    }
};
