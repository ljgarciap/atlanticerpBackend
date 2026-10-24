<?php

declare(strict_types=1);

namespace Tests\Feature\Lafayette;

use App\Console\Commands\LafayetteMapearProveedorCommand;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compras\Models\Provider;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1151 — `lafayette:mapear-proveedor`: asocia BK INTERPRISES S.A. a los productos de AtlanticERP que coinciden con Lafayette
 * (spec docs/specs/scrum-1151-mapeo-lafayette-bk-interprises.md). La BD de pruebas puede traer otros productos/filas: cada test
 * usa referencias propias (prefijo `T1151`) y solo afirma sobre sus propios datos.
 */
final class LafayetteMapearProveedorCommandTest extends FeatureTestCase
{
    private int $n = 0;

    private int $bkId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        $this->bkId = (int) Provider::query()->firstOrCreate(['name' => LafayetteMapearProveedorCommand::PROVEEDOR], ['currency' => 'USD'])->id;
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el comando olvida el tenant al terminar
    }

    private function producto(string $reference, array $attrs = []): int
    {
        $this->tenant();

        return (int) CatalogProduct::create([
            'reference' => $reference, 'name' => "Producto {$reference}", 'price_full' => 10.0, 'cost' => 4.0, 'is_active' => true, ...$attrs,
        ])->id;
    }

    private function lafayette(string $referencia, array $attrs = []): string
    {
        $this->tenant();
        $id = 'T1151-L'.++$this->n;
        LafayetteProducto::create(['lafayette_id' => $id, 'referencia' => $referencia, 'descripcion' => 'x', 'disponible' => 1, ...$attrs]);

        return $id;
    }

    private function proveedor(string $name): int
    {
        $this->tenant();

        return (int) Provider::create(['name' => $name, 'currency' => 'USD'])->id;
    }

    /** @param array<string, mixed> $opts @return array{0: int, 1: string} */
    private function cmd(array $opts = []): array
    {
        $code = Artisan::call('lafayette:mapear-proveedor', $opts);
        $this->tenant();

        return [$code, Artisan::output()];
    }

    private function huella(string $salida): string
    {
        preg_match('/--esperado=([a-f0-9]{32})/', $salida, $m);

        return $m[1] ?? '';
    }

    /** @return array<string, array<string, string>> filas del CSV por lafayette_id (solo las de estos tests) */
    private function csv(string $salida): array
    {
        preg_match('/CSV del resultado: (.+)/', $salida, $m);
        $ruta = trim($m[1] ?? '');
        $this->assertFileExists($ruta);
        $rows = [];
        $h = fopen($ruta, 'rb');
        $cab = fgetcsv($h, 0, ',', '"', '\\');
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $row = array_combine($cab, $r);
            if (str_starts_with($row['lafayette_id'], 'T1151')) {
                $rows[$row['lafayette_id']] = $row;
            }
        }
        fclose($h);

        return $rows;
    }

    private function aplicar(): array
    {
        [, $dry] = $this->cmd();

        return $this->cmd(['--apply' => true, '--operador' => 'test', '--esperado' => $this->huella($dry)]);
    }

    /** @return array{principal: ?int, pivote: list<int>} */
    private function proveedoresDe(int $productoId): array
    {
        $this->tenant();

        return [
            'principal' => CatalogProduct::query()->findOrFail($productoId)->provider_id,
            'pivote'    => DB::table(CatalogProduct::providersPivotTable())->where('catalog_product_id', $productoId)->pluck('provider_id')->map(fn ($v): int => (int) $v)->sort()->values()->all(),
        ];
    }

    // Senior Review — la referencia viene de una fuente externa: una celda que empiece con = + - @ no debe abrirse como fórmula en Excel.
    public function test_el_csv_neutraliza_referencias_que_excel_tomaria_por_formula(): void
    {
        $peligrosa = $this->lafayette('=HYPERLINK("http://x")');
        $normal = $this->lafayette('T1151-CSV-NORMAL');

        [, $out] = $this->cmd();

        $csv = $this->csv($out);
        $this->assertSame('\'=HYPERLINK("http://x")', $csv[$peligrosa]['referencia']);
        $this->assertSame('T1151-CSV-NORMAL', $csv[$normal]['referencia'], 'Una referencia normal no se altera.');
    }

    public function test_el_dry_run_no_escribe_nada_y_entrega_el_resultado_en_csv(): void
    {
        $p = $this->producto('T1151-A-001');
        $l = $this->lafayette(' t1151-a-001 ');   // mayúsculas y espacios distintos: coincidencia exacta según la regla
        $sin = $this->lafayette('T1151-NO-EXISTE');

        [$code, $out] = $this->cmd();

        $this->assertSame(0, $code);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($p), 'El dry-run no escribe.');
        $csv = $this->csv($out);
        $this->assertSame('asociado', $csv[$l]['estado']);
        $this->assertSame((string) $p, $csv[$l]['catalog_product_id']);
        $this->assertSame('sin_coincidencia', $csv[$sin]['estado']);
    }

    public function test_apply_exige_operador_y_huella(): void
    {
        $this->producto('T1151-B-001');
        $this->lafayette('T1151-B-001');

        $this->assertSame(1, $this->cmd(['--apply' => true])[0]);
        $this->assertSame(1, $this->cmd(['--apply' => true, '--operador' => 'test'])[0]);
        $this->assertSame(1, $this->cmd(['--apply' => true, '--operador' => 'test', '--esperado' => 'huella-vieja'])[0], 'Una huella que no coincide se rechaza.');
    }

    public function test_asocia_como_principal_si_no_tenia_proveedor_y_como_adicional_si_ya_tenia_otro(): void
    {
        $sinProv = $this->producto('T1151-C-001');
        $otroProv = $this->proveedor('Proveedor Previo 1151');
        $conProv = $this->producto('T1151-C-002', ['provider_id' => $otroProv]);
        $this->lafayette('T1151-C-001');
        $this->lafayette('T1151-C-002');

        [$code, $out] = $this->aplicar();

        $this->assertSame(0, $code);
        $this->assertSame(['principal' => $this->bkId, 'pivote' => [$this->bkId]], $this->proveedoresDe($sinProv));
        // El principal previo NO se reemplaza: BK solo se agrega a la tabla de varios proveedores.
        $this->assertSame($otroProv, $this->proveedoresDe($conProv)['principal']);
        $this->assertContains($this->bkId, $this->proveedoresDe($conProv)['pivote']);
        // «Disponible para BK al crear órdenes»: forProvider() lo devuelve en ambos casos.
        $ids = CatalogProduct::query()->forProvider($this->bkId)->whereIn('id', [$sinProv, $conProv])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$sinProv, $conProv], $ids);
    }

    public function test_no_modifica_ningun_dato_del_producto_ni_crea_productos(): void
    {
        $p = $this->producto('T1151-D-001', ['factory_reference' => 'FAB-1151-D', 'description' => 'Desc original', 'category' => 'Interior']);
        $this->lafayette('T1151-D-001', ['descripcion' => 'OTRA descripción de Lafayette', 'marca' => 'OtraMarca']);
        $this->tenant();
        $antes = CatalogProduct::query()->findOrFail($p)->only(['reference', 'name', 'description', 'category', 'factory_reference', 'price_full', 'cost', 'photo_url', 'is_active']);
        $total = CatalogProduct::query()->count();
        $lafayetteAntes = LafayetteProducto::query()->count();

        $this->aplicar();

        $this->tenant();
        $this->assertSame($antes, CatalogProduct::query()->findOrFail($p)->only(array_keys($antes)));
        $this->assertSame($total, CatalogProduct::query()->count(), 'No se crean productos.');
        $this->assertSame($lafayetteAntes, LafayetteProducto::query()->count(), 'El espejo de Lafayette es de solo lectura.');
    }

    public function test_los_productos_sin_coincidencia_no_reciben_el_proveedor(): void
    {
        $sinMatch = $this->producto('T1151-E-LOCAL');
        $this->lafayette('T1151-E-SOLO-LAFAYETTE');

        $this->aplicar();

        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($sinMatch));
    }

    public function test_la_coincidencia_solo_normalizada_queda_pendiente_y_no_se_asocia(): void
    {
        $p = $this->producto('T1151-F-ML-10097-BWH');
        $l = $this->lafayette('T1151F ML10097BWH');    // iguales solo quitando guiones y espacios

        [, $out] = $this->cmd();   // sin asociaciones no hay huella que aplicar: el resultado sale del dry-run

        $csv = $this->csv($out);
        $this->assertSame('pendiente_revision', $csv[$l]['estado']);
        $this->assertStringContainsString('normalizar', $csv[$l]['motivo']);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($p));
    }

    public function test_referencias_parecidas_pero_distintas_no_se_confunden(): void
    {
        $a = $this->producto('T1151-ML-10097AWH');
        $b = $this->producto('T1151-ML-10097BWH-1');
        $l = $this->lafayette('T1151-ML-10097BWH-1');

        $this->aplicar();

        $this->assertSame($this->bkId, $this->proveedoresDe($b)['principal']);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($a), 'ML-10097AWH no es ML-10097BWH-1.');
        $this->assertNotNull($l);
    }

    public function test_varios_productos_de_atlanticerp_con_la_misma_referencia_es_ambiguo(): void
    {
        $p1 = $this->producto('T1151-G-001');
        $p2 = $this->producto('t1151-g-001');           // `reference` es única, pero distingue mayúsculas
        $l = $this->lafayette('T1151-G-001');

        [, $out] = $this->cmd();   // sin asociaciones no hay huella que aplicar: el resultado sale del dry-run

        $this->assertSame('pendiente_revision', $this->csv($out)[$l]['estado']);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($p1));
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($p2));
    }

    public function test_varias_filas_de_lafayette_con_la_misma_referencia_quedan_pendientes(): void
    {
        $p = $this->producto('T1151-H-001');
        $l1 = $this->lafayette('T1151-H-001');
        $l2 = $this->lafayette('t1151-h-001');

        [, $out] = $this->cmd();   // sin asociaciones no hay huella que aplicar: el resultado sale del dry-run

        $csv = $this->csv($out);
        $this->assertSame('pendiente_revision', $csv[$l1]['estado']);
        $this->assertSame('pendiente_revision', $csv[$l2]['estado']);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($p));
    }

    public function test_incluye_productos_inactivos_y_excluye_los_que_salieron_de_la_fuente(): void
    {
        $inactivo = $this->producto('T1151-I-001', ['is_active' => false]);
        $this->lafayette('T1151-I-001');
        $fuera = $this->producto('T1151-I-002');
        $this->lafayette('T1151-I-002', ['fuera_de_fuente_at' => now()]);

        $this->aplicar();

        $this->assertSame($this->bkId, $this->proveedoresDe($inactivo)['principal'], 'Decisión de Luis: activos e inactivos.');
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($fuera), 'Lo que ya no está en la fuente no se asocia.');
    }

    public function test_choque_de_referencia_de_fabrica_con_otro_producto_ya_bajo_bk_queda_pendiente(): void
    {
        $this->producto('T1151-J-001', ['factory_reference' => 'FAB-1151-J', 'provider_id' => $this->bkId]);
        $nuevo = $this->producto('T1151-J-002', ['factory_reference' => 'FAB-1151-J']);
        $l = $this->lafayette('T1151-J-002');

        [, $out] = $this->cmd();   // sin asociaciones no hay huella que aplicar: el resultado sale del dry-run

        $this->assertSame('pendiente_revision', $this->csv($out)[$l]['estado']);
        $this->assertStringContainsString('factory_reference', $this->csv($out)[$l]['motivo']);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($nuevo));
    }

    public function test_es_idempotente_y_no_duplica_filas(): void
    {
        $p = $this->producto('T1151-K-001');
        $l = $this->lafayette('T1151-K-001');
        $this->aplicar();

        [$code, $out] = $this->cmd();     // segundo dry-run

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ya estaba asociado', $this->csv($out)[$l]['motivo']);
        $this->assertSame([$this->bkId], $this->proveedoresDe($p)['pivote']);
        $this->aplicar();                 // aplicar de nuevo no duplica
        $this->assertSame([$this->bkId], $this->proveedoresDe($p)['pivote']);
    }

    public function test_deja_un_evento_de_auditoria_y_revert_deshace_solo_lo_que_sigue_valiendo(): void
    {
        $sinProv = $this->producto('T1151-L-001');
        $otroProv = $this->proveedor('Proveedor Previo 1151-L');
        $adicional = $this->producto('T1151-L-002', ['provider_id' => $otroProv]);
        $reasignado = $this->producto('T1151-L-003');
        $this->lafayette('T1151-L-001');
        $this->lafayette('T1151-L-002');
        $this->lafayette('T1151-L-003');
        $this->aplicar();

        $this->tenant();
        $evento = AuditLog::query()->where('action', AuditService::LAFAYETTE_MAPEAR_PROVEEDOR)->latest('id')->firstOrFail();
        $this->assertSame('test', $evento->metadata['operador']);

        // Un producto reasignado a mano después NO se toca al revertir.
        CatalogProduct::query()->whereKey($reasignado)->update(['provider_id' => $otroProv]);

        [$code] = $this->cmd(['--revert' => $evento->id, '--operador' => 'test']);

        $this->assertSame(0, $code);
        $this->assertSame(['principal' => null, 'pivote' => []], $this->proveedoresDe($sinProv));
        $this->assertSame($otroProv, $this->proveedoresDe($adicional)['principal'], 'El principal previo nunca se tocó.');
        $this->assertNotContains($this->bkId, $this->proveedoresDe($adicional)['pivote']);
        $this->assertSame($otroProv, $this->proveedoresDe($reasignado)['principal'], 'Lo reasignado después se deja intacto.');
    }

    public function test_falla_si_el_proveedor_bk_no_existe_o_esta_duplicado(): void
    {
        $this->tenant();
        Provider::create(['name' => LafayetteMapearProveedorCommand::PROVEEDOR, 'currency' => 'USD']);   // segundo BK

        [$code, $out] = $this->cmd();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('exactamente UN proveedor', $out);
    }
}
