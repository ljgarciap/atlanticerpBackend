<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9b (docs/specs/fase9-catalogo-productos-generico.md §2) — `crm_projects.dialux` pasa a `requires_technical_study`: «el proyecto
 * requiere un estudio o cálculo técnico» (DiALux era el software de cálculo de iluminación del cliente anterior). Renombre sin pérdida:
 * los valores, el default y los índices se conservan. `down()` lo devuelve.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        if (Schema::hasColumn($this->table('projects'), 'dialux')) {
            DB::statement("ALTER TABLE {$this->table('projects')} RENAME COLUMN dialux TO requires_technical_study");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn($this->table('projects'), 'requires_technical_study')) {
            DB::statement("ALTER TABLE {$this->table('projects')} RENAME COLUMN requires_technical_study TO dialux");
        }
    }
};
