<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\ProveedorExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-756 — tests puros de la capa de mapeo de `ProveedorExtractor` (sin DB, sin conexión de
 * red). Cubre los casos borde del ticket: campos nulos/vacíos, determinismo, y el smoke test de
 * que la query no filtra nada (a diferencia de `ProductoExtractor`, sin criterio de "solo
 * activos" — ver docblock de la clase).
 */
final class ProveedorExtractorTest extends TestCase
{
    // ── mapProveedorRow() ────────────────────────────────────────────────────────────────────

    public function test_map_proveedor_row_mapea_campos_completos(): void
    {
        $row = self::proveedorRow();

        $proveedor = ProveedorExtractor::mapProveedorRow($row);

        $this->assertSame(55, $proveedor->icgCodproveedor);
        $this->assertSame('Iluminación Panamá S.A.', $proveedor->nombre);
        $this->assertSame('Ilumipan', $proveedor->nombreComercial);
        $this->assertSame('1234567-8-901234', $proveedor->cif);
        $this->assertSame('Calle 50, Ciudad de Panamá', $proveedor->direccion);
        $this->assertSame('507-1234567', $proveedor->telefono1);
        $this->assertSame('ventas@ilumipan.com', $proveedor->email);
    }

    public function test_map_proveedor_row_nombre_comercial_nulo_si_no_viene(): void
    {
        $row = self::proveedorRow(['NOMCOMERCIAL' => null]);

        $proveedor = ProveedorExtractor::mapProveedorRow($row);

        $this->assertNull($proveedor->nombreComercial);
    }

    public function test_map_proveedor_row_string_vacio_o_solo_espacios_se_normaliza_a_null(): void
    {
        $row = self::proveedorRow(['CIF' => '   ', 'DIRECCION1' => '']);

        $proveedor = ProveedorExtractor::mapProveedorRow($row);

        $this->assertNull($proveedor->cif);
        $this->assertNull($proveedor->direccion);
    }

    public function test_map_proveedor_row_telefono_y_email_nulos_si_ausentes(): void
    {
        $row = self::proveedorRow(['TELEFONO1' => null, 'E_MAIL' => null]);

        $proveedor = ProveedorExtractor::mapProveedorRow($row);

        $this->assertNull($proveedor->telefono1);
        $this->assertNull($proveedor->email);
    }

    public function test_map_proveedor_row_nombre_obligatorio_nunca_nulo(): void
    {
        $row = self::proveedorRow(['NOMPROVEEDOR' => 'Solo Razón Social']);

        $proveedor = ProveedorExtractor::mapProveedorRow($row);

        $this->assertSame('Solo Razón Social', $proveedor->nombre);
    }

    public function test_map_proveedor_row_es_determinista(): void
    {
        $row = self::proveedorRow();

        $first  = ProveedorExtractor::mapProveedorRow($row);
        $second = ProveedorExtractor::mapProveedorRow($row);

        $this->assertEquals($first, $second);
    }

    // ── query — smoke test: sin filtro de "solo activos" (a diferencia de ProductoExtractor) ──

    public function test_proveedores_query_selecciona_de_la_tabla_correcta_sin_filtro(): void
    {
        $sql = ProveedorExtractor::proveedoresQuery();

        $this->assertStringContainsString('FROM PROVEEDORES', $sql);
        $this->assertStringNotContainsString('WHERE', $sql);
    }

    public function test_proveedores_query_incluye_las_columnas_documentadas(): void
    {
        $sql = ProveedorExtractor::proveedoresQuery();

        foreach (['CODPROVEEDOR', 'NOMPROVEEDOR', 'NOMCOMERCIAL', 'CIF', 'DIRECCION1', 'TELEFONO1', 'E_MAIL'] as $column) {
            $this->assertStringContainsString($column, $sql, "Falta la columna {$column} en la query");
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function proveedorRow(array $overrides = []): array
    {
        return array_merge([
            'CODPROVEEDOR' => 55,
            'NOMPROVEEDOR' => 'Iluminación Panamá S.A.',
            'NOMCOMERCIAL' => 'Ilumipan',
            'CIF'          => '1234567-8-901234',
            'DIRECCION1'   => 'Calle 50, Ciudad de Panamá',
            'TELEFONO1'    => '507-1234567',
            'E_MAIL'       => 'ventas@ilumipan.com',
        ], $overrides);
    }
}
