<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Servicios\Services\TechnicianSpecialtyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-721 — opciones livianas para el filtro de técnico (REQ-217), NO el módulo completo de
 * Técnicos Internos (Batch 6, fuera de alcance). El catálogo de "técnico interno" hoy son los
 * usuarios reales con rol `tecnico_servicios`/`garantias_servicios` (ver migración
 * 2026_08_02_100004_grant_servicios_edit_to_technician_roles.php) — no existe tabla propia
 * todavía, `Ticket::internal_technician_id` ya es FK "suelta" y puede apuntar a `users.id` sin
 * cambios de esquema. Si Batch 6 más adelante necesita campos propios de técnico (especialidad,
 * disponibilidad), se resuelve en su momento sin romper este contrato.
 */
final class TechnicianController extends Controller
{
    private const INTERNAL_TECHNICIAN_ROLE_KEYS = ['tecnico_servicios', 'garantias_servicios'];

    public function __construct(private readonly TechnicianSpecialtyService $specialty) {}

    /**
     * GET /api/servicios/technicians/internal?fields=options
     * GET /api/servicios/technicians/internal?fields=options&tipo=warranty — Batch 2 (REQ-226
     * RN2): con `tipo`, filtra a los técnicos válidos para ESE tipo de ticket (Garantías → solo
     * garantias_servicios; cualquier otro tipo → solo tecnico_servicios, nunca mezclados). Sin
     * `tipo`, mantiene el comportamiento sin filtrar de SCRUM-721 (usado por el filtro de la tabla).
     */
    public function internal(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');

        if (is_string($tipo) && $tipo !== '') {
            return response()->json(['data' => $this->specialty->forTipo($tipo)]);
        }

        $roleIds = Role::whereIn('key', self::INTERNAL_TECHNICIAN_ROLE_KEYS)->pluck('id');

        $technicians = User::whereIn('role_id', $roleIds)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return response()->json(['data' => $technicians]);
    }
}
