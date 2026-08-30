<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-247 (REQ-184) — umbral de margen del producto sustituto que decide si la solicitud
 * escala a Mark (Gerencia). Default 15: valor literal de la Regla de Negocio del ticket ("Si el
 * margen del sustituto es MENOR a 15%..."), no un número inventado — parametric igual que
 * `low_rating_threshold`/`claim_attention_days`, nunca un const en el controller.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->decimal('replacement_margin_threshold', 5, 2)->default(15);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropColumn('replacement_margin_threshold');
        });
    }
};
