<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-053: consulta del historial de auditoría de Ventas & Diseño — mismo
 * audit_logs que ya alimenta LogsAuditTrail (altas/ediciones/bajas automáticas
 * de los modelos del módulo) + QuoteContactController (pivot, logueado a mano).
 * Acotado a action LIKE 'ventas_diseno.%' — no es un visor genérico de todo
 * audit_logs (CRM/Security/AI tienen sus propios eventos ahí), coherente con
 * "PANTALLA: General (todas)" siendo "todas las pantallas de Ventas & Diseño",
 * no todo el sistema.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = AuditLog::with('user')
            ->where('action', 'like', 'ventas_diseno.%')
            ->orderByDesc('created_at');

        $entityType = $request->query('entity_type');
        if (is_string($entityType) && $entityType !== '') {
            $query->where('entity_type', $entityType);
        }

        $entityId = $request->query('entity_id');
        if (is_numeric($entityId)) {
            $query->where('entity_id', (string) $entityId);
        }

        $userId = $request->query('user_id');
        if (is_numeric($userId)) {
            $query->where('user_id', (int) $userId);
        }

        $from = $request->query('from');
        if (is_string($from) && $from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $request->query('to');
        if (is_string($to) && $to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log) => $this->format($log))->values(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(AuditLog $log): array
    {
        return [
            'id'          => $log->id,
            'action'      => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id'   => $log->entity_id,
            'old_values'  => $log->old_values,
            'new_values'  => $log->new_values,
            'user'        => $log->user !== null ? ['id' => $log->user->id, 'name' => trim("{$log->user->first_name} {$log->user->last_name}")] : null,
            'created_at'  => $log->created_at->toIso8601String(),
        ];
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
