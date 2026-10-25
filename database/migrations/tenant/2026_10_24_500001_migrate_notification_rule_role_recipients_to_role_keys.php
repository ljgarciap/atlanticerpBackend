<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 del aislamiento del núcleo (docs/specs/fase2-retiro-user-roles.md, paso 2.1). Las reglas de notificación del CRM con
 * `recipient_type = 'role'` guardaban en `recipient_value` el nombre de un rol del enum legado (`user_roles`). Ahora el destinatario se
 * resuelve por clave de rol real (`roles.key`): `designer` pasa a `vendedor_disenador`; `superadmin`/`management` ya coinciden;
 * `supervisor`/`electrician` no tienen equivalente en el catálogo y se dejan como están (no coinciden con nadie, igual que hoy).
 * `down()` revierte solo `vendedor_disenador` → `designer`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        $this->translate(['designer' => 'vendedor_disenador']);
    }

    public function down(): void
    {
        $this->translate(['vendedor_disenador' => 'designer']);
    }

    /** @param array<string, string> $map */
    private function translate(array $map): void
    {
        $rules = DB::table($this->table('notification_rules'))->where('recipient_type', 'role')->get(['id', 'recipient_value']);

        foreach ($rules as $rule) {
            $values = json_decode((string) $rule->recipient_value, true);
            if (! is_array($values)) {
                continue;
            }

            $translated = array_values(array_unique(array_map(static fn (mixed $v): mixed => is_string($v) && isset($map[$v]) ? $map[$v] : $v, $values)));
            if ($translated !== $values) {
                DB::table($this->table('notification_rules'))->where('id', $rule->id)->update(['recipient_value' => json_encode($translated)]);
            }
        }
    }
};
