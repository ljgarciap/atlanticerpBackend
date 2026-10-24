<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\ClienteExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-755 — tests puros de la capa de mapeo de `ClienteExtractor` (sin DB, sin conexión de
 * red). Cubre el gotcha NDBOOLEANF de `CLIENTES.BLOQUEADO` — 'T'/'F' confirmado contra ICG real
 * 2026-08-17, NO 'V'/'F' como en ARTICULOS.DESCATALOGADO (ver docblock de la clase) — y los casos
 * borde del ticket.
 */
final class ClienteExtractorTest extends TestCase
{
    // ── mapClienteRow() ─────────────────────────────────────────────────────────────────────

    public function test_map_cliente_row_mapea_campos_basicos(): void
    {
        $row = self::clienteRow();

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertSame(1001, $cliente->icgCodcliente);
        $this->assertSame('Cliente Comercial SA', $cliente->nombreComercial);
        $this->assertSame('Cliente Legal SA', $cliente->razonSocial);
        $this->assertSame('8-123-456', $cliente->cif);
        $this->assertSame(55, $cliente->icgCodvendedor);
    }

    public function test_map_cliente_row_mapea_nif20(): void
    {
        $this->assertSame('155783782-2-2026DV35', ClienteExtractor::mapClienteRow(self::clienteRow(['NIF20' => ' 155783782-2-2026DV35 ']))->nif20);
    }

    public function test_map_cliente_row_nif20_vacio_o_ausente_es_null(): void
    {
        $this->assertNull(ClienteExtractor::mapClienteRow(self::clienteRow(['NIF20' => '   ']))->nif20);
        $this->assertNull(ClienteExtractor::mapClienteRow(self::clienteRow(['NIF20' => null]))->nif20);

        $row = self::clienteRow();
        unset($row['NIF20']);
        $this->assertNull(ClienteExtractor::mapClienteRow($row)->nif20);
    }

    public function test_clientes_query_selecciona_nif20(): void
    {
        $this->assertStringContainsString('c.NIF20', ClienteExtractor::clientesQuery());
    }

    public function test_map_cliente_row_interpreta_bloqueado_t_como_true(): void
    {
        // 'T' confirmado contra ICG real 2026-08-17 (dev.atlanticerp.ai vía gateway VPN) — nunca 'V'.
        $row = self::clienteRow(['BLOQUEADO' => 'T']);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertTrue($cliente->bloqueado);
    }

    public function test_map_cliente_row_interpreta_bloqueado_f_como_false(): void
    {
        $row = self::clienteRow(['BLOQUEADO' => 'F']);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertFalse($cliente->bloqueado);
    }

    public function test_map_cliente_row_no_confunde_t_con_v_ni_con_0_1(): void
    {
        // Regresión directa del bug real encontrado 2026-08-17: 'V' YA NO debe interpretarse como
        // true (era la asunción incorrecta original, copiada de ARTICULOS.DESCATALOGADO). Tampoco
        // '1' si alguien tratara la columna como bit por error.
        $row = self::clienteRow(['BLOQUEADO' => 'V']);
        $this->assertFalse(ClienteExtractor::mapClienteRow($row)->bloqueado);

        $row = self::clienteRow(['BLOQUEADO' => '1']);
        $this->assertFalse(ClienteExtractor::mapClienteRow($row)->bloqueado);
    }

    public function test_map_cliente_row_campos_opcionales_nulos_si_string_vacio(): void
    {
        $row = self::clienteRow(['CIF' => '   ', 'NOMBRECOMERCIAL' => null, 'E_MAIL' => '']);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertNull($cliente->cif);
        $this->assertNull($cliente->nombreComercial);
        $this->assertNull($cliente->email);
    }

    public function test_map_cliente_row_codvendedor_nulo_si_vacio(): void
    {
        $row = self::clienteRow(['CODVENDEDOR' => null]);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertNull($cliente->icgCodvendedor);
    }

