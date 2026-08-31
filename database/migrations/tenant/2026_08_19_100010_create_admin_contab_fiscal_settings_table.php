<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-632/633/634/637 (REQ-555/556/557/560) — fila única (id=1) de Configuración Fiscal.
 * Mismo patrón que `compras.settings`/`ComprasSettings`: parámetros de negocio en tabla
 * configurable, nunca hardcodeados. `primary_approver_user_id` se deja NULL acá — lo backfillea
 * `AdminContPrimaryApproverBackfillSeeder` (mismo criterio que `PrimaryApproverBackfillSeeder` de
 * Compras: correr el lookup por email en el seeder, no en la migración, para no depender del
 * orden migrate/seed en un entorno reprovisionado desde cero).
 *
 * `pac_last_sync_at` (REQ-557 RN3) nunca se acepta desde el body de un request — es informativo,
 * generado por un job futuro no construido en este batch.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('razon_social')->default('');
            $table->string('nombre_comercial')->default('');
            $table->string('ruc')->default('');
            $table->string('dv')->default('');
            $table->string('direccion_fiscal', 500)->default('');
            $table->string('regimen_tributario')->default('general'); // general | pequeno_contribuyente
            $table->string('pac_provider')->default('digifact');
            $table->string('pac_ambiente')->default('testing'); // production | testing
            $table->timestamp('pac_last_sync_at')->nullable();
            $table->boolean('pac_doc_factura_habilitado')->default(true);
            $table->boolean('pac_doc_nota_credito_habilitado')->default(true);
            $table->boolean('retencion_proveedores_activa')->default(false);
            $table->foreignId('primary_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table($this->table('fiscal_settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('fiscal_settings'));
    }
};
