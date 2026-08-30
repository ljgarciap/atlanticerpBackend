<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-183→190 (REQ-120→127) — módulo nuevo Compras/Proveedores, sprint2.
 * Único campo obligatorio es `name` (REQ-126 RN1) — el resto se completa después.
 * `currency` default 'USD' (REQ-126 RN2). Datos bancarios y de contacto en columnas planas
 * (3 secciones del modal de detalle, REQ-125) — sin tabla aparte, no hay múltiples cuentas
 * bancarias ni múltiples contactos por proveedor en ningún criterio de aceptación.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('providers'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_type')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('providers'));
    }
};
