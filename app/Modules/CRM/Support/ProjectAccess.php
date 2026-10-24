<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Models\User;
use App\Modules\CRM\Models\Project;

/**
 * Regla de acceso a un proyecto del CRM: superadmin y management ven todo; el resto, solo los proyectos donde está asignado.
 *
 * Misma regla que las copias privadas `canAccess()` de los controladores del CRM (documentos, enlaces compartidos, actividades,
 * recordatorios, proyectos). SCRUM-1056 la centraliza para que las funciones de IA no puedan saltarse la visibilidad del CRM;
 * migrar las copias del CRM a esta clase queda como limpieza aparte.
 */
final class ProjectAccess
{
    public static function canAccess(string $activeRole, Project $project, User $user): bool
    {
        if (in_array($activeRole, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true)) {
            return true;
        }

        return $project->assignees->contains('id', $user->id);
    }
}
