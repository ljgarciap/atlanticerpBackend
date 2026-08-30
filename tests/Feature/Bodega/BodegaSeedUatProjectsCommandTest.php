<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-748 — cubre el comando one-shot `bodega:seed-uat-projects`. Los 10 vendedores reales
 * (`role_key=vendedor_disenador`) ya existen en la BD de tests vía `BusinessRoleUserSeeder`
 * (corre incondicionalmente en `DatabaseSeeder`, incluido `testing` — ver docblock del seeder),
 * así que no hace falta sembrarlos acá.
 */
class BodegaSeedUatProjectsCommandTest extends FeatureTestCase
{
    private const MARKER = '[UAT-BODEGA]';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, PipelineCard> */
    private function markedCards(): \Illuminate\Database\Eloquent\Collection
    {
        return PipelineCard::whereHas(
            'salesProject',
            fn ($q) => $q->where('name', 'like', self::MARKER.'%'),
        )->get();
    }

    public function test_crea_40_proyectos_aprobados_distribuidos_entre_vendedores_reales_con_order_en_bodega(): void
    {
        $this->artisan('bodega:seed-uat-projects', ['--tenant' => 'illuminations', '--count' => 40])
            ->assertExitCode(0);

        $this->reMakeCurrent();

        $cards = $this->markedCards();
        $this->assertSame(40, $cards->count());
        $this->assertTrue($cards->every(fn (PipelineCard $card): bool => $card->stage === 'approved'));

        // Distribuido entre más de 1 vendedor real (nunca cuentas demo *@illuminations.test).
        $distinctOwners = $cards->pluck('owner_id')->unique();
        $this->assertGreaterThan(1, $distinctOwners->count());

        $ownerEmails = User::whereIn('id', $distinctOwners)->pluck('email');
        foreach ($ownerEmails as $email) {
            $this->assertStringEndsNotWith('@illuminations.test', $email);
        }

        // Los 40 aparecen en Bodega como Order (flujo real, sin inserción manual).
        foreach ($cards as $card) {
            $order = Order::where('pipeline_card_id', $card->id)->first();
            $this->assertNotNull($order, "Se esperaba un Order en Bodega para PipelineCard {$card->id}.");
        }

        // Sin duplicados: 1 SalesProject por 1 PipelineCard marcado.
        $this->assertSame(40, SalesProject::where('name', 'like', self::MARKER.'%')->count());
    }

    public function test_correrlo_dos_veces_con_el_mismo_count_no_duplica(): void
    {
        $this->artisan('bodega:seed-uat-projects', ['--tenant' => 'illuminations', '--count' => 40])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $firstRunIds = $this->markedCards()->pluck('id')->sort()->values();
        $this->assertSame(40, $firstRunIds->count());

        $this->artisan('bodega:seed-uat-projects', ['--tenant' => 'illuminations', '--count' => 40])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $secondRunIds = $this->markedCards()->pluck('id')->sort()->values();

        $this->assertSame(40, $secondRunIds->count());
        $this->assertSame($firstRunIds->all(), $secondRunIds->all(), 'La segunda corrida no debe crear ni duplicar tarjetas.');
        $this->assertSame(40, Order::whereIn('pipeline_card_id', $secondRunIds)->count());
    }

    public function test_top_up_de_10_a_40_en_una_segunda_corrida(): void
    {
        $this->artisan('bodega:seed-uat-projects', ['--tenant' => 'illuminations', '--count' => 10])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame(10, $this->markedCards()->count());
        $firstBatchIds = $this->markedCards()->pluck('id')->sort()->values();

        $this->artisan('bodega:seed-uat-projects', ['--tenant' => 'illuminations', '--count' => 40])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $cards = $this->markedCards();
        $this->assertSame(40, $cards->count());

        // Los 10 originales siguen existiendo (top-up, no se regenera lo ya creado).
        $this->assertTrue($firstBatchIds->diff($cards->pluck('id'))->isEmpty());

        $this->assertTrue($cards->every(fn (PipelineCard $card): bool => $card->stage === 'approved'));
        foreach ($cards as $card) {
            $this->assertNotNull(
                Order::where('pipeline_card_id', $card->id)->first(),
                "Se esperaba un Order en Bodega para PipelineCard {$card->id} (top-up)."
            );
        }
    }
}
