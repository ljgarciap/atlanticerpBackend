<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1120 — `cotizaya_settings`: fila única que dice cuál es el Cliente Master / Subcliente «CotizaYA» de cada
 * entorno (los ids difieren por entorno, por eso no se hardcodean). La llena `CotizaYaClientSeeder` (idempotente).
 * `cotizaya_folio_counters`: contador atómico propio del folio CotizaYA-AAAA-NNNN, una fila por año — serie aparte de
 * `quote_folio_counters` (COT-).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('cotizaya_settings'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id');
            $table->unsignedBigInteger('sub_client_id');
            $table->timestamps();
        });

        Schema::create($this->table('cotizaya_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cotizaya_folio_counters'));
        Schema::dropIfExists($this->table('cotizaya_settings'));
    }
};
