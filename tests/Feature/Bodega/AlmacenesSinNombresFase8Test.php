<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Servicios\Services\InsumoService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 8a — el código no depende del nombre de los almacenes: la reserva de servicios se identifica por `funcion`, la central por
 * `despacho_por_defecto` y el tipo de ubicación de Admin&Cont por `tipo`.
 */
class AlmacenesSinNombresFase8Test extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_la_migracion_deja_funcion_tipo_y_nombres_neutros(): void
    {
        $this->assertSame('Reserva Servicios', Warehouse::conFuncion(Warehouse::FUNCION_RESERVA_SERVICIOS)?->name);

        $exhibiciones = Warehouse::query()->where('tipo', Warehouse::TIPO_EXHIBICION)->orderBy('id')->pluck('name')->all();
        $this->assertSame(['Sala de exhibición 1', 'Sala de exhibición 2', 'Exhibición en cliente'], $exhibiciones);
        $this->assertSame(1, Warehouse::query()->where('despacho_por_defecto', true)->count());
    }

    public function test_renombrar_todos_los_almacenes_no_rompe_insumos_ni_ubicaciones(): void
    {
        $reservaId = Warehouse::conFuncion(Warehouse::FUNCION_RESERVA_SERVICIOS)?->id;
        $centralId = Warehouse::despachoPorDefecto()?->id;
        $this->assertNotNull($reservaId);
        $this->assertNotNull($centralId);

        foreach (Warehouse::query()->orderBy('id')->get() as $i => $w) {
            $w->update(['name' => 'Almacén propio '.($i + 1)]);
        }

        $insumos = app(InsumoService::class);
        $this->assertSame($reservaId, $insumos->reservaWarehouseId());
        $this->assertSame($centralId, $insumos->bodegaCentralWarehouseId());

        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');
        $tipos = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/locations')->assertStatus(200)->json())
            ->where('source', 'bodega')->pluck('tipo')->unique()->sort()->values()->all();
        $this->assertSame(['bodega', 'exhibicion'], $tipos);
    }

    public function test_la_funcion_es_unica(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        Warehouse::query()->where('name', 'Merma')->update(['funcion' => Warehouse::FUNCION_RESERVA_SERVICIOS]);
    }
}
