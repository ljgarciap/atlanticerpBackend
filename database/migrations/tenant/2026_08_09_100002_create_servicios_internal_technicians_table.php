<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-255→260) — perfil de técnico interno (nombre, contacto,
 * especialidad, color identificador, horario laboral). Ver docs/architecture/servicios-fase4-diseno.md.
 *
 * `user_id` es NULLABLE a propósito — diverge del snippet original del diseño (que lo daba por
 * NOT NULL) tras revisar REQ-259: su formulario de alta ("nombre, teléfono, correo y
 * especialidad") no exige correo y no reusa el flujo de `POST /api/users` (que sí lo exige
 * `required|unique`, más `security_level_id`/guardia jerárquica que Aaron/lider_servicios no
 * tiene por qué ejercer). Un técnico interno registrado por REQ-259 es un perfil de roster, no
 * necesariamente una cuenta de login — por eso nombre/telefono/email viven acá como snapshot
 * propio, no solo derivados de `users`. Los 4 técnicos reales ya existentes (Carlos Vergara,
 * Pedro Santos, Agustín Rodríguez, Miguel Castillo) sí quedan linkeados vía `user_id`
 * (`InternalTechnicianSeeder`), porque ya son usuarios reales con rol `tecnico_servicios`/
 * `garantias_servicios` y son quienes hoy reciben asignaciones reales de tickets
 * (`tickets.internal_technician_id` sigue apuntando a `users.id`, sin cambios — ver
 * TechnicianController::internal()). Un perfil sin `user_id` no es seleccionable para agendar
 * (Batch 2, REQ-226) hasta que alguien le cree una cuenta real por el flujo de Usuarios — límite
 * conocido, documentado, fuera de alcance de este batch.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('internal_technicians'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained('users', 'id', 'servicios_internal_technicians_user_fk')
                ->nullOnDelete();

            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('especialidad');   // general | warranty
            $table->string('color');
            $table->jsonb('working_hours')->default('{"start":"08:00","end":"18:00"}');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('internal_technicians'));
    }
};
