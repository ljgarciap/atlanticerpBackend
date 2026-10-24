<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-987 (2026-09-29) — registro append-only de cada transmisión al PAC: payload enviado y
 * respuesta recibida. Hasta ahora el payload se armaba en memoria, se enviaba y se descartaba; ante
 * una consulta del proveedor (MSL pidió los payloads de las pruebas con falla) había que
 * reconstruirlo a mano y leer `laravel.log` por SSH. La vista previa (`fe-preview`) NO sirve de
 * evidencia: recalcula con los datos vigentes.
 *
 * Sin `updated_at` y sin FK física: `documento_id` apunta a `invoices`/`credit_notes` según
 * `documento_tipo` (mismo criterio de referencias sueltas del módulo). Nunca se edita ni se borra
 * desde la app (retención sin purga, decisión de Luis) — es evidencia fiscal. `request_payload` y
 * `response_body` llevan datos del receptor (RUC, nombre, correo): solo Mark/superadmin.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('fe_transmisiones'), function (Blueprint $table): void {
            $table->id();
            $table->string('documento_tipo', 20);          // factura | nota_credito
            $table->unsignedBigInteger('documento_id');
            $table->string('provider', 20);                // msl | digifact
            $table->string('ambiente', 20)->nullable();    // pruebas | produccion
            $table->string('operacion', 30);               // certificar | consultar_estado
            $table->string('origen', 20);                  // certificacion | polling
            $table->string('endpoint')->nullable();
            $table->string('serie')->nullable();
            $table->string('numero_fiscal')->nullable();
            $table->jsonb('request_payload')->nullable();  // NULL en consultas GET
            $table->unsignedSmallInteger('http_status')->nullable(); // NULL si no hubo respuesta
            $table->jsonb('response_body')->nullable();
            $table->string('resultado', 30);               // certificado | rechazado | pendiente | error_configuracion | sin_respuesta
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->string('request_id', 64)->nullable();  // el de AtlanticERP, para cruzar con laravel.log
            $table->timestamp('created_at')->useCurrent();

            $table->index(['documento_tipo', 'documento_id'], 'fe_transmisiones_documento_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('fe_transmisiones'));
    }
};
