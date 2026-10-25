<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Batch 2 (REQ-225 RN5, REQ-226 RN2) — regla de "especialidad" de técnico interno por tipo de
 * ticket: Garantías solo la atiende `tecnico_garantias` (hoy, Garantias Servicios Test); cualquier otro
 * tipo (Instalación/Reclamo/Retrofit) solo lo atienden los `tecnico` — nunca se mezclan.
 * El módulo formal de Técnicos Internos con un campo propio de "especialidad" es Batch 6 (todavía
 * no construido); esta regla ya es 100% resolvible hoy con el rol real de cada usuario (mismo
 * catálogo de 4 personas que `TechnicianController::internal()`, SCRUM-721), así que se centraliza
 * acá para que ambos REQ (225/226) usen la misma fuente de verdad y no diverjan.
 */
final class TechnicianSpecialtyService
{
    private const ROLE_TECNICO_GENERAL = RoleKeys::TECNICO;

    private const ROLE_TECNICO_GARANTIAS = RoleKeys::TECNICO_GARANTIAS;

    /** @return Collection<int, User> técnicos activos válidos para el tipo de ticket dado. */
    public function forTipo(string $tipo): Collection
    {
        $roleKey = $tipo === Ticket::TIPO_WARRANTY ? self::ROLE_TECNICO_GARANTIAS : self::ROLE_TECNICO_GENERAL;
        $roleId = Role::where('key', $roleKey)->value('id');

        return User::where('role_id', $roleId)
            ->where('is_active', true)
            // SCRUM-1015 — un técnico eliminado (soft delete) deja de ser asignable.
            ->whereNotIn('id', InternalTechnician::onlyTrashed()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    /** RN5/RN2 — ¿este usuario es una opción válida de técnico interno para este tipo de ticket? */
    public function matches(User $technician, string $tipo): bool
    {
        return $this->forTipo($tipo)->contains('id', $technician->id);
    }
}
