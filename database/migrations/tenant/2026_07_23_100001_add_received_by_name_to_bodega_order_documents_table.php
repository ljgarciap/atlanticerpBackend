<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-QA 2026-07-23 (CRÍTICO, SCRUM-329 Batch A3, ticket SCRUM-400/REQ-330) — REQ-329 (SCRUM-399,
 * ya construido en Batch A2) solo captura `courier_id` ("quién entregó") en
 * `DeliveryService::registerSignedGuide()`, nunca un nombre de quien RECIBIÓ. REQ-330 pide mostrar
 * "el nombre de quien recibió, con estilo de firma" en la Guía de Entrega una vez firmada — con el
 * modelo de datos previo esto era físicamente imposible de cumplir sin inventar el dato (la UI
 * mostraba el repartidor, no el receptor real). Decisión de Luis 2026-07-23: agregar el campo
 * faltante a REQ-329 en vez de usar `Order.customer_name` como sustituto (alguien más puede firmar
 * en la dirección de entrega, no necesariamente el cliente registrado).
 *
 * Vive en `order_documents`, no en `orders`: es un dato específico de ESA guía firmada (un pedido
 * dividido en familia tiene una guía firmada por tarjeta, cada una con su propio receptor) — mismo
 * criterio que el resto de la tabla (append-only, un dato por documento, nunca por pedido).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_documents'), function (Blueprint $table): void {
            $table->string('received_by_name')->nullable()->after('storage_key');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_documents'), function (Blueprint $table): void {
            $table->dropColumn('received_by_name');
        });
    }
};
