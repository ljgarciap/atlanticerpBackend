<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-796 (secc. 7) — "Fecha estimada de entrega" gana la opción "Por definir"
 * (delivery_type='tbd'), que exime a Quote::validationErrorsForGenerating() de exigir
 * al menos 1 fecha de entrega. `QuoteRequest::rules()` ya acepta el valor nuevo, pero
 * el check constraint real de Postgres (mismo patrón/gotcha que SCRUM-88, ver migración
 * 2026_07_29_100000_add_photo_to_ventas_diseno_pipeline_card_files_type.php) también
 * necesita el valor nuevo o cualquier "Generar"/"Guardar borrador" con 'tbd' revienta con
 * el error SQL crudo "quotes_delivery_type_check" en vez de guardar. Acotado a `quotes` —
 * `pipeline_cards.delivery_type` es un campo independiente, fuera de alcance de esta
 * sección del ticket.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('quotes');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS quotes_delivery_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT quotes_delivery_type_check CHECK (delivery_type IN ('single','partial','tbd'))");
    }

    public function down(): void
    {
        $table = $this->table('quotes');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS quotes_delivery_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT quotes_delivery_type_check CHECK (delivery_type IN ('single','partial'))");
    }
};
