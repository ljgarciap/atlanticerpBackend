<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1137 F1. `legacy.contactos_cliente_raw` — copia cruda de CONTACTOSCLIENTES de ICG (821 filas).
 * Llave natural: `id` (el ID de ICG: la tabla de ICG no declara PK y la columna es nullable, pero en los datos
 * es único y sin nulos; el extractor lanza RuntimeException si llega un ID nulo). Por eso `id` es la llave
 * primaria, sin surrogate. Tipos generados desde el esquema real (`docs/architecture/icg-clientes-esquema-icg.txt`).
 * Sin FK hacia `legacy.clientes_raw` ni `ventas_diseno.*`. Acceso solo por base de datos y comandos Artisan.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('contactos_cliente_raw'), function (Blueprint $table): void {
            $table->integer('codcliente')->nullable();
            $table->string('cargo', 255)->nullable();
            $table->string('nombre', 255)->nullable();
            $table->string('telefono', 15)->nullable();
            $table->string('e_mail', 255)->nullable();
            $table->integer('id')->primary();
            $table->string('dptoedi', 13)->nullable();
            $table->boolean('facturacion')->nullable();
            $table->boolean('tesoreria')->nullable();
            $table->string('mobil', 15)->nullable();
            $table->string('source_hash', 64);
            $table->timestamps();

            $table->index('codcliente', 'legacy_contactos_cliente_raw_codcliente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('contactos_cliente_raw'));
    }
};
