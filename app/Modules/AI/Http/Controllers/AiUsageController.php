<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiAnalysis;
use App\Shared\Http\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Vista de auditoría de consumo de IA para superadmin — cuánto se gastó en cada request, quién
 * la hizo, en qué módulo y sobre qué documento (si aplica). Pedido de Luis 2026-07-20, tras la
 * detección de facturas (SCRUM-224) sumar consumo real de Claude.
 *
 * NO cubre SecurityAnalysisJob: llama a AnthropicClient directo sin pasar por BaseAiJob/
 * ai_analyses, así que su consumo no aparece acá todavía — gap conocido, no una omisión
 * silenciosa (ver memory del proyecto).
 */
final class AiUsageController
{
    private const ALLOWED_SORTS = ['created_at', 'estimated_cost_usd', 'input_tokens', 'output_tokens'];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'summarize'                  => 'Resumen de proyecto',
        'suggest_actions'             => 'Sugerencia de acciones',
        'draft_message'               => 'Borrador de mensaje',
        'monthly_analysis'            => 'Análisis mensual',
        'replacement_search'          => 'Búsqueda de sustituto',
        'goods_receipt_invoice_match' => 'Detección de factura',
        'provider_confirmation'       => 'Confirmación de proveedor',
    ];

    public function index(Request $request): JsonResponse
    {
        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'created_at', 'desc');

        $query = AiAnalysis::query()->whereNotNull('model');

        if (is_string($from = $request->query('from')) && $from !== '') {
            $query->where('created_at', '>=', $from);
        }
        if (is_string($to = $request->query('to')) && $to !== '') {
            $query->where('created_at', '<=', $to);
        }
        if (is_string($type = $request->query('analysis_type')) && $type !== '') {
            $query->where('analysis_type', $type);
        }

        $paginator = $query
            ->orderBy($pagination['sort_by'], $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        /** @var list<int> $userIds */
        $userIds = collect($paginator->items())
            ->pluck('requested_by')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json([
            'data'  => collect($paginator->items())->map(fn (AiAnalysis $a) => $this->payload($a, $users))->values(),
            'meta'  => Pagination::meta($paginator),
            'totals' => [
                'current_month' => $this->sumFor(
                    AiAnalysis::query()->whereNotNull('model')->where('created_at', '>=', now()->startOfMonth())
                ),
                'all_time' => $this->sumFor(AiAnalysis::query()->whereNotNull('model')),
            ],
        ]);
    }

    /**
     * @param Builder<AiAnalysis> $query
     * @return array{input_tokens: int, output_tokens: int, estimated_cost_usd: float}
     */
    private function sumFor(Builder $query): array
    {
        /** @var object{input_tokens: int|string|null, output_tokens: int|string|null, estimated_cost_usd: float|string|null} $row */
        $row = $query->selectRaw(
            'COALESCE(SUM(input_tokens),0) as input_tokens, '
            . 'COALESCE(SUM(output_tokens),0) as output_tokens, '
            . 'COALESCE(SUM(estimated_cost_usd),0) as estimated_cost_usd'
        )->first();

        return [
            'input_tokens'       => (int) $row->input_tokens,
            'output_tokens'      => (int) $row->output_tokens,
            'estimated_cost_usd' => (float) $row->estimated_cost_usd,
        ];
    }

    /**
     * @param Collection<int, User> $users
     * @return array<string, mixed>
     */
    private function payload(AiAnalysis $a, Collection $users): array
    {
        $user = $a->requested_by !== null ? $users->get($a->requested_by) : null;

        return [
            'id'                 => $a->id,
            'created_at'         => $a->created_at->toIso8601String(),
            'analysis_type'      => $a->analysis_type,
            'analysis_label'     => self::TYPE_LABELS[$a->analysis_type] ?? $a->analysis_type,
            'model'              => $a->model,
            'input_tokens'       => $a->input_tokens,
            'output_tokens'      => $a->output_tokens,
            'estimated_cost_usd' => $a->estimated_cost_usd,
            'document_name'      => $a->extra['original_filename'] ?? null,
            'status'             => $a->status,
            'requested_by'       => $user !== null
                ? ['id' => $user->id, 'name' => "{$user->first_name} {$user->last_name}"]
                : null,
        ];
    }
}
