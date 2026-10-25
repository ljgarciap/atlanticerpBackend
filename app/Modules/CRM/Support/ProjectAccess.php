<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Models\User;
use App\Modules\CRM\Models\Project;

/**
 * Regla de acceso a un proyecto del CRM: quien ve a todo el equipo de Ventas & Diseño (Gerencia, superadmin) ve todo; el resto, solo los proyectos donde está asignado.
 *
 * Misma regla que las copias privadas `canAccess()` de los controladores del CRM (documentos, enlaces compartidos, actividades,
 * recordatorios, proyectos). SCRUM-1056 la centraliza para que las funciones de IA no puedan saltarse la visibilidad del CRM;
 * migrar las copias del CRM a esta clase queda como limpieza aparte.
 */
final class ProjectAccess
{
    public static function canAccess(Project $project, User $user): bool
    {
        // Fase 2: por permiso, no por la clave del rol del JWT — el mismo `canViewTeamV2` que ya usan los controladores del CRM (Fase 3 de la auditoría).
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $project->assignees->contains('id', $user->id);
    }
}
