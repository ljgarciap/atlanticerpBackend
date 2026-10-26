<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6a (docs/specs/fase6-fiscal-por-pais.md) — contexto regional por tenant: país (ISO-3166), moneda (ISO-4217), zona horaria
 * (IANA) y locale. Los valores por omisión son los de Panamá, que es lo que ya usaban todos los tenants: cero cambio de comportamiento.
 */
return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('country', 2)->default('PA');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('America/Panama');
            $table->string('locale', 10)->default('es-PA');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['country', 'currency', 'timezone', 'locale']);
        });
    }
};
