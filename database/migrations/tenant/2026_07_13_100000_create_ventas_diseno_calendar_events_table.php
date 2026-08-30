<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-058 ("Mi calendario"), Grupo 3. Decisión de Luis (2026-07-13): calendario nativo
 * simple, CRUD manual, sin integración con Outlook/Microsoft Graph — el Excel marcaba
 * esa integración como "pendiente de definir alcance" por el propio cliente, así que no
 * se construye nada de eso todavía. `owner_id` sigue el mismo patrón que
 * `pipeline_cards.owner_id` (un evento pertenece a un único vendedor, alcance Mío/Equipo
 * de REQ-056 se resuelve igual que en Pipeline).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('calendar_events'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('calendar_events'));
    }
};
