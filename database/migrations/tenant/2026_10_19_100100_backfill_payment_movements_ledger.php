<?php

declare(strict_types=1);

use App\Modules\AdminCont\Services\PaymentLedgerBackfill;
use App\Shared\Multitenancy\TenantSchemaMigration;

/**
 * SCRUM-1168 F1 — copia el historial de abonos y cobros al libro de movimientos (ver `PaymentLedgerBackfill`). Idempotente: si el despliegue
 * dejó una ventana entre subir el código y migrar, `php artisan cobros:backfill-libro` completa lo que falte sin duplicar nada.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        app(PaymentLedgerBackfill::class)->run();
    }

    public function down(): void
    {
        // El libro es append-only: lo copiado no se borra desde una migración.
    }
};
