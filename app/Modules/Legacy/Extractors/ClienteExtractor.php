<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-755 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae CLIENTES+CONTACTOSCLIENTES de ICG
 * (SQL Server, conexión `legacy_icg`) para el batch de Clientes
 * (Illumination/migracion-icg-diseno.md §2/§4/§5).
 *
 * Sin filtro de "solo activos" — a diferencia de `ProductoExtractor` (ARTICULOS.DESCATALOGADO='F'),
 * el ticket pide capturar `bloqueado` como un atributo más de cada cliente, no como criterio de
 * exclusión (todo `CLIENTES` se extrae, bloqueado o no).
 *
 * GOTCHA NDBOOLEANF — CONFIRMADO CONTRA DATA REAL 2026-08-17 (antes de la corrida real de Tarea 6,
 * a raíz de la revisión de mapeo posterior al hallazgo de SCRUM-750/Productos). `CLIENTES.BLOQUEADO`
 * es `nvarchar(1)`, pero **NO sigue el mismo literal que `ARTICULOS.DESCATALOGADO`** — los valores
 * reales en dev.atlanticerp.ai vía el gateway VPN son `'T'`/`'F'` (7720 `'F'`, 1 `'T'`), nunca `'V'`. La
 * asunción original ("misma convención que ARTICULOS", `'V'` = true) era incorrecta — de haber
 * corrido la Tarea 6 sin este chequeo, `bloqueado` hubiera quedado SIEMPRE `false`, incluido el
 * único cliente real bloqueado en ICG, de forma silenciosa (sin error, sin excepción). Mismo patrón
 * de riesgo que el bug de `ProductoMapper::reference`/`resolveCost()` (SCRUM-750): nunca asumir
 * uniformidad de literal NDBOOLEANF entre tablas, ni siquiera entre tablas "core" del mismo ICG
 * Manager — confirmar contra `INFORMATION_SCHEMA`+muestra real antes de cada corrida real.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `ProductoExtractor`, a propósito, para que `mapClienteRow()`/`mapContactoRow()` sean testeables
 * con arrays de filas fake, sin ninguna conexión real — ver
 * tests/Unit/Legacy/ClienteExtractorTest.php): son `static`, puras, y `extract()` es el único
 * método que toca la red.
 */
final class ClienteExtractor implements ClienteExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): ExtractedClientes
    {
        $clienteRows  = DB::connection($this->connection)->select(self::clientesQuery());
        $contactoRows = DB::connection($this->connection)->select(self::contactosQuery());

        $clientes = array_values(array_map(
            static fn (object $row): ClienteRow => self::mapClienteRow((array) $row),
            $clienteRows,
        ));

        $contactos = array_values(array_map(
            static fn (object $row): ContactoClienteRow => self::mapContactoRow((array) $row),
            $contactoRows,
        ));

        return new ExtractedClientes($clientes, $contactos);
    }

    public static function clientesQuery(): string
    {
        return <<<'SQL'
            SELECT c.CODCLIENTE, c.NOMBRECLIENTE, c.NOMBRECOMERCIAL, c.CIF, c.NIF20, c.DIRECCION1,
                   c.POBLACION, c.PROVINCIA, c.PAIS, c.TELEFONO1, c.TELEFONO2, c.MOBIL, c.E_MAIL,
                   c.CODVENDEDOR, c.BLOQUEADO, c.FECHAMODIFICADO
            FROM CLIENTES c
            SQL;
    }

    public static function contactosQuery(): string
    {
        return <<<'SQL'
            SELECT cc.ID, cc.CODCLIENTE, cc.CARGO, cc.NOMBRE, cc.TELEFONO, cc.MOBIL, cc.E_MAIL
            FROM CONTACTOSCLIENTES cc
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `clientesQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapClienteRow(array $row): ClienteRow
    {
        return new ClienteRow(
            icgCodcliente: (int) $row['CODCLIENTE'],
            nombreComercial: self::nullableString($row['NOMBRECOMERCIAL'] ?? null),
            // NOMBRECLIENTE es obligatorio en ICG (razón social/nombre legal) — nunca nulo.
            razonSocial: (string) $row['NOMBRECLIENTE'],
            cif: self::nullableString($row['CIF'] ?? null),
            // SCRUM-1137: NIF20 (nvarchar(20)) trae el RUC completo; CIF (nvarchar(12)) lo trunca.
            nif20: self::nullableString($row['NIF20'] ?? null),
            direccion: self::nullableString($row['DIRECCION1'] ?? null),
            poblacion: self::nullableString($row['POBLACION'] ?? null),
            provincia: self::nullableString($row['PROVINCIA'] ?? null),
            pais: self::nullableString($row['PAIS'] ?? null),
            telefono1: self::nullableString($row['TELEFONO1'] ?? null),
            telefono2: self::nullableString($row['TELEFONO2'] ?? null),
            mobil: self::nullableString($row['MOBIL'] ?? null),
            email: self::nullableString($row['E_MAIL'] ?? null),
            icgCodvendedor: self::nullableInt($row['CODVENDEDOR'] ?? null),
            // CLIENTES.BLOQUEADO — NDBOOLEANF de texto, 'T' = true (confirmado contra ICG real,
            // ver GOTCHA en el docblock de la clase). Nunca 'V' pese a ser el mismo literal que
            // ARTICULOS.DESCATALOGADO usa para verdadero.
            bloqueado: ($row['BLOQUEADO'] ?? null) === 'T',
            fechaModificadoIcg: self::nullableDateTime($row['FECHAMODIFICADO'] ?? null),
        );
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `contactosQuery()`.
     *
     * @param array<string, mixed> $row
     */
    public static function mapContactoRow(array $row): ContactoClienteRow
    {
        return new ContactoClienteRow(
            icgId: (int) $row['ID'],
            icgCodcliente: (int) $row['CODCLIENTE'],
            cargo: self::nullableString($row['CARGO'] ?? null),
            // NOMBRE es obligatorio en ICG (nunca nulo).
            nombre: (string) $row['NOMBRE'],
            telefono: self::nullableString($row['TELEFONO'] ?? null),
            mobil: self::nullableString($row['MOBIL'] ?? null),
            email: self::nullableString($row['E_MAIL'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * `FECHAMODIFICADO` llega del driver `pdo_sqlsrv` como string ya formateado
     * (`Y-m-d H:i:s[.u]`). Defensivo: una fecha malformada nunca debe reventar todo el extracto —
     * se descarta esa columna puntual (`null`), el resto de la fila sigue mapeándose normalmente.
     */
    private static function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
