<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-755 (épica SCRUM-749). Mapeo MANUAL `VENDEDORES.CODVENDEDOR` → `auth.users.id`
 * (Illumination/migracion-icg-diseno.md §4/§5) — Luis lo completa a mano después de este batch, con
 * la lista real de vendedores todavía activos (de los 101 `VENDEDORES`, "probablemente <15 siguen
 * activos"). Este batch SOLO crea la tabla vacía — sin seed real, sin lógica de auto-match, y sin
 * que ningún `Extractor`/`Loader`/`Matcher` la toque todavía.
 *
 * PK = `icg_codvendedor` (no un `id` autoincremental — es la llave natural de ICG, un mapeo 1:1).
 * `user_id` nullable — un `icg_codvendedor` puede quedar sin mapear indefinidamente sin bloquear
 * nada (`icg_codvendedor` cruda queda visible en `legacy.clientes` de todos modos).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('vendedor_map'), function (Blueprint $table): void {
            $table->unsignedInteger('icg_codvendedor')->primary();
            $table->foreignId('user_id')->nullable()
                ->constrained('users', 'id', 'legacy_vendedor_map_user_fk')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('vendedor_map'));
    }
};
