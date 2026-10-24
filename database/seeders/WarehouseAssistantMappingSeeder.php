<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307, ticket SCRUM-377) — mapeo real vendedor→Asistente de
 * Bodega, confirmado contra la RN1/RN2 del ticket de Jira (no inventado): Asistente Bodega Test 2 recibe
 * los pedidos de Vendedor Disenador, Vendedor Disenador, Vendedor Disenador, Vendedor Disenador y Vendedor Disenador; Asistente Bodega Test recibe los de Vendedor Disenador,
 * Vendedor Disenador, Vendedor Disenador, Vendedor Disenador y Vendedor Disenador. Los 12 emails vienen de `BusinessRoleUserSeeder::USERS`
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
        'vendedordisenador8@test.com' => 'asistentebodega2@test.com', // Asistente Bodega Test 2
        'vendedordisenador3@test.com' => 'asistentebodega2@test.com',
        'vendedordisenador2@test.com' => 'asistentebodega2@test.com',
        'vendedordisenador9@test.com' => 'asistentebodega2@test.com',
        'vendedordisenador6@test.com' => 'asistentebodega2@test.com',

        'vendedordisenador10@test.com' => 'asistentebodega@test.com', // Asistente Bodega Test
        'vendedordisenador4@test.com' => 'asistentebodega@test.com',
        'vendedordisenador5@test.com' => 'asistentebodega@test.com',
        'vendedordisenador@test.com' => 'asistentebodega@test.com',
        'vendedordisenador7@test.com' => 'asistentebodega@test.com',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — WarehouseAssistantMappingSeeder skipped.');

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
                    'seller_user_id' => $sellerId,
                    'assistant_user_id' => $assistantId,
                    'is_active' => true,
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
