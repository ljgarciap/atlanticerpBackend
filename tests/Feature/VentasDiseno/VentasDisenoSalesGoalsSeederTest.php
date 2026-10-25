<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Models\User;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\VentasDisenoSalesGoalsSeeder;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-146 — verifica que el roster real de vendedor (sembrado por
 * BusinessRoleUserSeeder) tenga su Meta/Meta Plus real seeded, y que Vendedor Disenador
 * (sin valor real confirmado en el ticket) quede sin Meta configurada.
 */
class VentasDisenoSalesGoalsSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_juanma_tiene_su_meta_real_seeded(): void
    {
        $juanma = User::where('email', 'vendedordisenador8@test.com')->firstOrFail();
        $goal = SalesGoal::where('user_id', $juanma->id)->firstOrFail();

        $this->assertEquals(4328.87, $goal->goal_amount);
        $this->assertEquals(1500000.00, $goal->goal_plus_amount);
    }

    public function test_paola_recibe_los_valores_de_mauricio_del_ticket_original(): void
    {
        $paola = User::where('email', 'vendedordisenador5@test.com')->firstOrFail();
        $goal = SalesGoal::where('user_id', $paola->id)->firstOrFail();

        $this->assertEquals(812.48, $goal->goal_amount);
        $this->assertEquals(700000.00, $goal->goal_plus_amount);
    }

    public function test_annie_queda_sin_meta_configurada_pendiente_de_luis(): void
    {
        $annie = User::where('email', 'vendedordisenador6@test.com')->firstOrFail();

        $this->assertNull(SalesGoal::where('user_id', $annie->id)->first());
    }

    public function test_no_pisa_una_meta_ya_ajustada_a_mano(): void
    {
        $idmar = User::where('email', 'vendedordisenador10@test.com')->firstOrFail();
        SalesGoal::where('user_id', $idmar->id)->update(['goal_amount' => 99999.99]);

        (new VentasDisenoSalesGoalsSeeder)->run();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEquals(99999.99, SalesGoal::where('user_id', $idmar->id)->firstOrFail()->goal_amount);
    }
}
