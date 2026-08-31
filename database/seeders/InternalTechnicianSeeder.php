<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-255→260) — backfill de `internal_technicians` para los 4
 * técnicos internos reales que ya existen como `User` desde `BusinessRoleUserSeeder`/
 * `CoreUserSeeder` (Tecnico Servicios Test, Tecnico Servicios Test 2, Agustín Rodríguez, Garantias Servicios Test). Corre en
 * TODOS los entornos — son personas reales, no datos demo. Create-only-if-not-exists por
 * `user_id`, nunca pisa un color/especialidad ya asignados a mano.
 *
 * Garantias Servicios Test recibe explícitamente el color ámbar (REQ-259 RN3 lo usa como el ejemplo de
 * "color ya tomado" — el resto de la paleta evita ese tono para no repetirlo).
 */
class InternalTechnicianSeeder extends Seeder
{
    /** @var list<array{email: string, especialidad: string, color: string, has_bonus_plan: bool}> */
    private const TECHNICIANS = [
        // Batch 7 (REQ-258 RN7) — Tecnico Servicios Test es hoy el único con plan de bonificación activo.
        ['email' => 'tecnicoservicios@test.com', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL, 'color' => '#3B82F6', 'has_bonus_plan' => true],
        ['email' => 'tecnicoservicios2@test.com', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL, 'color' => '#8B5CF6', 'has_bonus_plan' => false],
        ['email' => 'tecnicoservicios3@test.com', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL, 'color' => '#EC4899', 'has_bonus_plan' => false],
        ['email' => 'garantiasservicios@test.com', 'especialidad' => InternalTechnician::ESPECIALIDAD_WARRANTY, 'color' => '#F59E0B', 'has_bonus_plan' => false],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        foreach (self::TECHNICIANS as $entry) {
            $user = User::where('email', $entry['email'])->first();
            if ($user === null) {
                continue;
            }

            $technician = InternalTechnician::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'nombre' => trim("{$user->first_name} {$user->last_name}"),
                    'telefono' => $user->phone,
                    'email' => $user->email,
                    'especialidad' => $entry['especialidad'],
                    'color' => $entry['color'],
                ],
            );

            // Batch 7 — a diferencia del resto de campos (solo al crear), `has_bonus_plan` se
            // sincroniza en cada corrida: es la fuente de verdad de quién tiene plan de
            // bonificación activo hoy, no un dato capturado a mano que no deba pisarse.
            $technician->update(['has_bonus_plan' => $entry['has_bonus_plan']]);
        }

        Tenant::forgetCurrent();
    }
}
