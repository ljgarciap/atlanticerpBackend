<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;
use Tests\Support\TerminosDelClienteAnterior;

/**
 * Fase 4b (docs/specs/fase4b-dataset-demo-generico.md §3.3) — el dataset demo conserva su FORMA (mismos conteos que antes de la fase) y su contenido es
 * neutro: ninguna organización, persona de contacto con dominio real ni término del sector del cliente anterior en las tablas sembradas.
 *
 * Los seeders demo se saltan en `testing` («los tests crean sus propios fixtures»), así que aquí se ejercen cambiando el entorno a `local`, igual que
 * `DatabaseSeederTiersTest` lo cambia a `production`. Todo ocurre dentro de la transacción del test.
 */
final class DemoDatasetNeutralidadTest extends FeatureTestCase
{
    /** En el orden de `DatabaseSeeder`. */
    private const DEMO = [
        'CatalogProductSeeder', 'CatalogProductFamilySeeder', 'DemoProviderAssignmentSeeder', 'DemoReplacementRequestSeeder',
        'VentasDisenoDemoSeeder', 'ServiciosDemoSeeder', 'CrmDemoSeeder',
    ];

    /** @var array<string, array{0: string, 1: string}> tabla => [schema, columnas de texto a escanear separadas por coma; '*' = todas] */
    private const TABLAS = [
        'master_clients' => ['ventas_diseno', '*'], 'sub_clients' => ['ventas_diseno', '*'], 'sales_projects' => ['ventas_diseno', '*'],
        'catalog_products' => ['ventas_diseno', '*'], 'catalog_product_families' => ['ventas_diseno', '*'],
        'providers' => ['compras', '*'], 'tickets' => ['servicios', '*'],
        'projects' => ['crm', '*'], 'project_activities' => ['crm', '*'], 'project_reminders' => ['crm', '*'],
    ];

    private function sembrar(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        foreach (['catalog_product_families', 'catalog_products'] as $t) {
            DB::table("atlantic_ventas_diseno.{$t}")->whereRaw('1 = 1')->delete();
        }
        Tenant::forgetCurrent();

        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'local');
        try {
            foreach (self::DEMO as $seeder) {
                $this->artisan('db:seed', ['--class' => "Database\\Seeders\\{$seeder}", '--force' => true])->assertExitCode(0);
            }
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }

        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function filas(string $schema, string $tabla): int
    {
        return (int) DB::table("atlantic_{$schema}.{$tabla}")->count();
    }

    public function test_el_demo_conserva_la_forma_de_siempre(): void
    {
        $this->sembrar();

        $this->assertSame(49, $this->filas('ventas_diseno', 'catalog_products'), '46 productos + 3 de la reposición');
        $this->assertSame(10, $this->filas('ventas_diseno', 'catalog_product_families'));
        $this->assertSame(49, DB::table('atlantic_ventas_diseno.catalog_product_family_items')->count(), 'la red producto-familia se conserva (un producto puede estar en varias)');
        $this->assertSame(2, DB::table('atlantic_ventas_diseno.master_clients')->where('name', 'like', '[DEMO]%')->count());
        $this->assertSame(2, DB::table('atlantic_ventas_diseno.sub_clients')->where('business_name', 'like', '[DEMO]%')->count());
        $this->assertSame(3, DB::table('atlantic_ventas_diseno.sales_projects')->where('name', 'like', '[DEMO]%')->count());
        $this->assertSame(
            ['approved', 'design', 'lead', 'lost', 'proposal', 'quote'],
            DB::table('atlantic_ventas_diseno.pipeline_cards')->orderBy('stage')->pluck('stage')->unique()->values()->all(),
            'una tarjeta por etapa de sistema',
        );
        $this->assertSame(12, DB::table('atlantic_crm.projects')->where('nombre', 'like', '[DEMO]%')->count());
        $this->assertSame(14, DB::table('atlantic_servicios.tickets')->where('descripcion', 'like', '[DEMO]%')->count());
    }

    public function test_las_tablas_sembradas_no_tienen_nombres_ni_vocabulario_del_cliente_anterior(): void
    {
        $this->sembrar();

        $hallazgos = [];
        foreach (self::TABLAS as $tabla => [$schema]) {
            $columnas = DB::table('information_schema.columns')
                ->where('table_schema', "atlantic_{$schema}")->where('table_name', $tabla)
                ->whereIn('data_type', ['character varying', 'text', 'jsonb', 'json'])->pluck('column_name')->all();

            foreach (DB::table("atlantic_{$schema}.{$tabla}")->get() as $fila) {
                foreach ($columnas as $col) {
                    $valor = $fila->{$col} ?? null;
                    if (is_string($valor) && ($t = TerminosDelClienteAnterior::encuentra($valor)) !== null) {
                        $hallazgos[] = "{$schema}.{$tabla}.{$col}: «{$t}» en «".mb_substr($valor, 0, 60).'»';
                    }
                }
            }
        }

        $this->assertSame([], array_slice(array_values(array_unique($hallazgos)), 0, 15), 'el dato demo no debe llevar términos del cliente anterior');
    }
}
