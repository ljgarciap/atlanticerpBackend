<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Http\Requests\StoreNotificationRuleRequest;
use App\Modules\CRM\Http\Requests\UpdateNotificationRuleRequest;
use App\Modules\CRM\Models\NotificationRule;
use App\Modules\CRM\Notifications\NotifiableModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class NotificationRuleController extends Controller
{
    // ── GET /api/admin/notification-rules ──────────────────────────────────

    public function index(): JsonResponse
    {
        $rules = NotificationRule::query()->orderByDesc('created_at')->get();

        return response()->json([
            'data'     => $rules->map(fn (NotificationRule $r) => $this->format($r))->values(),
            'registry' => $this->registryPayload(),
        ]);
    }

    // ── POST /api/admin/notification-rules ─────────────────────────────────

    public function store(StoreNotificationRuleRequest $request): JsonResponse
    {
        /** @var array{sub?: int}|null $jwt */
        $jwt = $request->attributes->get('jwt');

        $rule = NotificationRule::create([
            ...$request->validated(),
            'created_by' => isset($jwt['sub']) ? (int) $jwt['sub'] : null,
        ]);

        return response()->json($this->format($rule), 201);
    }

    // ── PUT /api/admin/notification-rules/{id} ─────────────────────────────

    public function update(UpdateNotificationRuleRequest $request, int $id): JsonResponse
    {
        $rule = NotificationRule::find($id);

        if ($rule === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rule->update($request->validated());

        return response()->json($this->format($rule->refresh()));
    }

    // ── DELETE /api/admin/notification-rules/{id} ──────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $rule = NotificationRule::find($id);

        if ($rule === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rule->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(NotificationRule $rule): array
    {
        return [
            'id'              => $rule->id,
            'name'            => $rule->name,
            'trigger_type'    => $rule->trigger_type,
            'trigger_model'   => $rule->trigger_model,
            'trigger_event'   => $rule->trigger_event,
            'field'           => $rule->field,
            'operator'        => $rule->operator,
            'value'           => $rule->value,
            'channels'        => $rule->channels,
            'recipient_type'  => $rule->recipient_type,
            'recipient_value' => $rule->recipient_value,
            'is_active'       => $rule->is_active,
            'created_at'      => $rule->created_at->toIso8601String(),
        ];
    }

    /**
     * Catálogo para que el rule builder del frontend arme los dropdowns encadenados sin
     * hardcodear el whitelist en dos lugares.
     *
     * @return array<string, mixed>
     */
    private function registryPayload(): array
    {
        $models = [];

        foreach (NotifiableModel::REGISTRY as $name => $config) {
            $models[$name] = $config['fields'];
        }

        return [
            'models'    => $models,
            'operators' => NotificationRule::OPERATORS,
            'recipient_types' => [
                NotificationRule::RECIPIENT_USER,
                NotificationRule::RECIPIENT_ROLE,
                NotificationRule::RECIPIENT_PROJECT_ASSIGNEES,
            ],
        ];
    }
}
