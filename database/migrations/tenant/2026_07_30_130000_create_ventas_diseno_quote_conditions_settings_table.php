<?php

declare(strict_types=1);

use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-138 — el texto de Condiciones (REQ-046) era editable por-cotización con un
 * permiso individual; Daniela pidió poder cambiarlo una vez y que aplique a todas las
 * cotizaciones NUEVAS desde ese momento, no cotización por cotización. Mismo patrón que
 * pricing_settings/report_settings: fila única (id=1, sembrada acá con el texto fijo que
 * antes vivía como constante en Quote::DEFAULT_CONDITIONS_TEXT), sin "una fila por
 * cotización" — las cotizaciones ya generadas conservan su conditions_text propio
 * (documento histórico, no debe cambiar retroactivamente).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('quote_conditions_settings'), function (Blueprint $table): void {
            $table->id();
            $table->text('text');
            $table->timestamps();
        });

        DB::table($this->table('quote_conditions_settings'))->insert([
            'id' => 1, 'text' => Quote::DEFAULT_CONDITIONS_TEXT, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quote_conditions_settings'));
    }
};
