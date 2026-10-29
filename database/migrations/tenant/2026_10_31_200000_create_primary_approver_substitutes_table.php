<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12b — aprobadores suplentes. El titular sigue siendo `primary_approver_user_id` (Compras y Admin & Contab, sin cambios); un suplente
 * es cualquier otra cuenta que puede aprobar lo mismo cuando el titular no está. Una fila por suplente, compartida por ambos módulos.
 * Aditiva: sin filas, el comportamiento es exactamente el de antes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('primary_approver_substitutes'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('primary_approver_substitutes'));
    }
};
