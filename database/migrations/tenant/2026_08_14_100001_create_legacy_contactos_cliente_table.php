<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-755 (épica SCRUM-749). Staging de CONTACTOSCLIENTES — a diferencia de la mayoría de las
 * columnas ⚠️ "por convención" del MER de ICG (Illumination/migracion-icg-diseno.md §2), la FK
 * `CODCLIENTE → CLIENTES.CODCLIENTE` de esta tabla SÍ está declarada como constraint real en SQL
 * Server. Igual se trata como tolerante acá (§13.2: "tolerar huérfanos siempre, nunca asumir
 * integridad referencial de ICG") — `App\Modules\Legacy\Services\ClienteStagingLoader` descarta
 * (no falla) cualquier contacto cuyo `icg_codcliente` no resuelva a un `legacy.clientes.id` dentro
 * del extracto de la corrida actual.
 *
 * `icg_id` (la PK real de CONTACTOSCLIENTES en ICG) es la llave natural — UNIQUE, usada por el
 * Loader-A para el upsert idempotente. `legacy_cliente_id` es la FK interna hacia
 * `legacy.clientes.id` (no hacia `icg_codcliente` directo, a diferencia de
 * `legacy.producto_precios`/`producto_costos` — esta entidad sí necesita resolver el id interno
 * porque el propio ticket pide la columna con ese nombre).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('contactos_cliente'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('legacy_cliente_id')
                ->constrained($this->table('clientes'), 'id', 'legacy_contactos_cliente_cliente_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('icg_id')->unique('legacy_contactos_cliente_icg_id_uq');
            $table->string('cargo')->nullable();
            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->string('mobil')->nullable();
            $table->string('email')->nullable();

            $table->timestamps();

            $table->index('legacy_cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('contactos_cliente'));
    }
};
