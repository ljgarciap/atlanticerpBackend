<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1161 — reordenar productos dentro de una partida con drag and drop. Hasta ahora el orden era
 * `id` (orden de ingreso, SCRUM-1026); `position` lo hace editable sin tocar la lógica de ingreso:
 * un ítem nuevo recibe la siguiente posición de su partida (ver `QuoteItem::booted()`).
 * El backfill respeta el orden actual (`id`) dentro de cada partida, así nada se mueve al migrar.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0)->after('quote_part_id');
        });

        $items = $this->table('quote_items');
        DB::statement(
            "UPDATE {$items} AS i SET position = r.pos FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY quote_part_id ORDER BY id) - 1 AS pos FROM {$items}
            ) AS r WHERE r.id = i.id"
        );
    }

    public function down(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
