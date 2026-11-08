<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 28 (grupo 6) — aprobadores por proceso de Admin & Contab (caja chica, arqueo, anulación de cobros,
 * notas de crédito). Un proceso SIN filas sigue el aprobador primario y los suplentes de siempre (cero
 * cambio al desplegar); con filas, solo esas personas (más superadmin) lo deciden. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('process_approvers'), function (Blueprint $table): void {
            $table->id();
            $table->string('process', 40);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['process', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('process_approvers'));
    }
};
