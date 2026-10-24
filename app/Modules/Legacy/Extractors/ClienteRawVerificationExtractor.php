<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ClienteRawVerificationExtractorContract;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SCRUM-1137 F1 — agregados del lado ICG para `legacy:verify-clientes-raw`. Solo SELECT de la llave natural
 * (un entero por fila: ~7.800 + 821, trivial en memoria) y `COUNT(*)`; nunca lee columnas de datos. Consultas
 * `static` y puras; solo los métodos de la interfaz tocan la conexión `legacy_icg`.
 */
final class ClienteRawVerificationExtractor implements ClienteRawVerificationExtractorContract
{
    /** entidad de staging => [tabla ICG, columna llave]. */
    private const ORIGEN = [
        'clientes_raw'          => ['CLIENTES', 'CODCLIENTE'],
        'contactos_cliente_raw' => ['CONTACTOSCLIENTES', 'ID'],
    ];

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function conteos(): array
    {
        $db  = DB::connection($this->connection);
        $out = [];
        foreach (array_keys(self::ORIGEN) as $entidad) {
            $rows = $db->select(self::countQuery($entidad));
            $row  = $rows === [] ? [] : array_change_key_case((array) $rows[0], CASE_UPPER);
            $out[$entidad] = (int) ($row['N'] ?? 0);
        }

        return $out;
    }

    public function llaves(string $entidad): array
    {
        return self::mapKeys(DB::connection($this->connection)->select(self::keysQuery($entidad)));
    }

    public static function countQuery(string $entidad): string
    {
        return 'SELECT COUNT(*) AS N FROM ' . self::origen($entidad)[0];
    }

    public static function keysQuery(string $entidad): string
    {
        [$tabla, $llave] = self::origen($entidad);

        // ID es nullable en ICG: un NULL no es una llave (el extractor de datos ya falla con él).
        return "SELECT {$llave} AS K FROM {$tabla} WHERE {$llave} IS NOT NULL";
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return list<int>
     */
    public static function mapKeys(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            if (isset($row['K']) && is_numeric($row['K'])) {
                $out[] = (int) $row['K'];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private static function origen(string $entidad): array
    {
        return self::ORIGEN[$entidad]
            ?? throw new InvalidArgumentException("Entidad de clientes raw desconocida '{$entidad}'.");
    }
}
