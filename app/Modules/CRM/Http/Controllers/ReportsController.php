<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Services\ReportsService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $service) {}

    /** GET /api/crm/reports/monthly */
    public function monthly(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $isManagement = $user->canViewTeamV2('ventas_diseno');

        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $month = isset($validated['month']) ? (string) $validated['month'] : now()->format('Y-m');
        $filterUserId = $isManagement && isset($validated['user_id']) ? (int) $validated['user_id'] : null;

        return response()->json($this->service->monthly($user, $isManagement, $month, $filterUserId));
    }

    /** GET /api/crm/reports/monthly/export */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $isManagement = $user->canViewTeamV2('ventas_diseno');

        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $month = isset($validated['month']) ? (string) $validated['month'] : now()->format('Y-m');
        $filterUserId = $isManagement && isset($validated['user_id']) ? (int) $validated['user_id'] : null;

        $data = $this->service->monthly($user, $isManagement, $month, $filterUserId);
        $filename = 'reporte-'.$month.'.csv';

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — export real de datos, potencialmente
        // de todo el equipo cuando isManagement=true sin user_id (sin entityId puntual: es un
        // reporte agregado, no de una entidad específica).
        app(AuditService::class)->log(
            action: AuditService::CRM_REPORT_EXPORTED,
            entityType: 'monthly_report',
            metadata: ['month' => $month, 'filter_user_id' => $filterUserId, 'is_management' => $isManagement],
        );

        return response()->streamDownload(function () use ($data, $isManagement): void {
            $stream = fopen('php://output', 'w');
            assert($stream !== false);

            // UTF-8 BOM so Excel opens it correctly
            fwrite($stream, "\xEF\xBB\xBF");

            // ── Encabezado ────────────────────────────────────────────────────
            fputcsv($stream, ['Reporte Mensual — '.$data['month_label']]);
            fputcsv($stream, ['Período', $data['month']]);
            fputcsv($stream, ['Filtro',  $data['user_title']]);
            fputcsv($stream, []);

            // ── KPIs ──────────────────────────────────────────────────────────
            fputcsv($stream, ['KPIs']);
            fputcsv($stream, ['Leads nuevos',   $data['kpis']['leads_nuevos']]);
            fputcsv($stream, ['Valor nuevos',    $data['kpis']['valor_nuevos']]);
            fputcsv($stream, ['Cerrados',        $data['kpis']['cerrados']]);
            fputcsv($stream, ['Valor cerrado',   $data['kpis']['valor_cerrado']]);
            fputcsv($stream, ['Perdidos',        $data['kpis']['perdidos']]);
            fputcsv($stream, ['Valor perdido',   $data['kpis']['valor_perdido']]);
            fputcsv($stream, ['Tasa de cierre',  $data['kpis']['tasa_cierre'].'%']);
            fputcsv($stream, ['Actividades',     $data['kpis']['actividades']]);
            fputcsv($stream, []);

            // ── Actividades por tipo ──────────────────────────────────────────
            fputcsv($stream, ['Actividades por tipo']);
            fputcsv($stream, ['Tipo', 'Cantidad']);
            /** @var array<string, int> $actByType */
            $actByType = (array) $data['actividades_por_tipo'];
            foreach ($actByType as $tipo => $count) {
                fputcsv($stream, [ucfirst((string) $tipo), $count]);
            }
            fputcsv($stream, []);

            // ── Proyectos nuevos del mes ──────────────────────────────────────
            fputcsv($stream, ['Proyectos nuevos del mes']);
            fputcsv($stream, ['ID', 'Nombre', 'Contacto', 'Tipo', 'Etapa', 'Ubicación', 'Valor', 'Asignados', 'Fecha creación']);
            /** @var array<int, array<string, mixed>> $proyectos */
            $proyectos = (array) $data['proyectos_nuevos'];
            foreach ($proyectos as $p) {
                $p = (array) $p;
                $assignees = array_map(
                    fn (mixed $a): string => is_array($a) ? trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')) : '',
                    (array) ($p['assignees'] ?? []),
                );
                fputcsv($stream, [
                    $p['id'] ?? '',
                    $p['nombre'] ?? '',
                    $p['contacto'] ?? '',
                    $p['tipo'] ?? '',
                    $p['etapa'] ?? '',
                    $p['ubicacion'] ?? '',
                    $p['valor'] ?? 0,
                    implode(', ', $assignees),
                    $p['created_at'] ?? '',
                ]);
            }
            fputcsv($stream, []);

            // ── Bitácora ──────────────────────────────────────────────────────
            fputcsv($stream, ['Bitácora de actividades']);
            fputcsv($stream, ['Fecha', 'Tipo', 'Usuario', 'Proyecto', 'Cliente', 'Notas']);
            /** @var array<int, array<string, mixed>> $bitacora */
            $bitacora = (array) $data['bitacora'];
            foreach ($bitacora as $entry) {
                $entry = (array) $entry;
                fputcsv($stream, [
                    $entry['fecha'] ?? '',
                    $entry['tipo'] ?? '',
                    $entry['user_name'] ?? '',
                    $entry['project_nombre'] ?? '',
                    $entry['project_contacto'] ?? '',
                    $entry['notas'] ?? '',
                ]);
            }

            // ── Desglose por equipo (management, sin filtro de usuario) ──────
            /** @var array<int, array<string, mixed>> $breakdown */
            $breakdown = (array) $data['team_breakdown'];
            if ($isManagement && count($breakdown) > 0) {
                fputcsv($stream, []);
                fputcsv($stream, ['Desglose por equipo']);
                fputcsv($stream, ['Usuario', 'Leads nuevos', 'Cerrados', 'Perdidos', 'Tasa cierre', 'Actividades', 'Valor cerrado']);
                foreach ($breakdown as $row) {
                    $row = (array) $row;
                    fputcsv($stream, [
                        trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
                        $row['leads_nuevos'] ?? 0,
                        $row['cerrados'] ?? 0,
                        $row['perdidos'] ?? 0,
                        ($row['tasa_cierre'] ?? 0).'%',
                        $row['actividades'] ?? 0,
                        $row['valor_cerrado'] ?? 0,
                    ]);
                }
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** GET /api/crm/reports/team */
    public function team(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // Fase 3 de la auditoría de permisos (Atlantic/CLAUDE.md, 2026-08-30) — la ruta ya está
        // gateada por `permission:ventas_diseno.view_team`, este chequeo es defensa en profundidad
        // (mismo criterio que el resto del proyecto, ej. AdminContInternalCommissionController).
        if (! $user->canViewTeamV2('ventas_diseno')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->service->team()]);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
