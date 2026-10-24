<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compras\Models\Provider;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\FeatureTestCase;

/** `legacy:vincular-productos-proveedores` sobre la BD REAL de pruebas (spec docs/specs/icg-vinculacion-productos-proveedores.md). */
final class LegacyVincularProductosProveedoresCommandTest extends FeatureTestCase
{
    private int $cod = 880000;

    /** @var array<int, int> codproveedor de ICG => compras.providers.id */
    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Proveedor de ICG con (o sin) enlace a `compras.providers`. */
    private function proveedor(int $codproveedor, bool $enlazado = true): int
    {
        $providerId = null;
        if ($enlazado) {
            $providerId = (int) Provider::create(['name' => 'PROV ' . $codproveedor, 'currency' => 'USD'])->id;
            $this->providers[$codproveedor] = $providerId;
        }
        Proveedor::query()->updateOrCreate(
            ['icg_codproveedor' => $codproveedor],
            ['nombre' => 'ICG ' . $codproveedor, 'provider_id' => $providerId, 'match_status' => $enlazado ? Proveedor::MATCH_MATCHED : Proveedor::MATCH_UNMATCHED]
        );

        return $providerId ?? 0;
    }

    /** @param list<int> $codProveedores */
    private function producto(array $codProveedores, bool $activo = true, bool $descatalogado = false, ?string $factoryReference = null): int
    {
        $p = CatalogProduct::create([
            'reference' => 'CP-' . ++$this->cod, 'name' => 'P ' . $this->cod, 'price_full' => 10.0, 'is_active' => $activo,
            'factory_reference' => $factoryReference,
        ]);
        Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG ' . $this->cod, 'descatalogado' => $descatalogado, 'catalog_product_id' => $p->id]);
        foreach ($codProveedores as $prov) {
            ProductoCosto::query()->create(['icg_codarticulo' => $this->cod, 'codproveedor' => $prov, 'costo_neto' => 10.0]);
        }

        return (int) $p->id;
    }

    private function compra(int $productoId, int $codproveedor, string $fecha): void
    {
        $cod = (int) Producto::query()->where('catalog_product_id', $productoId)->value('icg_codarticulo');
        $cab = AlbaranCompra::query()->create(['icg_numserie' => 'T', 'icg_numalbaran' => $cod * 10 + $codproveedor, 'icg_n' => 'B', 'icg_codproveedor' => $codproveedor, 'fecha' => $fecha]);
        AlbaranCompraItem::query()->create(['legacy_albaran_compra_id' => $cab->id, 'icg_codarticulo' => $cod, 'precio' => 1.0]);
    }

    /** @return array{principal: ?int, proveedores: list<int>} */
    private function vinculo(int $id): array
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el comando olvida el tenant al terminar
        $p = CatalogProduct::query()->findOrFail($id);
        $ids = DB::table(CatalogProduct::providersPivotTable())->where('catalog_product_id', $id)->pluck('provider_id')->map(fn ($v): int => (int) $v)->sort()->values()->all();

