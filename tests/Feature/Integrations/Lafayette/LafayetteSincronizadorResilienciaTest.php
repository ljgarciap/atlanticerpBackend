<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\Integrations\Lafayette\Services\LafayetteProductoLoader;
use App\Modules\Integrations\Lafayette\Services\LafayetteSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 (Senior MEDIO + P1 + P2) — resiliencia del sincronizador ante datos de un tercero: línea base de la guarda solo con corridas
 * `success`, umbral de la guarda como parámetro, cuarentena por fila (mapper y BD) y mensajes de error sin SQL ni bindings.
 */
final class LafayetteSincronizadorResilienciaTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return list<MappedLafayetteProducto> */
    private static function sinteticas(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = new MappedLafayetteProducto(sprintf('RES%06d', $i), "REF-{$i}", "Producto {$i}", 'M', 'C', null, 5, 1.0, 1.5, null);
        }

        return $out;
    }

    /** @return list<LafayetteProductoRow> */
    private static function filas(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = new LafayetteProductoRow(sprintf('RES%06d', $i), "REF-{$i}", "Producto {$i}", 'M', null, 'C', 5, 1.0, 1.5, '2026-09-25 16:58:46');
        }

        return $out;
    }

    private function corrida(string $status, int $extraidos): void
    {
        LafayetteSyncRun::query()->create([
            'extraidos' => $extraidos, 'creados' => 0, 'actualizados' => 0, 'errores' => 0, 'duracion_ms' => 1,
            'status' => $status, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);
    }

    private function loader(): LafayetteProductoLoader
    {
        return $this->app->make(LafayetteProductoLoader::class);
    }

    private function setRatio(string $json): void
    {
        DB::table('atlantic_auth.system_settings')->updateOrInsert(['key' => LafayetteSettings::GUARDA_MIN_RATIO], ['value' => DB::raw("'{$json}'::jsonb")]);
    }

    // ── Guarda: línea base solo `success` ────────────────────────────────────────────────────────────────────

    public function test_la_linea_base_de_la_guarda_ignora_las_corridas_partial(): void
    {
        $this->loader()->load(self::sinteticas(20));
        $this->corrida(LafayetteSyncRun::STATUS_SUCCESS, 20);
        $this->corrida(LafayetteSyncRun::STATUS_PARTIAL, 5); // una corrida recortada que activó la guarda

        // Con la línea base de la corrida `partial` (5), 5 filas ya no dispararían la guarda y ocultarían 15 productos.
        $r = $this->loader()->load(array_slice(self::sinteticas(20), 0, 5));

        $this->assertTrue($r['guarda_activada'], 'la línea base es la última corrida SUCCESS (20), no la partial (5)');
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_dos_corridas_recortadas_consecutivas_por_el_comando_no_ocultan_el_catalogo(): void
    {
        $fake = new FakeLafayetteProductoExtractor(new ExtractedProductos(self::filas(20)));
        $this->app->instance(ProductoExtractorContract::class, $fake);
        $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->tenant();

        foreach ([1, 2] as $vez) {
            $fake->setResult(new ExtractedProductos(self::filas(3)));
            $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
            $this->tenant();

            $run = LafayetteSyncRun::query()->orderByDesc('id')->firstOrFail();
            $this->assertSame(LafayetteSyncRun::STATUS_PARTIAL, $run->status, "corrida recortada #{$vez}");
            $this->assertSame(0, $run->fuera_de_fuente);
        }

        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count(), 'ni la 2a corrida recortada oculta el catálogo');
        $this->assertSame(20, LafayetteProducto::query()->count());
    }

    // ── P2: el umbral es un parámetro ────────────────────────────────────────────────────────────────────────

    public function test_el_umbral_de_la_guarda_vive_en_system_settings_con_0_9_por_defecto(): void
    {
        $this->assertSame(0.9, app(LafayetteSettings::class)->guardaMinRatio(), 'sembrado por la migración con el 0,9 aprobado por Luis');
        $this->assertNotNull(DB::table('atlantic_auth.system_settings')->where('key', 'lafayette_guarda_min_ratio')->first());
    }

    public function test_cambiar_el_umbral_sin_deploy_cambia_el_comportamiento_de_la_guarda(): void
    {
        $this->loader()->load(self::sinteticas(20));
        $this->corrida(LafayetteSyncRun::STATUS_SUCCESS, 20);

        $this->setRatio('0.5');
        // 15 de 20 = 75 %: con el umbral en 0,5 no hay guarda (y se marcan 5 como fuera de la fuente)...
        $r = $this->loader()->load(array_slice(self::sinteticas(20), 0, 15));
        $this->assertFalse($r['guarda_activada']);
        $this->assertSame(5, $r['fuera_de_fuente']);

        // ...con el umbral en 0,9 (el defecto) el mismo 75 % SÍ activa la guarda.
        $this->setRatio('0.9');
        $this->loader()->load(self::sinteticas(20));
        $r2 = $this->loader()->load(array_slice(self::sinteticas(20), 0, 15));
        $this->assertTrue($r2['guarda_activada']);
        $this->assertSame(0, $r2['fuera_de_fuente']);
    }

    public function test_el_umbral_se_acota_a_0_1_y_cae_al_valor_de_config_si_falta_la_fila(): void
    {
        $this->setRatio('5');
        $this->assertSame(1.0, app(LafayetteSettings::class)->guardaMinRatio());
        $this->setRatio('-3');
        $this->assertSame(0.0, app(LafayetteSettings::class)->guardaMinRatio());

        DB::table('atlantic_auth.system_settings')->where('key', LafayetteSettings::GUARDA_MIN_RATIO)->delete();
        config(['lafayette.guarda_extraccion_min_ratio' => 0.8]);
        $this->assertSame(0.8, app(LafayetteSettings::class)->guardaMinRatio());
    }

    // ── P1: cuarentena por fila ──────────────────────────────────────────────────────────────────────────────

    public function test_una_fila_que_la_bd_rechaza_va_a_cuarentena_y_el_resto_se_carga(): void
    {
        Log::spy();
        $lote = self::sinteticas(5);
        // Salta el mapper a propósito (como un caso que éste no previó): un id de 60 caracteres no cabe en varchar(50) => la BD lo rechaza.
        $lote[2] = new MappedLafayetteProducto(str_repeat('Z', 60), 'R', 'D', 'M', 'C', null, 1, 1.0, 1.5, null);

        $r = $this->loader()->load($lote);

        $this->assertSame(4, $r['insertados']);
        $this->assertSame(1, $r['rechazadas']);
        $this->assertSame(0, $r['sin_cambios']);
        $this->assertSame(4, LafayetteProducto::query()->count());
        $this->assertFalse(LafayetteProducto::query()->where('lafayette_id', 'like', 'ZZZ%')->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx): bool => $msg === 'lafayette.fila_rechazada_por_bd' && ! array_key_exists('descripcion', $ctx))->once();
    }

    public function test_el_comando_con_filas_hostiles_no_aborta_cuenta_los_rechazos_y_queda_partial(): void
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../../Fixtures/Lafayette/vista_productos_lighting_hostil.php';
        $rows = array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw);
        $this->app->instance(ProductoExtractorContract::class, new FakeLafayetteProductoExtractor(new ExtractedProductos($rows)));

        $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'])
            ->expectsOutputToContain('Motivos de rechazo')
            ->assertExitCode(0);
        $this->tenant();

        $run = LafayetteSyncRun::query()->firstOrFail();
        $this->assertSame(25, $run->extraidos);
        $this->assertSame(20, $run->creados);
        $this->assertSame(5, $run->errores, 'las filas en cuarentena se cuentan en el contador de la corrida');
        $this->assertSame(LafayetteSyncRun::STATUS_PARTIAL, $run->status, '5/25 = 20 % supera filas_rechazadas_max_ratio (1 %)');
        $this->assertSame(20, LafayetteProducto::query()->count());

        // Lo saneado quedó saneado en la BD, no solo en el mapper.
        $this->assertNull(LafayetteProducto::query()->where('lafayette_id', 'HST0017')->value('url_imagen'));
        $this->assertSame(255, mb_strlen((string) LafayetteProducto::query()->where('lafayette_id', 'HST0006')->value('marca')));
        $this->assertSame(2000, mb_strlen((string) LafayetteProducto::query()->where('lafayette_id', 'HST0007')->value('descripcion')));
        $this->assertSame(2147483647, (int) LafayetteProducto::query()->where('lafayette_id', 'HST0010')->value('disponible'));
    }

    public function test_pocas_filas_rechazadas_dejan_la_corrida_en_success(): void
    {
        $rows = self::filas(300);
        $rows[10] = new LafayetteProductoRow('', 'R', 'D', null, null, null, 1, 1.0, 1.5, null); // 1 de 300 = 0,33 % < 1 %
        $this->app->instance(ProductoExtractorContract::class, new FakeLafayetteProductoExtractor(new ExtractedProductos($rows)));

        $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->tenant();

        $run = LafayetteSyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->errores);
        $this->assertSame(LafayetteSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(299, LafayetteProducto::query()->count());
    }

    // ── P3: mensajes sin SQL ni bindings ─────────────────────────────────────────────────────────────────────

    public function test_un_error_de_la_extraccion_no_imprime_sql_ni_bindings(): void
    {
        $e = new QueryException('mysql', 'SELECT secreto_de_columna FROM vista WHERE x = ?', ['binding-secreto-123'], new \PDOException('SQLSTATE[HY000] [2002] Connection timed out'));
        $this->app->instance(ProductoExtractorContract::class, new FakeLafayetteProductoExtractor(null, $e));

        $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'])
            ->expectsOutputToContain('Error al extraer de Lafayette')
            ->doesntExpectOutputToContain('binding-secreto-123')
            ->doesntExpectOutputToContain('secreto_de_columna')
            ->assertExitCode(1);
    }
}
