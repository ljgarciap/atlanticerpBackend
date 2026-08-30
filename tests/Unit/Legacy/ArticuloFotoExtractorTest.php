<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\ArticuloFotoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-751 — tests puros de la capa de mapeo de `ArticuloFotoExtractor` (sin DB, sin conexión de
 * red). `extract()` (el único método que toca la red) no se testea acá — se fakea vía
 * `ArticuloFotoExtractorContract` en los Feature tests del Job/comando, mismo patrón que
 * `ProductoExtractorTest` de SCRUM-750.
 */
final class ArticuloFotoExtractorTest extends TestCase
{
    public function test_map_row_devuelve_null_si_no_hay_fila(): void
    {
        $this->assertNull(ArticuloFotoExtractor::mapRow(null));
    }

    public function test_map_row_devuelve_null_si_foto_es_null(): void
    {
        $this->assertNull(ArticuloFotoExtractor::mapRow(['FOTO' => null]));
    }

    public function test_map_row_devuelve_null_si_foto_es_string_vacio(): void
    {
        $this->assertNull(ArticuloFotoExtractor::mapRow(['FOTO' => '']));
    }

    public function test_map_row_devuelve_los_bytes_crudos_tal_cual(): void
    {
        $bytes = "BM" . random_bytes(64);

        $result = ArticuloFotoExtractor::mapRow(['FOTO' => $bytes]);

        $this->assertSame($bytes, $result);
    }

    public function test_map_row_castea_a_string_si_no_llega_como_string(): void
    {
        // Algunos drivers pueden entregar el binario como un objeto stream-like o resource;
        // mapRow() siempre debe normalizar a string.
        $result = ArticuloFotoExtractor::mapRow(['FOTO' => 12345]);

        $this->assertSame('12345', $result);
    }

    public function test_query_selecciona_solo_foto_por_codarticulo_y_activos(): void
    {
        $sql = ArticuloFotoExtractor::query();

        $this->assertStringContainsString('SELECT FOTO', $sql);
        $this->assertStringContainsString('FROM ARTICULOS', $sql);
        $this->assertStringContainsString('CODARTICULO = ?', $sql);
        $this->assertStringContainsString("DESCATALOGADO = 'F'", $sql);
    }

    public function test_query_nunca_es_un_select_masivo(): void
    {
        // Riesgo real de escala (§15.1: ~19GB en 9707 filas) — la query siempre debe filtrar por
        // un único CODARTICULO, nunca traer todas las filas de una.
        $sql = ArticuloFotoExtractor::query();

        $this->assertStringNotContainsString('TOP ', strtoupper($sql));
    }
}
