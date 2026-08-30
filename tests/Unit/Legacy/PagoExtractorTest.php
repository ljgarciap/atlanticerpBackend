<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\PagoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-761 — tests puros de la capa de mapeo de `PagoExtractor` (sin DB, sin conexión de red).
 */
final class PagoExtractorTest extends TestCase
{
    public function test_map_pago_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::pagoRow();

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertSame('V', $pago->icgOrigen);
        $this->assertSame('F', $pago->icgTipodocumento);
        $this->assertSame('A', $pago->icgSerie);
        $this->assertSame(9001, $pago->icgNumero);
        $this->assertSame('B', $pago->icgN);
        $this->assertSame(1, $pago->icgPosicion);
        $this->assertSame(1500.50, $pago->importe);
        $this->assertSame(3, $pago->icgCodformapago);
        $this->assertSame('P', $pago->estado);
    }

    public function test_map_pago_row_icg_n_es_string_no_int(): void
    {
        $row = self::pagoRow(['N' => 'B']);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertIsString($pago->icgN);
        $this->assertSame('B', $pago->icgN);
    }

    public function test_map_pago_row_fecha_documento_valida_se_parsea(): void
    {
        $row = self::pagoRow(['FECHADOCUMENTO' => '2026-01-15 10:30:00']);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNotNull($pago->fechaDocumento);
        $this->assertSame('2026-01-15', $pago->fechaDocumento->format('Y-m-d'));
    }

    public function test_map_pago_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::pagoRow(['FECHADOCUMENTO' => 'no-es-una-fecha']);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNull($pago->fechaDocumento);
        $this->assertSame(1500.50, $pago->importe);
    }

    public function test_map_pago_row_fecha_saldado_nula(): void
    {
        $row = self::pagoRow(['FECHASALDADO' => null]);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNull($pago->fechaSaldado);
    }

    public function test_map_pago_row_codformapago_nulo_no_revienta(): void
    {
        $row = self::pagoRow(['CODFORMAPAGO' => null]);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNull($pago->icgCodformapago);
    }

    public function test_map_pago_row_estado_string_vacio_se_normaliza_a_null(): void
    {
        $row = self::pagoRow(['ESTADO' => '']);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNull($pago->estado);
    }

    public function test_map_pago_row_importe_nulo(): void
    {
        $row = self::pagoRow(['IMPORTE' => null]);

        $pago = PagoExtractor::mapPagoRow($row);

        $this->assertNull($pago->importe);
    }

    public function test_pagos_page_query_apunta_a_tesoreria_paginada(): void
    {
        $query = PagoExtractor::pagosPageQuery();

        $this->assertStringContainsString('TESORERIA', $query);
        // OFFSET...FETCH exige ORDER BY en SQL Server (mismo hallazgo que CotizacionExtractor,
        // 2026-08-21) — sin esto la paginación puede saltear o repetir filas entre páginas.
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('FETCH NEXT', $query);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function pagoRow(array $overrides = []): array
    {
        return array_merge([
            'ORIGEN'           => 'V',
            'TIPODOCUMENTO'    => 'F',
            'SERIE'            => 'A',
            'NUMERO'           => 9001,
            'N'                => 'B',
            'POSICION'         => 1,
            'FECHADOCUMENTO'   => '2026-01-15 10:30:00',
            'FECHAVENCIMIENTO' => '2026-02-15 00:00:00',
            'IMPORTE'          => 1500.50,
            'CODFORMAPAGO'     => 3,
            'ESTADO'           => 'P',
            'FECHASALDADO'     => null,
        ], $overrides);
    }
}
