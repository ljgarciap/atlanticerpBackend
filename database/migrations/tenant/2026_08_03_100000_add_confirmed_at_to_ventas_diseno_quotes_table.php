<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-723: separa "generar" (asignar folio, para poder previsualizar/descargar el
 * PDF) de "registrar oficialmente" (confirmed_at) — antes ambos pasaban juntos en
 * `generate()`, así que salir de la Vista Previa sin querer ya dejaba la cotización
 * contando en Pipeline/Reportes/Dashboard. Ahora solo el nuevo botón "Guardar"
 * (QuoteService::confirm()) setea confirmed_at, y es eso — no folio — lo que
 * `Quote::documentStatus()` usa para decidir si sigue siendo "draft".
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at']);
        });
    }
};
