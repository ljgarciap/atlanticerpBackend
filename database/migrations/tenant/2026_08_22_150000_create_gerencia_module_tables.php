<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-163 (REQ-101) — primera tabla real del módulo Gerencia (antes era 100% agregación de
// otros módulos, sin schema propio, ver GerenciaHomeService). REGLA_APROBACION: infraestructura
// configurable de "tipo de solicitud → aprobador(es)" — la matriz real (qué tipo le corresponde a
// cada persona) queda PENDIENTE DE DEFINIR CON EL CLIENTE (ver
// 6__Requerimientos_Gerencia.xlsx, hoja "Modelo de Datos"); acá solo se modela la estructura.
return new class extends TenantSchemaMigration
{
    protected string $module = 'gerencia';

    public function up(): void
    {
        // Primer módulo con tabla propia — el schema no existe todavía para tenants ya creados
        // hasta correr `tenant:sync-schemas` (ver Tenant::schemas()); se crea acá también como
        // defensa, mismo patrón que notifications/ventas_diseno.
        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$this->schema().'"');

        Schema::create($this->table('reglas_aprobacion'), function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create($this->table('regla_aprobacion_aprobadores'), function (Blueprint $table) {
            $table->foreignId('regla_aprobacion_id')->constrained($this->table('reglas_aprobacion'))->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['regla_aprobacion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('regla_aprobacion_aprobadores'));
        Schema::dropIfExists($this->table('reglas_aprobacion'));
    }
};
