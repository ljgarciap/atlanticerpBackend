<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 del aislamiento del núcleo (docs/specs/fase3-roles-genericos.md) — catálogo de roles genérico, aprobado por Luis (2026-10-25).
 * Renombra 4 roles (clave y nombre). `users.role_id`/`user_additional_roles.role_id` apuntan por id, así que NINGÚN usuario cambia de rol ni de permisos.
 * También traduce las reglas de notificación del CRM con destinatario «rol» (guardan la clave en `recipient_value`).
 *
 * Aborta sin tocar nada si ya existe un rol con la clave destino (p. ej. alguien creó `tecnico` por la pantalla de Roles): se resuelve a mano.
 * Los tokens emitidos antes traen la clave vieja en el claim `role`: decisión de Luis, reingreso obligado (sin alias).
 * `down()` revierte las mismas 4 filas y las reglas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, array{key: string, name: string}> clave vieja => nueva (y su nombre nuevo) */
    private const RENAMES = [
        'vendedor_disenador'      => ['key' => 'vendedor',          'name' => 'Vendedor'],
        'ayudante_general_bodega' => ['key' => 'auxiliar_bodega',   'name' => 'Auxiliar de Bodega'],
        'tecnico_servicios'       => ['key' => 'tecnico',           'name' => 'Técnico'],
        'garantias_servicios'     => ['key' => 'tecnico_garantias', 'name' => 'Técnico de Garantías'],
    ];

    /** @var array<string, string> nombre original de cada rol (para `down()`) */
    private const OLD_NAMES = [
        'vendedor_disenador'      => 'Vendedor/Diseñador',
        'ayudante_general_bodega' => 'Ayudante General de Bodega',
        'tecnico_servicios'       => 'Técnico de Servicios',
        'garantias_servicios'     => 'Garantías de Servicios',
    ];

    public function up(): void
    {
        $roles = $this->table('roles');

        foreach (self::RENAMES as $old => $new) {
            if (DB::table($roles)->where('key', $new['key'])->exists()) {
                throw new RuntimeException("Ya existe un rol con la clave «{$new['key']}»: renombrarlo o eliminarlo a mano antes de aplicar el catálogo genérico (Fase 3).");
            }
        }

        foreach (self::RENAMES as $old => $new) {
            DB::table($roles)->where('key', $old)->update(['key' => $new['key'], 'name' => $new['name'], 'updated_at' => now()]);
        }

        $this->translateNotificationRules(array_map(static fn (array $n): string => $n['key'], self::RENAMES));
    }

    public function down(): void
    {
        $roles = $this->table('roles');

        foreach (self::RENAMES as $old => $new) {
            DB::table($roles)->where('key', $new['key'])->update(['key' => $old, 'name' => self::OLD_NAMES[$old], 'updated_at' => now()]);
        }

        $this->translateNotificationRules(array_flip(array_map(static fn (array $n): string => $n['key'], self::RENAMES)));
    }

    /** @param array<string, string> $map clave de origen => clave de destino */
    private function translateNotificationRules(array $map): void
    {
        // `notification_rules` vive en el schema del módulo CRM, no en el de Auth (esta migración es de Auth).
        $table = Tenant::current()?->slug.'_crm.notification_rules';
        $rules = DB::table($table)->where('recipient_type', 'role')->get(['id', 'recipient_value']);

        foreach ($rules as $rule) {
            $values = json_decode((string) $rule->recipient_value, true);
            if (! is_array($values)) {
                continue;
            }

            $translated = array_values(array_unique(array_map(static fn (mixed $v): mixed => is_string($v) && isset($map[$v]) ? $map[$v] : $v, $values)));
            if ($translated !== $values) {
                DB::table($table)->where('id', $rule->id)->update(['recipient_value' => json_encode($translated)]);
            }
        }
    }
};
