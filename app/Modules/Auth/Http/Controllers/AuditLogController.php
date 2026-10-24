<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\AuditLog;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * SCRUM-792 (Epic SCRUM-788 — Logs y Telemetría) — visor generalizado de audit_logs, TODOS los
 * módulos (a diferencia de VentasDiseno\Http\Controllers\AuditLogController, que queda intacto,
 * scoped a `action LIKE 'ventas_diseno.%'` — ver docblock de esa clase). Montado en
 * routes/admin.php dentro del grupo `permission:superadmin.all` (mismo nivel que "Consumo
 * IA"/AiUsageController, mismo patrón: sin chequeo manual de auth acá, la ruta ya lo cubre — ver
 * App\Shared\Http\Middleware\CheckPermission, que devuelve 401/403 antes de llegar al controller).
 *
 * "Tipo" (Cambio de dato / Acción) se deriva del sufijo de `action` — created/updated/deleted =
 * Cambio de dato, cualquier otro sufijo = Acción — sin columna nueva en audit_logs, mismo criterio
 * ya aprobado por Luis en la epic.
 */
final class AuditLogController
{
    private const PER_PAGE = 50;

    /** @var list<string> */
    private const CHANGE_SUFFIXES = ['created', 'updated', 'deleted'];

    /**
     * SCRUM-813 (Epic SCRUM-788) — catálogo de entity_type para reemplazar el <input> de texto
     * libre del filtro por un <select> en LogsPage.tsx. DISTINCT contra audit_logs en vez de un
     * catálogo hardcodeado (ni en backend ni en frontend): con ~90 entidades hoy repartidas entre
     * LogsAuditTrail automático y llamadas manuales a AuditService::log() en ~15 puntos distintos
     * del código, un catálogo mantenido a mano driftea en cuanto se agrega una entidad nueva sin
     * que alguien recuerde actualizar 2 archivos más. Cacheado 5 min por tenant — mismo TTL que
     * GlobalSetting::resolveRetentionMonths() en ArchiveAuditLogsJob.
     */
    public function entityTypes(): JsonResponse
    {
        $cacheKey = 'audit_logs:entity_types:' . (string) Tenant::current()?->slug;

        $types = Cache::remember($cacheKey, 300, fn () => AuditLog::query()
            ->whereNotNull('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->values());

        return response()->json(['data' => $types]);
    }

    public function index(Request $request): JsonResponse
    {
        // SCRUM-1020 (Pre-QA) — antes un `from`/`to` inválido llegaba crudo a whereDate() y respondía
        // 500 con el mensaje SQL; ahora que Logs toma el rango de la URL, se valida como 422.
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = AuditLog::with('user')->orderByDesc('created_at');

        $module = $request->query('module');
        if (is_string($module) && $module !== '') {
            // El módulo es el segmento antes del primer '.' de `action` (ej. 'servicios' en
            // 'servicios.ticket.created') — LIKE con el propio separador evita que 'compras'
            // matchee también 'compras_algo' si algún día existiera ese prefijo.
            $query->where('action', 'like', $module . '.%');
        }

        $tipo = $request->query('tipo');
        if ($tipo === 'cambio_dato') {
            $query->where(function ($q): void {
                foreach (self::CHANGE_SUFFIXES as $suffix) {
                    $q->orWhere('action', 'like', '%.' . $suffix);
                }
            });
        } elseif ($tipo === 'accion') {
            $query->where(function ($q): void {
                foreach (self::CHANGE_SUFFIXES as $suffix) {
                    $q->where('action', 'not like', '%.' . $suffix);
                }
            });
        }

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
            'data'         => collect($page->items())->map(fn (AuditLog $log) => $this->format($log))->values(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(AuditLog $log): array
    {
        $suffix = str_contains($log->action, '.') ? substr($log->action, (int) strrpos($log->action, '.') + 1) : $log->action;
        $module = str_contains($log->action, '.') ? substr($log->action, 0, (int) strpos($log->action, '.')) : $log->action;

        return [
            'id'          => $log->id,
            'action'      => $log->action,
            'module'      => $module,
            'tipo'        => in_array($suffix, self::CHANGE_SUFFIXES, true) ? 'cambio_dato' : 'accion',
            'entity_type' => $log->entity_type,
            'entity_id'   => $log->entity_id,
            'old_values'  => $log->old_values,
            'new_values'  => $log->new_values,
            // SCRUM-814 — antes nunca se devolvía, así que el preview de prompt de uso de IA
            // (ai.usage.requested, ver AiUsageAuditor) quedaba capturado en la base pero invisible
            // en la pantalla de Logs. metadata ya es JSONB de propósito general, mismo nivel de
            // confianza que old_values/new_values (nunca contenido no confiable del tenant, ver
            // docblock de AiUsageAuditor).
            'metadata'    => $log->metadata,
            'user'        => $log->user !== null ? ['id' => $log->user->id, 'name' => trim("{$log->user->first_name} {$log->user->last_name}")] : null,
            'created_at'  => $log->created_at->toIso8601String(),
        ];
    }
}
