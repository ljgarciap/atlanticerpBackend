<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        // Primer módulo del schema para el tenant "illuminations" ya existente —
        // tenant:create también lo crea de entrada para tenants nuevos (Tenant::schemas()).
        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$this->schema().'"');

        Schema::create($this->table('master_clients'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('default_price_type', ['public', 'project', 'special', 'partner', 'premium'])
                ->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('master_clients'));
    }
};
