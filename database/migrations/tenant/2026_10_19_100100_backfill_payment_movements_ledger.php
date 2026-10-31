<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;

/**
 * SCRUM-1168 F1 — copiaba el historial de abonos y cobros al libro de movimientos. Fase 15a: el modelo anterior de cobros se retiró junto con su
 * backfill (`PaymentLedgerBackfill`); en un entorno nuevo no hay historial que copiar, así que esta migración ya no hace nada. Se conserva para que
 * el registro de migraciones de los entornos que ya la corrieron siga coincidiendo con los archivos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        // Sin efecto desde la Fase 15a.
    }

    public function down(): void
    {
        // El libro es append-only: lo copiado no se borra desde una migración.
    }
};
