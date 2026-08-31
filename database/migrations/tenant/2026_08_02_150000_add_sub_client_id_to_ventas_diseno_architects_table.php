<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-122 (Gerencia Test, 2026-08-02) — el directorio de Arquitectos era global
 * (decisión confirmada el 2026-07-30); Daniela pidió que en cambio queden asociados
 * a Cliente Master/Subcliente igual que sales_projects (ver
 * 2026_07_13_100001_make_sub_client_id_nullable_on_ventas_diseno_sales_projects_table
 * para el mismo patrón). Nullable por la misma razón que ahí: los arquitectos
 * globales ya existentes (datos de dev/test) quedan huérfanos — dejan de aparecer
 * en cualquier búsqueda acotada a un subcliente, sin borrarlos ni romper cotizaciones
 * que ya los tengan vinculados por id.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->foreignId('sub_client_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('architects'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sub_client_id');
        });
    }
};
