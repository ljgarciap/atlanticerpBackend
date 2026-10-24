<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-998 (auditoría SCRUM-995, sitio E; docs/specs/scrum998-catalog-pdf-background.md). Una fila por
 * exportación del Catálogo en segundo plano: cuando el PDF pedido excede `catalog_pdf_max_single_products`
 * (`CrmSettingsService`), `POST /catalog/send-pdf` no genera un PDF plano síncrono sino que encola un job que arma
 * un ZIP de volúmenes (un PDF por tanda de `catalog_pdf_volume_size` productos) y lo guarda en S3 privado.
 *
 * El registro es el único canal de estado: el frontend consulta `GET /catalog/pdf-exports/{id}` y el aviso in-app
 * al terminar/fallar lleva el enlace. `uuid` es el identificador público (y el prefijo de las claves en S3), el
 * `id` interno nunca se expone. Solo el solicitante (o superadmin) puede leer/descargar su exportación.
 *
 * Vive en `ventas_diseno` junto a `catalog_products` (misma razón que `lafayette_sync_runs`: una tabla chica no
 * justifica un schema nuevo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('catalog_pdf_exports'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 20);                       // completo | seleccionados
            $table->jsonb('product_ids')->nullable();          // solo `seleccionados`
            $table->unsignedInteger('total_products')->default(0);
            $table->unsignedInteger('volumes')->default(0);
            $table->unsignedInteger('volume_size')->default(0); // el `catalog_pdf_volume_size` vigente AL PEDIR (un reintento usa el mismo)
            $table->string('status', 20)->default('queued');   // queued | running | done | failed
            $table->string('storage_key')->nullable();         // ZIP en S3 (privado), null hasta `done`
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();          // mensaje seguro para el usuario, nunca una traza
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index('requested_by');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_pdf_exports'));
    }
};
