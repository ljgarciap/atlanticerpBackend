<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1157 (rebote) — a Mariam (Líder de Operaciones, operaciones@) le seguían apareciendo CRM y Ventas & Diseño: en producción tiene una
 * fila de visibilidad por persona del módulo Ventas & Diseño (ver y equipo) que no le corresponde (sus módulos son Compras·Inventario,
 * Bodega y los de Administración & Contabilidad que replican a Félix). El menú solo refleja esa fila — CRM y Ventas & Diseño salen de ella —
 * así que se corrige el dato, no el menú. Decisión de Luis: se quita solo esta fila; `crm.read` queda como está. Solo borra esa fila;
 * es idempotente. La sesión abierta la conserva hasta renovarse (el módulo viaja en el JWT).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const EMAIL = 'lideroperaciones@test.com';

    public function up(): void
    {
        $users = $this->table('users');
        $visibility = $this->table('user_module_visibility');

        DB::table($visibility)
            ->where('module', 'ventas_diseno')
            ->whereIn('user_id', DB::table($users)->where('email', self::EMAIL)->select('id'))
            ->delete();
    }

    public function down(): void
    {
        // Dato corregido a propósito: no se vuelve a conceder el acceso.
    }
};
