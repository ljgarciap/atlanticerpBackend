<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización-D (REQ-048/051): folio único asignado atómicamente al "generar" la
 * cotización (ver QuoteFolioService) y fecha de generación, usada como "Fecha" en
 * la Vista Previa/Externa del documento. Ambos quedan null mientras la cotización
 * siga siendo un borrador sin generar.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->string('folio')->nullable()->unique()->after('status');
            $table->timestamp('generated_at')->nullable()->after('folio');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn(['folio', 'generated_at']);
        });
    }
};