    public function test_map_cliente_row_parsea_fecha_modificado(): void
    {
        $row = self::clienteRow(['FECHAMODIFICADO' => '2026-01-15 10:30:00']);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertNotNull($cliente->fechaModificadoIcg);
        $this->assertSame('2026-01-15', $cliente->fechaModificadoIcg->format('Y-m-d'));
    }

    public function test_map_cliente_row_fecha_modificado_nula_si_vacia(): void
    {
        $row = self::clienteRow(['FECHAMODIFICADO' => null]);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertNull($cliente->fechaModificadoIcg);
    }

    public function test_map_cliente_row_fecha_modificado_nula_si_malformada(): void
    {
        // Defensivo: una fecha corrupta nunca debe reventar todo el extracto.
        $row = self::clienteRow(['FECHAMODIFICADO' => 'no-es-una-fecha']);

        $cliente = ClienteExtractor::mapClienteRow($row);

        $this->assertNull($cliente->fechaModificadoIcg);
    }

    public function test_map_cliente_row_es_determinista(): void
    {
        $row = self::clienteRow();

        $first  = ClienteExtractor::mapClienteRow($row);
        $second = ClienteExtractor::mapClienteRow($row);

        $this->assertEquals($first, $second);
    }

    // ── mapContactoRow() ────────────────────────────────────────────────────────────────────

    public function test_map_contacto_row_mapea_campos_basicos(): void
    {
        $row = self::contactoRow();

        $contacto = ClienteExtractor::mapContactoRow($row);

        $this->assertSame(501, $contacto->icgId);
        $this->assertSame(1001, $contacto->icgCodcliente);
        $this->assertSame('Gerente de Compras', $contacto->cargo);
        $this->assertSame('Juan Pérez', $contacto->nombre);
    }

    public function test_map_contacto_row_campos_opcionales_nulos_si_vacios(): void
    {
        $row = self::contactoRow(['CARGO' => null, 'TELEFONO' => '  ', 'E_MAIL' => '']);

        $contacto = ClienteExtractor::mapContactoRow($row);

        $this->assertNull($contacto->cargo);
        $this->assertNull($contacto->telefono);
        $this->assertNull($contacto->email);
    }

    public function test_map_contacto_row_es_determinista(): void
    {
        $row = self::contactoRow();

        $first  = ClienteExtractor::mapContactoRow($row);
        $second = ClienteExtractor::mapContactoRow($row);

        $this->assertEquals($first, $second);
    }

    // ── queries — smoke test de que apuntan a las tablas correctas ────────────────────────────

    public function test_clientes_query_selecciona_de_clientes(): void
    {
        $this->assertStringContainsString('FROM CLIENTES', ClienteExtractor::clientesQuery());
    }

    public function test_contactos_query_selecciona_de_contactosclientes(): void
    {
        $this->assertStringContainsString('FROM CONTACTOSCLIENTES', ClienteExtractor::contactosQuery());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function clienteRow(array $overrides = []): array
    {
        return array_merge([
            'CODCLIENTE'      => 1001,
            'NOMBRECLIENTE'   => 'Cliente Legal SA',
            'NOMBRECOMERCIAL' => 'Cliente Comercial SA',
            'CIF'             => '8-123-456',
            'NIF20'           => '8-123-456',
            'DIRECCION1'      => 'Calle 50',
            'POBLACION'       => 'Panamá',
            'PROVINCIA'       => 'Panamá',
            'PAIS'            => 'Panamá',
            'TELEFONO1'       => '2345678',
            'TELEFONO2'       => null,
            'MOBIL'           => '61234567',
            'E_MAIL'          => 'contacto@cliente.com',
            'CODVENDEDOR'     => 55,
            'BLOQUEADO'       => 'F',
            'FECHAMODIFICADO' => '2026-01-01 08:00:00',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function contactoRow(array $overrides = []): array
    {
        return array_merge([
            'ID'          => 501,
            'CODCLIENTE'  => 1001,
            'CARGO'       => 'Gerente de Compras',
            'NOMBRE'      => 'Juan Pérez',
            'TELEFONO'    => '2345678',
            'MOBIL'       => '61234567',
            'E_MAIL'      => 'juan.perez@cliente.com',
        ], $overrides);
    }
}
