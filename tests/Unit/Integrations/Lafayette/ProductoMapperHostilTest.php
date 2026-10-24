<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Exceptions\FilaRechazadaException;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Services\ProductoMapper;
use Tests\TestCase;

/**
 * SCRUM-990 (P1) — una fila hostil o malformada de la fuente NO aborta la sincronización: se sanea o va a cuarentena (se cuenta por motivo).
 * Pura (sin DB), sobre el fixture de filas hostiles con la forma real de PDO.
 */
final class ProductoMapperHostilTest extends TestCase
{
    /** @return array<string, \App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto> id => fila aceptada */
    private function aceptadas(): array
    {
        $batch = $this->batch();
        $out = [];
        foreach ($batch->productos as $p) {
            $out[$p->lafayetteId] = $p;
        }

        return $out;
    }

    private function batch(): \App\Modules\Integrations\Lafayette\Data\MappedBatch
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../../Fixtures/Lafayette/vista_productos_lighting_hostil.php';
        $rows = array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw);

        return (new ProductoMapper())->buildBatch(new ExtractedProductos($rows));
    }

    public function test_las_cinco_filas_con_identificador_invalido_van_a_cuarentena_sin_abortar_el_resto(): void
    {
        $batch = $this->batch();

        $this->assertSame(5, $batch->rechazadas);
        $this->assertSame([
            FilaRechazadaException::ID_DEMASIADO_LARGO => 1,
            FilaRechazadaException::ID_VACIO           => 1,
            FilaRechazadaException::ID_INVALIDO        => 2,
            FilaRechazadaException::REFERENCIA_DEMASIADO_LARGA => 1,
        ], $batch->motivos);
        $this->assertCount(20, $batch->productos, '25 filas - 5 rechazadas = 20 aceptadas');
        $this->assertArrayHasKey('HST0000', $this->aceptadas(), 'la fila sana sigue entrando');
    }

    public function test_los_motivos_no_contienen_datos_de_la_fila(): void
    {
        $json = json_encode($this->batch()->motivos, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('XXXX', $json);
        $this->assertStringNotContainsString('HST', $json);
    }

    public function test_textos_largos_se_truncan_a_la_columna_o_al_tope_configurable(): void
    {
        $a = $this->aceptadas();

        $this->assertSame(255, mb_strlen((string) $a['HST0006']->marca));
        $this->assertSame(255, mb_strlen((string) $a['HST0006']->categoria));
        $this->assertSame(2000, mb_strlen((string) $a['HST0007']->descripcion), 'tope de config lafayette.descripcion_max');
    }

    public function test_caracteres_de_control_nul_y_utf8_invalido_se_eliminan_y_se_conservan_tab_y_el_resto(): void
    {
        $a = $this->aceptadas();

        $this->assertSame("connul y campana y\ttab", $a['HST0008']->descripcion);
        $this->assertSame('MARCA', $a['HST0008']->marca);
        $this->assertTrue(mb_check_encoding((string) $a['HST0009']->descripcion, 'UTF-8'), 'queda UTF-8 válido');
        $this->assertStringContainsString('utf8 inv', (string) $a['HST0009']->descripcion);
    }

    public function test_enteros_y_precios_fuera_de_rango(): void
    {
        $a = $this->aceptadas();

        $this->assertSame(2147483647, $a['HST0010']->disponible);
        $this->assertSame(0, $a['HST0011']->disponible);
        $this->assertNull($a['HST0012']->precio5, 'precio negativo => null');
        $this->assertNull($a['HST0013']->precio5, 'INF => null');
        $this->assertNull($a['HST0014']->precio5, 'sobre el tope plausible => null');
        $this->assertSame(1.0, $a['HST0000']->precio5);
        $this->assertNull($a['HST0012']->precio6, 'precio_6 negativo => null');
        $this->assertNull($a['HST0013']->precio6, 'precio_6 INF => null');
        $this->assertNull($a['HST0014']->precio6, 'precio_6 sobre el tope plausible => null');
        $this->assertSame(1.5, $a['HST0000']->precio6);
    }

    public function test_fechas_ilegibles_o_fuera_de_rango_quedan_null(): void
    {
        $a = $this->aceptadas();

        $this->assertNull($a['HST0015']->fuenteActualizadoAt);
        $this->assertNull($a['HST0016']->fuenteActualizadoAt, 'la fecha "cero" de MariaDB no es una fecha');
        $this->assertNotNull($a['HST0000']->fuenteActualizadoAt);
    }

    public function test_url_imagen_solo_http_o_https_con_host_y_largo_acotado(): void
    {
        $a = $this->aceptadas();

        $this->assertNull($a['HST0017']->urlImagen, 'javascript:');
        $this->assertNull($a['HST0018']->urlImagen, 'data:');
        $this->assertNull($a['HST0019']->urlImagen, 'ftp:');
        $this->assertSame('http://ok.example/a.png', $a['HST0020']->urlImagen);
        $this->assertSame('https://ok.example/b.png', $a['HST0021']->urlImagen);
        $this->assertNull($a['HST0022']->urlImagen, 'más de 2048 caracteres');
        $this->assertNull($a['HST0023']->urlImagen, 'sin host');
        $this->assertNull($a['HST0024']->urlImagen, 'con espacio');
    }

    public function test_map_one_lanza_la_excepcion_de_cuarentena_con_el_motivo(): void
    {
        $this->expectException(FilaRechazadaException::class);
        $this->expectExceptionMessage(FilaRechazadaException::ID_VACIO);

        ProductoMapper::mapOne(new LafayetteProductoRow('   ', 'R', 'D', null, null, null, 1, 1.0, 1.5, null));
    }
}
