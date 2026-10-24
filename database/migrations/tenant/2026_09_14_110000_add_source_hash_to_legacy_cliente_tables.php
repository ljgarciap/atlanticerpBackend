<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 — mismo patrón que `2026_09_14_100000_add_source_hash_to_legacy_producto_tables.php`
 * (ver ese docblock para el porqué de hash y no ventana de fecha). Nullable — filas cargadas por
 * el comando full-sync existente (legacy:sync-clientes) nunca setean esta columna.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('clientes'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('fecha_modificado_icg');
        });

        Schema::table($this->table('contactos_cliente'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('clientes'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('contactos_cliente'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
