<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-751 (seguimiento de SCRUM-750, épica SCRUM-749 — Migración ICG→AtlanticERP).
 * Illumination/migracion-icg-diseno.md §15.3/§15.4 — mecanismo de dedup por hash.
 *
 * DECISIÓN DE DISEÑO (dejada a criterio de Backend Dev por el diseño, §15 "decidí vos la
 * persistencia concreta"): tabla chica y dedicada en vez de, por ejemplo, agregar una columna
 * `foto_hash` a `legacy.productos` — se prefiere una tabla aparte por `hash` (UNIQUE) porque el
 * dedup real (§15.1: ~15% de las filas comparten bytes idénticos con OTRO `CODARTICULO`, ej. una
 * foto placeholder reutilizada entre variantes) es una relación muchos-a-uno (muchos artículos →
 * un mismo hash/`s3_key`), no una propiedad 1:1 de cada artículo. Sobrevive entre corridas (no
 * solo dentro de la misma) — un artículo nuevo en una corrida futura que comparta hash con uno ya
 * subido en una corrida anterior reusa el `s3_key` sin volver a subir el JPEG a S3.
 *
 * `catalog_product_id` guarda el producto que DISPARÓ la subida real de esa foto (el primero en
 * verla) — puramente informativo/trazabilidad, `CatalogPhotoLoader` nunca lo usa para resolver a
 * qué productos aplica un hash (cualquier producto con ese hash reusa el mismo `s3_key`,
 * independiente de quién subió primero).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('producto_foto_hashes'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            // SHA-256 hex = 64 chars, del BMP ORIGINAL (antes de decodificar/recodificar) — ver
            // docblock de App\Modules\Legacy\Services\ProductoFotoConverter.
            $table->string('hash', 64)->unique('legacy_foto_hashes_hash_uq');
            $table->string('s3_key');

            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'legacy_foto_hashes_catalog_product_fk')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('producto_foto_hashes'));
    }
};
