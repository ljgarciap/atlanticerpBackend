<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1121 — vínculo de asociados (base `architects`) con proyecto y cotización. El Proyecto (`sales_project`) es el punto común de la
 * tarjeta de Pipeline y de todas sus cotizaciones, así que los asociados se guardan A NIVEL DE PROYECTO: lo que se agrega desde una
 * cotización aparece en el detalle del proyecto y viceversa, sin copiar nada (una sola fuente de verdad).
 *
 * `quote_associates` solo guarda los de una cotización que todavía no tiene proyecto (borrador); al asignarle proyecto se funden en
 * `sales_project_associates` y se vacía. `quotes.architect_id` NO cambia: sigue siendo el arquitecto que origina la comisión de Admin&Cont.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('sales_project_associates'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('architect_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['sales_project_id', 'architect_id'], 'sales_project_associates_unique');
        });

        Schema::create($this->table('quote_associates'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('architect_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['quote_id', 'architect_id'], 'quote_associates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quote_associates'));
        Schema::dropIfExists($this->table('sales_project_associates'));
    }
};
