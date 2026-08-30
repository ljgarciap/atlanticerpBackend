<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307, ticket SCRUM-377) — mapeo real vendedor→Asistente de
 * Bodega, confirmado contra la RN1/RN2 del ticket de Jira (no inventado): Mariano Sandoval recibe
 * los pedidos de Juan Manuel, Fefi, Neil, Bernardo y Annie; Osvaldo Santos recibe los de Idmar,
 * Vanessa, Paola, Milena y Maribel. Los 12 emails vienen de `BusinessRoleUserSeeder::USERS`
 * (roster real ya sembrado) — ninguno se inventa acá.
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de `DatabaseSeeder`, después de
 * `BusinessRoleUserSeeder`) — es una regla de negocio real, no dato demo. Idempotente por
 * fusión, mismo criterio que `SpecialPermissionSeeder`: si el vendedor ya tiene una fila activa
 * con el mismo asistente, no hace nada; si la tiene con OTRO asistente, la deja (un
 * superadmin/Jefe de Bodega pudo haberla reasignado a mano vía la pantalla de administración que
 * construya un batch posterior — este seeder nunca pisa una reasignación manual ya hecha, solo
 * crea la fila inicial si el vendedor no tiene ninguna).
 */
class WarehouseAssistantMappingSeeder extends Seeder
{
    /** @var array<string, string> seller email => assistant email */
    private const MAPPING = [
        'juanmanuel@illuminations.com.pa' => 'almacen2@illuminations.com.pa', // Mariano Sandoval
        'fefi@illuminations.com.pa'       => 'almacen2@illuminations.com.pa',
        'neil.quiel@illuminations.com.pa' => 'almacen2@illuminations.com.pa',
        'bernardo@illuminations.com.pa'   => 'almacen2@illuminations.com.pa',
        'annie@illuminations.com.pa'      => 'almacen2@illuminations.com.pa',

        'idmar@illuminations.com.pa'      => 'logistica@illuminations.com.pa', // Osvaldo Santos
        'vanessa@illuminations.com.pa'    => 'logistica@illuminations.com.pa',
        'paola@illuminations.com.pa'      => 'logistica@illuminations.com.pa',
        'milena.e@grupolafayette.com'     => 'logistica@illuminations.com.pa',
        'maribel@illuminations.com.pa'    => 'logistica@illuminations.com.pa',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "illuminations" not found — WarehouseAssistantMappingSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $authSchema = "{$tenant->slug}_auth";
            $userIds = DB::table("{$authSchema}.users")
                ->whereIn('email', array_unique([...array_keys(self::MAPPING), ...array_values(self::MAPPING)]))
                ->pluck('id', 'email');

            foreach (self::MAPPING as $sellerEmail => $assistantEmail) {
                if (! isset($userIds[$sellerEmail])) {
                    $this->command?->warn("Vendedor \"{$sellerEmail}\" no encontrado — mapeo omitido.");
                    continue;
                }
                if (! isset($userIds[$assistantEmail])) {
                    $this->command?->warn("Asistente \"{$assistantEmail}\" no encontrado — mapeo omitido.");
                    continue;
                }

                $sellerId = (int) $userIds[$sellerEmail];
                $assistantId = (int) $userIds[$assistantEmail];

                $hasActiveMapping = WarehouseAssistantMapping::where('seller_user_id', $sellerId)
                    ->where('is_active', true)
                    ->exists();

                if ($hasActiveMapping) {
                    continue; // no pisa una reasignación manual ya hecha
                }

                WarehouseAssistantMapping::create([
                    'seller_user_id'    => $sellerId,
                    'assistant_user_id' => $assistantId,
                    'is_active'         => true,
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