        return ['principal' => $p->provider_id === null ? null : (int) $p->provider_id, 'proveedores' => $ids];
    }

    /** @param array<string, mixed> $opciones */
    private function correr(array $opciones = []): PendingCommand
    {
        return $this->artisan('legacy:vincular-productos-proveedores', ['--tenant' => 'atlantic'] + $opciones);
    }

    private function huella(): string
    {
        Artisan::call('legacy:vincular-productos-proveedores', ['--tenant' => 'atlantic']);
        preg_match('/--esperado=([0-9a-f]{32})/', Artisan::output(), $m);
        $this->assertNotEmpty($m, 'el dry-run debe imprimir la huella');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $m[1];
    }

    private function aplicar(): void
    {
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => $this->huella()])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $a = $this->proveedor(9801);
        $id = $this->producto([9801]);

        $this->correr()->expectsOutputToContain('DRY-RUN')->assertExitCode(0);
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($id));
        $this->assertGreaterThan(0, $a);
    }

    public function test_un_proveedor_queda_como_principal_y_en_la_pivote(): void
    {
        $a = $this->proveedor(9801);
        $id = $this->producto([9801]);

        $this->aplicar();

        $this->assertSame(['principal' => $a, 'proveedores' => [$a]], $this->vinculo($id));
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES)->firstOrFail();
        $this->assertSame('tests', $evento->metadata['operador']);
        $this->assertArrayHasKey($id, $evento->metadata['vinculos_aplicados']);
    }

    public function test_varios_proveedores_entran_todos_y_el_principal_es_el_de_la_compra_mas_reciente(): void
    {
        $a = $this->proveedor(9801);
        $b = $this->proveedor(9802);
        $id = $this->producto([9801, 9802]);
        $this->compra($id, 9801, '2026-01-10 00:00:00');
        $this->compra($id, 9802, '2026-06-10 00:00:00');

        $this->aplicar();

        $this->assertSame(['principal' => $b, 'proveedores' => collect([$a, $b])->sort()->values()->all()], $this->vinculo($id));
    }

    public function test_empate_o_sin_compras_deja_como_principal_al_de_menor_codigo_y_lo_lista(): void
    {
        $a = $this->proveedor(9803);
        $b = $this->proveedor(9804);
        $sinCompras = $this->producto([9804, 9803]);
        $empate = $this->producto([9803, 9804]);
        $this->compra($empate, 9803, '2026-03-01 00:00:00');
        $this->compra($empate, 9804, '2026-03-01 00:00:00');

        $this->correr()->expectsOutputToContain((string) $sinCompras)->assertExitCode(0);
        $this->aplicar();

        $todos = collect([$a, $b])->sort()->values()->all();
        $this->assertSame(['principal' => $a, 'proveedores' => $todos], $this->vinculo($sinCompras));
        $this->assertSame(['principal' => $a, 'proveedores' => $todos], $this->vinculo($empate));
    }

    public function test_el_proveedor_por_defecto_1001_gana_como_principal(): void
    {
        $x = $this->proveedor(9805);
        $d = $this->proveedor(1001);
        $id = $this->producto([9805, 1001]);

        $this->aplicar();

        $this->assertSame($d, $this->vinculo($id)['principal']);
        $this->assertContains($x, $this->vinculo($id)['proveedores']);
    }

    public function test_proveedores_sin_enlace_se_omiten_y_un_producto_solo_con_ellos_no_se_toca(): void
    {
        $a = $this->proveedor(9801);
        $this->proveedor(9806, enlazado: false);
        $mixto = $this->producto([9801, 9806]);
        $soloSinEnlace = $this->producto([9806]);

        $this->aplicar();

        $this->assertSame(['principal' => $a, 'proveedores' => [$a]], $this->vinculo($mixto));
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($soloSinEnlace));
    }

    public function test_nunca_pisa_ni_toca_lo_que_no_corresponde(): void
    {
        $a = $this->proveedor(9801);
        $otro = $this->proveedor(9807);
        $conPrincipal = $this->producto([9801]);
        CatalogProduct::query()->whereKey($conPrincipal)->update(['provider_id' => $otro]);
        $conPivote = $this->producto([9801]);
        CatalogProduct::query()->findOrFail($conPivote)->providers()->attach($otro);
        $inactivo = $this->producto([9801], activo: false);
        $descat = $this->producto([9801], descatalogado: true);
        $sinCostoIcg = $this->producto([]);
        $elegible = $this->producto([9801]); // sin él no habría nada que aplicar y el dry-run no imprimiría huella

        $this->aplicar();

        $this->assertSame(['principal' => $a, 'proveedores' => [$a]], $this->vinculo($elegible));

        $this->assertSame(['principal' => $otro, 'proveedores' => []], $this->vinculo($conPrincipal));
        $this->assertSame(['principal' => null, 'proveedores' => [$otro]], $this->vinculo($conPivote));
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($inactivo));
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($descat));
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($sinCostoIcg));
    }

    public function test_choque_de_factory_reference_con_otro_producto_del_proveedor_lo_excluye(): void
    {
        $a = $this->proveedor(9801);
        $existente = $this->producto([]);
        CatalogProduct::query()->whereKey($existente)->update(['provider_id' => $a, 'factory_reference' => 'FR-9801']);
        $choca = $this->producto([9801], factoryReference: 'FR-9801');
        $libre = $this->producto([9801], factoryReference: 'FR-9801-B');

        $this->aplicar();

        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($choca));
        $this->assertSame($a, $this->vinculo($libre)['principal']);
    }

    public function test_dos_productos_de_la_misma_corrida_con_el_mismo_proveedor_y_referencia_no_rompen_el_apply(): void
    {
        $a = $this->proveedor(9801);
        $primero = $this->producto([9801], factoryReference: 'FR-DUP');
        $segundo = $this->producto([9801], factoryReference: 'FR-DUP');
        $otro = $this->producto([9801]);

        $this->aplicar();

        $this->assertSame($a, $this->vinculo($primero)['principal']);
        $this->assertNull($this->vinculo($segundo)['principal'], 'el segundo chocaría con el primero: se excluye');
        $this->assertSame($a, $this->vinculo($otro)['principal']);
    }

    public function test_es_idempotente(): void
    {
        $this->proveedor(9801);
        $this->producto([9801]);
        $this->aplicar();

        $antes = AuditLog::query()->where('action', AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES)->count();
        $this->correr()->expectsOutputToContain('Nada que aplicar')->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($antes, AuditLog::query()->where('action', AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES)->count());
    }

    public function test_apply_exige_operador_y_huella_correcta(): void
    {
        $this->proveedor(9801);
        $id = $this->producto([9801]);

        $this->correr(['--apply' => true])->assertExitCode(1);
        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(1);
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => str_repeat('0', 32)])->assertExitCode(1);
        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($id));
    }

    public function test_revert_deshace_solo_lo_que_sigue_valiendo_lo_aplicado(): void
    {
        $a = $this->proveedor(9801);
        $b = $this->proveedor(9802);
        $x = $this->producto([9801]);
        $y = $this->producto([9802]);
        $this->aplicar();
        // Alguien reasigna Y a mano después de la corrida.
        CatalogProduct::query()->whereKey($y)->update(['provider_id' => $a]);
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES)->firstOrFail();

        $this->correr(['--revert' => $evento->id, '--operador' => 'tests'])->assertExitCode(0);

        $this->assertSame(['principal' => null, 'proveedores' => []], $this->vinculo($x));
        $this->assertSame(['principal' => $a, 'proveedores' => [$b]], $this->vinculo($y), 'el producto reasignado después no se revierte');
    }
}
