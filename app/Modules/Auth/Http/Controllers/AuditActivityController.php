<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\AuditActivitySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-1020 (Epic SCRUM-788) — dashboard de actividad sobre audit_logs. Montado en
 * routes/admin.php dentro del grupo `permission:superadmin.all` (mismo gate que el visor de Logs),
 * por eso no hay chequeo de auth acá. Solo lectura. Ver AuditActivitySummaryService.
 */
final class AuditActivityController
{
    public function __construct(private readonly AuditActivitySummaryService $summary) {}

    /** GET /api/admin/audit/activity-summary?from=Y-m-d&to=Y-m-d — rango máximo 90 días, ambos extremos incluidos. */
    public function summary(Request $request): JsonResponse
    {
        $max = AuditActivitySummaryService::MAX_DAYS;

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from'], (string) config('app.timezone'));
        $to   = CarbonImmutable::createFromFormat('Y-m-d', $validated['to'], (string) config('app.timezone'));

        if ($from === null || $to === null || $from->diffInDays($to) + 1 > $max) {
            return response()->json([
                'message' => "El rango máximo es de {$max} días.",
                'errors'  => ['to' => ["El rango máximo es de {$max} días."]],
            ], 422);
        }

        return response()->json($this->summary->summary($from, $to));
    }
}
