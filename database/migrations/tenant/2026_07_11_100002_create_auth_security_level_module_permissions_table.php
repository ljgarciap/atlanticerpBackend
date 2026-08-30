<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006, Decisión 4 — Fase A. Sin seed todavía a propósito: mapear los 13 roles nuevos a
 * security_levels concretos (con qué can_edit/can_approve por módulo) requiere primero crear o
 * elegir los security_levels que correspondan a cada rol — los niveles 1-10 actuales son
 * genéricos, no tienen una correspondencia 1:1 con "Líder Bodega", "Vendedor/Diseñador", etc.
 * Ese mapeo es un paso pendiente antes de Fase B, no se adivina en esta migración (ver nota en
 * la migración de `roles`, `default_security_level_id` también queda NULL por la misma razón).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('security_level_module_permissions'), function (Blueprint $table): void {
            $table->id();
            // Nombres de constraint explícitos y cortos: el nombre autogenerado por Laravel se
            // deriva del nombre de tabla completo con schema ("illuminations_auth.security_level_
            // module_permissions"), que para esta tabla es lo bastante largo como para que el FK
            // ("..._security_level_id_foreign") y el UNIQUE ("..._security_level_id_module_unique")
            // trunquen al mismo string de 63 bytes (NAMEDATALEN de Postgres) y colisionen.
            $table->foreignId('security_level_id')
                ->constrained(indexName: 'slmp_security_level_id_foreign')
                ->cascadeOnDelete();
            $table->string('module', 40);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->unique(['security_level_id', 'module'], 'slmp_security_level_id_module_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('security_level_module_permissions'));
    }
};
