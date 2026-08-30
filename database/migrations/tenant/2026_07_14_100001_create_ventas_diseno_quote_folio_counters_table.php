<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-051: contador atómico del folio COT-AAAA-NNNN, una fila por año calendario.
 * `year` es la PK (no un id autoincremental aparte) porque el contador se busca
 * siempre por año, nunca por id — ver QuoteFolioService::next().
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('quote_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quote_folio_counters'));
    }
};
