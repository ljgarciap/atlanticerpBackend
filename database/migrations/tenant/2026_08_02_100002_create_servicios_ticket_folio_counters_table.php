<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-249 RN1 — folio PREFIJO-AÑO-NNNN (INS/GAR/REC/RET), consecutivo por PREFIJO+año (a
 * diferencia de `QuoteFolioCounter`/`OrderFolioCounter`/`ZonaLibreFolioCounter`, que son solo por
 * año). PK compuesta (`prefijo`, `year`) — ver `TicketFolioService::next()` para el mecanismo de
 * asignación atómica sin colisiones (mismo patrón `INSERT ... ON CONFLICT DO NOTHING` +
 * `lockForUpdate()`, adaptado a la clave compuesta vía `DB::table()` en vez de Eloquent, que no
 * soporta primary keys compuestas de forma nativa).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('ticket_folio_counters'), function (Blueprint $table): void {
            $table->string('prefijo', 4); // INS | GAR | REC | RET
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->primary(['prefijo', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_folio_counters'));
    }
};
