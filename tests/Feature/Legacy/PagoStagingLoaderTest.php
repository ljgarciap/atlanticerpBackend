<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedPagos;
use App\Modules\Legacy\Data\PagoRow;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Services\PagoStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-761 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal. Sin Matcher, sin Loader-B — el pipeline de esta entidad termina en `legacy.pagos`. Cubre
 * también la llave natural de 6 columnas (ver docblock de la migración).
 */
final class PagoStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_load_stagea_pagos(): void
    {
        $loader = new PagoStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(['pagos' => 1], $counts);
        $this->assertDatabaseHas('atlantic_legacy.pagos', [
            'icg_origen' => 'V', 'icg_tipodocumento' => 'F', 'icg_serie' => 'A',
            'icg_numero' => 9001, 'icg_n' => 'B', 'icg_posicion' => 1,
        ]);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica(): void
    {
        $loader = new PagoStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Pago::query()->where('icg_numero', 9001)->count());
    }

    public function test_re_correr_actualiza_valores_cambiados(): void
    {
        $loader = new PagoStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedPagos([self::pagoRow(estado: 'C')]);
        $loader->load($updated);

        $pago = Pago::query()->where('icg_numero', 9001)->firstOrFail();
        $this->assertSame('C', $pago->estado);
    }

    /**
     * Llave natural de 6 columnas — dos filas con el mismo `icg_numero`/`icg_serie` pero distinta
     * `icg_posicion` (dos líneas de pago del mismo documento) NUNCA deben colisionar en un solo
     * registro. Este es exactamente el bug que el sketch abreviado de §4 (solo 4 columnas de
     * llave) hubiera introducido — ver docblock de la migración.
     */
    public function test_posiciones_distintas_del_mismo_documento_no_colisionan(): void
    {
        $extraction = new ExtractedPagos([
            self::pagoRow(posicion: 1, importe: 100.0),
            self::pagoRow(posicion: 2, importe: 200.0),
        ]);

        $loader = new PagoStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(['pagos' => 2], $counts);
        $this->assertSame(2, Pago::query()->where('icg_numero', 9001)->count());
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_posicion' => 1, 'importe' => '100.00']);
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_posicion' => 2, 'importe' => '200.00']);
    }

    /**
     * Regresión — mismo patrón que `ProveedorStagingLoaderTest`: el chunking (CHUNK_SIZE=500) no
     * debe perder ni duplicar filas en el borde entre chunks.
     */
    public function test_load_con_mas_filas_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $pagos = [];
        for ($i = 1; $i <= 600; $i++) {
            $pagos[] = self::pagoRow(numero: $i);
        }

        $loader = new PagoStagingLoader();
        $counts = $loader->load(new ExtractedPagos($pagos));

        $this->assertSame(['pagos' => 600], $counts);
        $this->assertSame(600, Pago::query()->count());
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 1]);
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 600]);
    }

    // ── loadPaginated() — hallazgo real 2026-08-27 (ver docblock de PagoExtractor) ─────────────

    public function test_load_paginated_stagea_filas_repartidas_en_varias_paginas_sin_perder_ni_duplicar(): void
    {
        $pagos = [];
        for ($i = 1; $i <= 250; $i++) {
            $pagos[] = self::pagoRow(numero: $i);
        }

        // Páginas de 100 sobre 250 filas — 3 llamadas al fetcher (100/100/50), simula lo que hace
        // el Command real contra PagoExtractor::extractPage() paginado.
        $pageSize = 100;
        $fetcher  = static function (int $offset, int $limit) use ($pagos): array {
            return array_values(array_slice($pagos, $offset, $limit));
        };

        $loader = new PagoStagingLoader();
        $counts = $loader->loadPaginated($fetcher, $pageSize);

        $this->assertSame(['pagos' => 250], $counts);
        $this->assertSame(250, Pago::query()->count());
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 1]);
        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 250]);
    }

    public function test_load_paginated_el_fetcher_se_llama_con_offsets_crecientes_hasta_pagina_vacia(): void
    {
        $pagos = [self::pagoRow(numero: 1), self::pagoRow(numero: 2)];

        $callsOffsets = [];
        $fetcher      = static function (int $offset, int $limit) use ($pagos, &$callsOffsets): array {
            $callsOffsets[] = $offset;

            return array_values(array_slice($pagos, $offset, $limit));
        };

        $loader = new PagoStagingLoader();
        $loader->loadPaginated($fetcher, pageSize: 1);

        // 2 filas con pageSize=1 → offsets 0 y 1 devuelven 1 fila cada uno (llenan la página, hay
        // que seguir pidiendo), offset 2 devuelve vacío y corta el loop.
        $this->assertSame([0, 1, 2], $callsOffsets);
    }

    public function test_load_paginated_re_correr_no_duplica(): void
    {
        $pagos   = [self::pagoRow()];
        $fetcher = static fn (int $offset, int $limit): array => array_values(array_slice($pagos, $offset, $limit));

        $loader = new PagoStagingLoader();
        $loader->loadPaginated($fetcher);
        $loader->loadPaginated($fetcher);

        $this->assertSame(1, Pago::query()->where('icg_numero', 9001)->count());
    }

    private static function pagoRow(
        int $numero = 9001,
        int $posicion = 1,
        ?float $importe = 1500.50,
        string $estado = 'P',
    ): PagoRow {
        return new PagoRow(
            icgOrigen: 'V',
            icgTipodocumento: 'F',
            icgSerie: 'A',
            icgNumero: $numero,
            icgN: 'B',
            icgPosicion: $posicion,
            fechaDocumento: null,
            fechaVencimiento: null,
            importe: $importe,
            icgCodformapago: 3,
            estado: $estado,
            fechaSaldado: null,
        );
    }

    private static function sampleExtraction(): ExtractedPagos
    {
        return new ExtractedPagos([self::pagoRow()]);
    }
}
