<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Bloque B2 (REQ-357/427) — "Confirmar llegada física" de Bodega, un paso NUEVO y
 * DISTINTO del que ya hace Compras (`confirmed_at`/`GoodsReceiptController::confirm()`, que sigue
 * siendo el único que factura de verdad al inventario — no se toca).
 *
 * Decisión de diseño (ticket ambiguo sobre el modelo de datos exacto, resuelta acá): 3 columnas
 * nuevas en la MISMA tabla `purchase_order_receipt_lines` en vez de una tabla aparte — es el mismo
 * registro que Compras ya usa para "Por ingresar" (`confirmed_at` NULL), Bodega solo anota SU
 * propio paso sobre esa misma línea, nunca la reemplaza:
 *   - `bodega_confirmed_at`       — cuándo Bodega avisó que llegó físicamente (NULL = todavía no).
 *   - `bodega_confirmed_quantity` — cuánto reportó Bodega que llegó REALMENTE para esa línea, que
 *     puede ser MENOR a `quantity` (RN de REQ-357: "llegó incompleto") — historial de lo contado,
 *     no se reescribe `quantity` (que sigue siendo lo que Compras pidió originalmente).
 *   - `bodega_confirmed_by`       — quién de Bodega lo reportó.
 *
 * RN2 (REQ-357) — una vez que Bodega confirma, el botón desaparece y aparece una nota de espera;
 * eso se deriva en el backend de `bodega_confirmed_at IS NOT NULL AND confirmed_at IS NULL` por
 * línea (ver `ArrivalConfirmationService`), no requiere una columna de estado aparte.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_receipt_lines'), function (Blueprint $table): void {
            $table->timestamp('bodega_confirmed_at')->nullable()->after('confirmed_at');
            $table->integer('bodega_confirmed_quantity')->nullable()->after('bodega_confirmed_at');
            $table->foreignId('bodega_confirmed_by')->nullable()->after('bodega_confirmed_quantity')
                ->constrained('users', 'id', 'receipt_lines_bodega_confirmed_by_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_order_receipt_lines'), function (Blueprint $table): void {
            // Nombre explícito (no el default de Laravel) — mismo constraint que se creó en up().
            $table->dropForeign('receipt_lines_bodega_confirmed_by_fk');
            $table->dropColumn(['bodega_confirmed_at', 'bodega_confirmed_quantity', 'bodega_confirmed_by']);
        });
    }
};
