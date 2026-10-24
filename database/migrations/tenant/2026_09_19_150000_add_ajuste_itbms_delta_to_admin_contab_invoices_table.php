<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-929 (B3b Punto 4, decisión de Luis 2026-09-19) — "recalcular el total del pedido
 * automáticamente" cuando se corrige la tasa de ITBMS de una factura `parcial`.
 *
 * Por qué hace falta una columna nueva: el guard/cierre de `InvoiceBillingPlanner` comparaba Σ
 * facturas activas contra `pipeline_cards.amount` (fijo). Corregir el ITBMS de una parcial cambia su
 * `monto` (Σ activas baja o sube) pero `card->amount` no se mueve — la factura que luego CIERRA el
 * pedido tomaba `card->amount − Σ activas`, recobrando en silencio la diferencia que la corrección
 * había ajustado. `ajuste_itbms_delta` es el delta (con signo) que esa corrección introdujo —
 * `nuevo_monto − monto_original` de ESE evento — y viaja con la factura de REEMPLAZO (acumulado con
 * el de la que reemplaza, ver `CreditNoteService::executeCorreccion()`). `InvoiceBillingPlanner`
 * suma `ajuste_itbms_delta` de todas las facturas ACTIVAS de la familia y lo aplica tanto al total
 * contra el que se guarda/cierra (`BillingContext::total`) como al objetivo de ITBMS que reconcilia
 * el residual de redondeo del cierre (`Quote::itbms() + ajusteItbms`), para que la factura que cierra
 * calcule el saldo real en vez de recomponer la diferencia corregida.
 *
 * Se persiste en vez de recalcularse desde el estado actual de las líneas porque, una vez aplicada la
 * corrección, las líneas de la factura ORIGINAL (con la tasa vieja) ya no existen — se anula y sus
 * líneas quedan solo en la factura de reemplazo, con la tasa NUEVA. El delta es la única forma de
 * saber "cuánto cambió" sin la información pre-corrección.
 *
 * Default 0 — toda factura emitida normalmente (sin corrección de ITBMS) no ajusta nada.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->decimal('ajuste_itbms_delta', 12, 2)->default(0)->after('saldo_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn('ajuste_itbms_delta');
        });
    }
};
